<?php

declare(strict_types=1);

namespace Drupal\book_cover_import\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\State\StateInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Psr\Log\LoggerInterface;

/**
 * Matches cover filenames to Record nodes and creates Book Cover Media items.
 */
final class BookCoverImporter {

  private const PREFIX_FIELDS = [
    'isbn10' => 'field_isbn10',
    'isbn13' => 'field_isbn13',
    'lcc' => 'field_lcc',
    'lccn' => 'field_lccn',
    'oclc' => 'field_oclc',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly LockBackendInterface $lock,
    private readonly Connection $database,
  ) {}

  /**
   * Processes a single uploaded cover image.
   *
   * @return array{status: string, message: string}
   */
  public function process(string $source_uri, bool $dry_run): array {
    $name = basename($source_uri);
    if (!preg_match('/^(isbn10|isbn13|lcc|lccn|oclc)_([^_]+)\.(webp|jpe?g|png)$/i', $name, $matches)) {
      return $this->result('error', "$name: invalid filename.");
    }
    if (!$this->isValidImage($source_uri)) {
      return $this->result('error', "$name: the staged file is not a valid image.");
    }

    $prefix = strtolower($matches[1]);
    $value = $matches[2];
    $field = self::PREFIX_FIELDS[$prefix];
    $node_storage = $this->entityTypeManager->getStorage('node');
    $nids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'record')
      ->condition($field, $value)
      ->sort('nid', 'ASC')
      ->execute();
    if (!$nids) {
      return $this->result('unmatched', "$name: no Record has $field = “$value”.");
    }

    /** @var \Drupal\node\NodeInterface[] $records */
    $records = array_values($node_storage->loadMultiple($nids));
    $first_title = $records[0]->label();
    $valid_records = [];
    $different_title_ids = [];
    foreach ($records as $record) {
      if ($record->label() === $first_title) {
        $valid_records[] = $record;
      }
      else {
        $different_title_ids[] = $record->id();
      }
    }
    if ($different_title_ids) {
      return $this->result('conflict', "$name: matched Record IDs " . implode(', ', array_map(static fn ($record) => $record->id(), $records)) . "; first title is “$first_title”, but Record ID(s) " . implode(', ', $different_title_ids) . ' have different title(s). No cover was assigned.');
    }

    $conflicting_files = $this->otherMatchingFiles($source_uri, $valid_records);
    if ($conflicting_files) {
      return $this->result('conflict', "$name: Record ID(s) " . implode(', ', array_map(static fn ($record) => $record->id(), $valid_records)) . ' also match ' . implode(', ', $conflicting_files) . '. No cover was assigned.');
    }

    $record_ids = implode(', ', array_map(static fn ($record) => $record->id(), $valid_records));
    if ($dry_run) {
      if ($prior_assignments = $this->priorAssignments($valid_records)) {
        return $this->result('conflict', "$name: Record ID(s) " . implode(', ', array_keys($prior_assignments)) . ' were already assigned by earlier ZIP file(s) ' . implode(', ', array_unique(array_values($prior_assignments))) . '. No cover was assigned.');
      }
      return $this->result('assigned', "$name: would create one Book Cover Media item and assign it to Record ID(s) $record_ids.");
    }

    // The lock also serializes read-modify-write access to the State session.
    $lock_name = 'book_cover_import.records.' . hash('sha256', implode(':', array_map(static fn ($record): string => (string) $record->id(), $valid_records)));
    if (!$this->lock->acquire($lock_name, 60.0)) {
      return $this->result('conflict', "$name: another import is currently assigning the same Record ID(s) $record_ids. Retry this archive after that import finishes.");
    }

    $copied_uri = FALSE;
    try {
      // Recheck after acquiring the lock: a parallel batch may have completed.
      if ($prior_assignments = $this->priorAssignments($valid_records)) {
        return $this->result('conflict', "$name: Record ID(s) " . implode(', ', array_keys($prior_assignments)) . ' were already assigned by earlier ZIP file(s) ' . implode(', ', array_unique(array_values($prior_assignments))) . '. No cover was assigned.');
      }
      if (!$this->isValidImage($source_uri)) {
        return $this->result('error', "$name: the staged file is no longer a valid image.");
      }

      $destination = $this->destinationUri($name);
      $directory = dirname($destination);
      if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        return $this->result('error', "$name: Drupal could not prepare the permanent cover directory.");
      }
      $copied_uri = $this->fileSystem->copy($source_uri, $destination, FileExists::Rename);
      if ($copied_uri === FALSE) {
        return $this->result('error', "$name: Drupal could not copy the extracted image to its permanent cover location.");
      }

