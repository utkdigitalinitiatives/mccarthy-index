<?php

declare(strict_types=1);

namespace Drupal\book_cover_import\Form;

use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\book_cover_import\Service\BookCoverImporter;
use Drupal\file\Entity\File;

/**
 * Uploads and processes a ZIP of Record cover images.
 */
final class BookCoverImportForm extends FormBase {

  private const EXTENSIONS = ['webp', 'jpg', 'jpeg', 'png'];

  /**
   * Resolves services at use time so AJAX form rebuilds do not retain stale
   * service properties from a serialized form object.
   */
  private function importer(): BookCoverImporter {
    return \Drupal::service('book_cover_import.importer');
  }

  private function fileSystem(): FileSystemInterface {
    return \Drupal::service('file_system');
  }

  public function getFormId(): string {
    return 'book_cover_import_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $job_id = $this->activeJobId();
    $files = $this->listCoverFiles($job_id);
    $session = $this->importer()->getAssignmentSession();
    $form['intro'] = [
      '#markup' => '<p>Upload one ZIP archive at a time, review the extracted images, then run a dry run or import. Each upload is staged in its own directory under the site’s current default writable scheme: <code>public://covers</code> locally and <code>azblob://covers</code> in production. Keep one import session active while processing all archive parts.</p><p>Allowed image types: <code>webp</code>, <code>jpg</code>, <code>jpeg</code>, <code>png</code>. Names must use <code>{isbn10|isbn13|lcc|lccn|oclc}_{value}.{extension}</code>. Images can be at the ZIP root or inside one enclosing folder.</p>',
    ];

    $form['session'] = [
      '#type' => 'details',
      '#title' => 'Import session',
      '#open' => TRUE,
    ];
    $form['session']['summary'] = [
      '#markup' => '<p><strong>Records assigned in the active multi-ZIP session:</strong> ' . count($session) . '</p><p>Assignments from earlier ZIP parts are retained in this session so a Record cannot be assigned a second imported cover by a later archive.</p>',
    ];
    $form['session']['reset'] = [
      '#type' => 'submit',
      '#value' => 'Start a new import session',
      '#submit' => ['::submitResetSession'],
      '#description' => 'Clears only the session conflict-tracking list. It does not delete Media, files, or existing Record cover references.',
    ];

    $form['archive'] = [
      '#type' => 'details',
      '#title' => '1. Upload ZIP archive',
      '#open' => TRUE,
    ];
    $form['archive']['zip_file'] = [
      '#type' => 'managed_file',
      '#title' => 'Cover image ZIP archive',
      '#upload_location' => 'temporary://book-cover-import-uploads',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'zip'],
      ],
      '#description' => 'The archive is extracted only after you select “Upload and extract archive”. Uploading another archive starts a new isolated staging job.',
    ];
    $form['archive']['upload'] = [
      '#type' => 'submit',
      '#value' => 'Upload and extract archive',
      '#submit' => ['::submitArchive'],
    ];

    $form['review'] = [
      '#type' => 'details',
      '#title' => '2. Review extracted files',
      '#open' => TRUE,
    ];
    $form['review']['summary'] = [
      '#markup' => '<p><strong>Extracted files ready:</strong> ' . count($files) . '</p>',
    ];
    if ($files) {
      $form['review']['files'] = [
        '#theme' => 'item_list',
        '#items' => array_map(static fn (array $file): string => $file['name'], array_slice($files, 0, 50)),
        '#empty' => 'No extracted image files.',
      ];
      if (count($files) > 50) {
        $form['review']['more'] = ['#markup' => '<p>Only the first 50 names are shown.</p>'];
      }
    }

    $form['process'] = [
      '#type' => 'details',
      '#title' => '3. Match and import',
      '#open' => TRUE,
    ];
    $form['process']['dry_run'] = [
      '#type' => 'submit',
      '#value' => 'Dry run (no changes)',
      '#disabled' => !$files,
      '#submit' => ['::submitProcess'],
      '#name' => 'dry_run',
    ];
    $form['process']['import'] = [
      '#type' => 'submit',
      '#value' => 'Process and assign covers',
      '#button_type' => 'primary',
      '#disabled' => !$files,
      '#submit' => ['::submitProcess'],
      '#name' => 'import',
    ];
    $form['process']['warning'] = [
      '#markup' => '<p><strong>Import behavior:</strong> A successful match replaces the Record’s current cover reference. Existing Media and files are retained. A single Media item is shared by all same-title Records matching a file. Any Record assigned by an earlier ZIP in the active session is skipped as a conflict. Staged source images are removed after a completed import; dry-run images are retained so they can be imported.</p>',
    ];

    return $form;
  }

  /**
   * Required by FormInterface; button-specific handlers perform the work.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function submitResetSession(array &$form, FormStateInterface $form_state): void {
    $this->importer()->resetAssignmentSession();
    $this->messenger()->addStatus('A new multi-ZIP import session has started. Existing Media, files, and Record cover references were not changed.');
    $form_state->setRebuild();
  }

  public function submitArchive(array &$form, FormStateInterface $form_state): void {
    $fids = array_filter($form_state->getValue('zip_file', []));
    $fid = reset($fids);
    if (!$fids || !$file = File::load($fid)) {
      $this->messenger()->addError('Choose a ZIP archive first.');
      return;
    }

    $job_id = bin2hex(random_bytes(16));
    $destination = $this->sourceDirectory($job_id);
    if (!$this->fileSystem()->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->messenger()->addError('Drupal could not create the isolated cover staging directory.');
      return;
    }

    $zip_path = $this->fileSystem()->realpath($file->getFileUri());
    $zip = new \ZipArchive();
    if ($zip_path === FALSE || $zip->open($zip_path) !== TRUE) {
      $this->messenger()->addError('Drupal could not open that ZIP archive.');
      $this->removeJobDirectory($job_id);
      return;
    }

    $extracted = 0;
    $ignored = 0;
    $rejected = [];
    $accepted = [];
    $duplicate_names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
      $entry = $zip->statIndex($i);
      $entry_name = $entry['name'] ?? '';

      if ($entry_name === '' || str_ends_with($entry_name, '/')) {
        continue;
      }
      if (str_contains($entry_name, '\\')) {
        $rejected[] = $entry_name;
        continue;
      }

      $parts = explode('/', $entry_name);
      $name = $parts[count($parts) - 1];
      if (in_array('__MACOSX', $parts, TRUE) || str_starts_with($name, '._') || $name === '.DS_Store') {
        $ignored++;
        continue;
      }
      if (count($parts) > 2 || in_array('', $parts, TRUE)) {
        $rejected[] = $entry_name;
        continue;
      }

      $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!in_array($extension, self::EXTENSIONS, TRUE) || !preg_match('/^(isbn10|isbn13|lcc|lccn|oclc)_[^_]+\.(webp|jpe?g|png)$/i', $name)) {
        $rejected[] = $entry_name;
        continue;
      }
      if (isset($duplicate_names[$name])) {
        $rejected[] = $entry_name . ' (duplicate filename)';
        continue;
      }
      if (isset($accepted[$name])) {
        $original_entry = $accepted[$name];
        $this->fileSystem()->delete($destination . '/' . $name);
        unset($accepted[$name]);
        $duplicate_names[$name] = TRUE;
        $extracted--;
        $rejected[] = $entry_name . ' (duplicate of ' . $original_entry . ')';
        continue;
      }

      $stream = $zip->getStream($entry_name);
      if ($stream === FALSE) {
        $rejected[] = $entry_name . ' (could not read ZIP entry)';
        continue;
      }
      $target = $destination . '/' . $name;
      $output = @fopen($target, 'wb');
      if ($output === FALSE) {
        fclose($stream);
        $rejected[] = $entry_name . ' (could not write staging file)';
        continue;
      }
      stream_copy_to_stream($stream, $output);
      fclose($stream);
      fclose($output);

      $image = \Drupal::service('image.factory')->get($target);
      if (!$image->isValid()) {
        $this->fileSystem()->delete($target);
        $rejected[] = $entry_name . ' (not a valid image)';
        continue;
      }

      $accepted[$name] = $entry_name;
      $extracted++;
    }
    $zip->close();

    $this->setActiveJobId($job_id);
    $this->messenger()->addStatus("Extracted $extracted valid cover image(s) into an isolated staging job." . ($ignored ? " Ignored $ignored macOS metadata entry/entries." : ''));
    if ($rejected) {
      $this->messenger()->addWarning('Rejected names: ' . implode(', ', array_slice($rejected, 0, 20)) . (count($rejected) > 20 ? ' …' : ''));
    }
    $form_state->setRebuild();
  }

  public function submitProcess(array &$form, FormStateInterface $form_state): void {
    $job_id = $this->activeJobId();
    $files = $this->listCoverFiles($job_id);
    if (!$job_id || !$files) {
      $this->messenger()->addError('Upload and extract at least one valid cover image first.');
      return;
    }

    $dry_run = $form_state->getTriggeringElement()['#name'] === 'dry_run';
    $batch = new BatchBuilder();
    $batch->setTitle($dry_run ? 'Dry-running cover import' : 'Importing Record covers')
      ->setInitMessage('Starting cover processing.')
      ->setProgressMessage('Processed @current of @total cover images.')
      ->setErrorMessage('The cover import encountered an unexpected error.');
    foreach ($files as $file) {
      $batch->addOperation([static::class, 'processFile'], [$file['uri'], $dry_run, $job_id]);
    }
    $batch->setFinishCallback([static::class, 'finishProcess']);
    batch_set($batch->toArray());
  }

  /**
   * Batch operation for one extracted image.
   */
  public static function processFile(string $uri, bool $dry_run, string $job_id, array &$context): void {
    $result = \Drupal::service('book_cover_import.importer')->process($uri, $dry_run);
    $context['results'][$result['status']] = ($context['results'][$result['status']] ?? 0) + 1;
    $context['results']['messages'][] = $result['message'];
    $context['results']['job_id'] = $job_id;
    $context['results']['dry_run'] = $dry_run;
    $context['message'] = $result['message'];
  }

  /**
   * Batch completion callback.
   *
   * Drupal passes the elapsed time as a formatted string.
   */
  public static function finishProcess(bool $success, array $results, array $operations, string $elapsed): void {
    if (!$success) {
      \Drupal::messenger()->addError('The batch did not complete. Check Recent log messages for details.');
      return;
    }
    $counts = [];
    foreach (['assigned', 'unmatched', 'conflict', 'error'] as $status) {
      $counts[] = ucfirst($status) . ': ' . ($results[$status] ?? 0);
    }
    \Drupal::messenger()->addStatus('Cover processing complete. ' . implode('; ', $counts) . '.');
    $job_id = $results['job_id'] ?? NULL;
    $dry_run = $results['dry_run'] ?? TRUE;
    if (!$dry_run && is_string($job_id) && preg_match('/^[a-f0-9]{32}$/', $job_id)) {
      static::removeJobDirectoryStatic($job_id);
      \Drupal::service('tempstore.private')->get('book_cover_import')->delete('active_job');
    }
  }

  /**
   * @return array<int, array{uri: string, name: string}>
   */
  private function listCoverFiles(?string $job_id): array {
    if (!$job_id || !preg_match('/^[a-f0-9]{32}$/', $job_id)) {
      return [];
    }
    $directory = $this->sourceDirectory($job_id);
    if (!is_dir($directory)) {
      return [];
    }
    $files = $this->fileSystem()->scanDirectory($directory, '/\.(webp|jpe?g|png)$/i', ['recurse' => FALSE]);
    $result = [];
    foreach ($files as $file) {
      $result[] = [
        'uri' => $file->uri,
        'name' => $file->filename,
      ];
    }
    usort($result, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    return $result;
  }

  private function activeJobId(): ?string {
    $job_id = \Drupal::service('tempstore.private')->get('book_cover_import')->get('active_job');
    return is_string($job_id) && preg_match('/^[a-f0-9]{32}$/', $job_id) ? $job_id : NULL;
  }

  private function setActiveJobId(string $job_id): void {
    \Drupal::service('tempstore.private')->get('book_cover_import')->set('active_job', $job_id);
  }

  private function sourceDirectory(string $job_id): string {
    $scheme = \Drupal::config('system.file')->get('default_scheme') ?: 'public';
    return $scheme . '://covers/' . $job_id;
  }

  private function removeJobDirectory(string $job_id): void {
    static::removeJobDirectoryStatic($job_id);
  }

  private static function removeJobDirectoryStatic(string $job_id): void {
    if (!preg_match('/^[a-f0-9]{32}$/', $job_id)) {
      return;
    }
    $scheme = \Drupal::config('system.file')->get('default_scheme') ?: 'public';
    $directory = $scheme . '://covers/' . $job_id;
    if (is_dir($directory)) {
      \Drupal::service('file_system')->deleteRecursive($directory);
    }
  }

}
