<?php

declare(strict_types=1);

namespace Drupal\book_cover_import\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
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

    // A node must not be matched by more than one archive image in this ZIP.
    $conflicting_files = $this->otherMatchingFiles($source_uri, $valid_records);
    if ($conflicting_files) {
      return $this->result('conflict', "$name: Record ID(s) " . implode(', ', array_map(static fn ($record) => $record->id(), $valid_records)) . ' also match ' . implode(', ', $conflicting_files) . '. No cover was assigned.');
    }

    // A node must not be assigned by more than one ZIP in the active session.
    $prior_assignments = $this->priorAssignments($valid_records);
    if ($prior_assignments) {
      return $this->result('conflict', "$name: Record ID(s) " . implode(', ', array_keys($prior_assignments)) . ' were already assigned by earlier ZIP file(s) ' . implode(', ', array_unique(array_values($prior_assignments))) . '. No cover was assigned.');
    }

    $record_ids = implode(', ', array_map(static fn ($record) => $record->id(), $valid_records));
    if ($dry_run) {
      return $this->result('assigned', "$name: would create one Book Cover Media item and assign it to Record ID(s) $record_ids.");
    }

    $destination = $this->destinationUri($name);
    $this->fileSystem->prepareDirectory(dirname($destination), FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    // FileRepository::copy() accepts an existing File entity in Drupal 11,
    // whereas this importer starts with an extracted stream-wrapper URI.
    $copied_uri = $this->fileSystem->copy($source_uri, $destination, FileExists::Rename);
    if ($copied_uri === FALSE) {
      return $this->result('error', "$name: Drupal could not copy the extracted image to its permanent cover location.");
    }

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

    return $this->result('assigned', "$name: created Media ID {$media->id()} and assigned it to Record ID(s) $record_ids.");
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
   *   Record IDs keyed by the filename that assigned each record.
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
   * Finds a second archive file that would assign another image to these nodes.
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

  private function result(string $status, string $message): array {
    $this->logger->notice($message);
    return ['status' => $status, 'message' => $message];
  }

}