      $transaction = $this->database->startTransaction();
      try {
        $file = File::create(['uri' => $copied_uri]);
        $file->setPermanent();
        $file->save();

        $media = Media::create([
          'bundle' => 'book_cover',
          'name' => $first_title . ' Cover',
          'field_media_image' => [
            'target_id' => $file->id(),
            'alt' => $first_title . ' Cover',
            'title' => '',
          ],
        ]);
        $media->save();

        foreach ($valid_records as $record) {
          $record->set('field_cover', ['target_id' => $media->id()]);
          $record->save();
        }
        $this->rememberAssignments($valid_records, $name);
      }
      catch (\Throwable $exception) {
        // Let Drupal roll back the File, Media, Record, and State database rows.
        unset($transaction);
        if ($copied_uri !== FALSE) {
          $this->fileSystem->delete($copied_uri);
        }
        throw $exception;
      }

      return $this->result('assigned', "$name: created Media ID {$media->id()} and assigned it to Record ID(s) $record_ids.");
    }
    catch (\Throwable $exception) {
      if ($copied_uri !== FALSE) {
        $this->fileSystem->delete($copied_uri);
      }
      $this->logger->error('{name}: import failed: {message}', ['name' => $name, 'message' => $exception->getMessage()]);
      return ['status' => 'error', 'message' => "$name: import failed; no Record assignments were committed."];
    }
    finally {
      $this->lock->release($lock_name);
    }
  }

  /**
   * Returns the active multi-ZIP session's assignment map.
   *
   * @return array<string, string>
   *   Record ID keyed map of prior archive filename.
   */
  public function getAssignmentSession(): array {
    return $this->state->get('book_cover_import.assignment_session', []);
  }

  /**
   * Starts a new multi-ZIP import session without altering existing content.
   */
  public function resetAssignmentSession(): void {
    $this->state->delete('book_cover_import.assignment_session');
  }

  /**
   * @param \Drupal\node\NodeInterface[] $records
   *
   * @return array<string, string>
   */
  private function priorAssignments(array $records): array {
    $session = $this->getAssignmentSession();
    $prior = [];
    foreach ($records as $record) {
      $id = (string) $record->id();
      if (isset($session[$id])) {
        $prior[$id] = $session[$id];
      }
    }
    return $prior;
  }

  /**
   * @param \Drupal\node\NodeInterface[] $records
   */
  private function rememberAssignments(array $records, string $filename): void {
    $session = $this->getAssignmentSession();
    foreach ($records as $record) {
      $session[(string) $record->id()] = $filename;
    }
    $this->state->set('book_cover_import.assignment_session', $session);
  }

  /**
   * Finds another staged file that would assign another image to these nodes.
   *
   * @param \Drupal\node\NodeInterface[] $records
   *
   * @return string[]
   */
  private function otherMatchingFiles(string $current_uri, array $records): array {
    $directory = dirname($current_uri);
    $files = $this->fileSystem->scanDirectory($directory, '/\.(webp|jpe?g|png)$/i', ['recurse' => FALSE]);
    $record_values = [];
    foreach ($records as $record) {
      foreach (self::PREFIX_FIELDS as $prefix => $field) {
        if (!$record->get($field)->isEmpty()) {
          $record_values[$prefix][] = (string) $record->get($field)->value;
        }
      }
    }

    $conflicts = [];
    foreach ($files as $file) {
      if ($file->uri === $current_uri || !preg_match('/^(isbn10|isbn13|lcc|lccn|oclc)_([^_]+)\.(webp|jpe?g|png)$/i', $file->filename, $matches)) {
        continue;
      }
      $prefix = strtolower($matches[1]);
      if (in_array($matches[2], $record_values[$prefix] ?? [], TRUE)) {
        $conflicts[] = $file->filename;
      }
    }
    return $conflicts;
  }

  private function destinationUri(string $filename): string {
    $scheme = $this->configFactory->get('system.file')->get('default_scheme') ?: 'public';
    return $scheme . '://record-covers/' . $filename;
  }

  private function isValidImage(string $uri): bool {
    try {
      return \Drupal::service('image.factory')->get($uri)->isValid();
    }
    catch (\Throwable) {
      return FALSE;
    }
  }

  private function result(string $status, string $message): array {
    $this->logger->notice($message);
    return ['status' => $status, 'message' => $message];
  }

}
