<?php

namespace Drupal\reg_core\Drush\Commands;

use Drupal\Core\File\FileSystemInterface;
use Drupal\reg_core\Migration\MediaCenterLegacyImporter;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Runs the controlled legacy REG News migration. */
#[AsCommand(
  name: 'reg:migrate-media-news',
  description: 'Discover or import official legacy REG News & Events content.',
  aliases: ['reg-migrate-media-news'],
)]
final class MediaCenterMigrationCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly MediaCenterLegacyImporter $importer,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('language', NULL, InputOption::VALUE_REQUIRED, 'Limit to en or rw.')
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Discover without writing Drupal entities or imported media; a requested diagnostic report may be written.')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Maximum records after filtering.', '0')
      ->addOption('classification-report', NULL, InputOption::VALUE_NONE, 'Write a CSV explaining the current classification decisions.')
      ->addOption('update-existing', NULL, InputOption::VALUE_NONE, 'Refresh migration-managed metadata and missing dates; preserve editorial title and body changes.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $language = trim((string) $input->getOption('language'));
    if ($language !== '' && !in_array($language, ['en', 'rw'], TRUE)) {
      $output->writeln('<error>--language must be en or rw.</error>');
      return Command::INVALID;
    }
    $report = $this->importer->run([
      'language' => $language,
      'dry_run' => (bool) $input->getOption('dry-run'),
      'limit' => max(0, (int) $input->getOption('limit')),
      'update_existing' => (bool) $input->getOption('update-existing'),
      'classification_report' => (bool) $input->getOption('classification-report'),
    ]);
    if ($input->getOption('classification-report')) {
      try {
        $path = $this->writeClassificationReport($report['classification_records']);
        $output->writeln(sprintf('Classification Report: %s', $path));
        $path = $this->writeDateReport($report['date_records']);
        $output->writeln(sprintf('Date Report: %s', $path));
        if ($input->getOption('dry-run')) {
          $path = $this->writeDiscoveryReport($report['discovery_diagnostics']);
          $output->writeln(sprintf('Discovery Report: %s', $path));
        }
      }
      catch (\Throwable $exception) {
        $output->writeln(sprintf('<error>Unable to write classification report: %s</error>', $exception->getMessage()));
        return Command::FAILURE;
      }
    }
    $output->writeln(sprintf('Discovered: %d', $report['discovered']));
    $output->writeln(sprintf('Would Create: %d', $report['would_create']));
    $output->writeln(sprintf('Imported Published: %d', $report['imported_published']));
    $output->writeln(sprintf('Imported Needs Date Review: %d', $report['imported_needs_date_review']));
    $output->writeln(sprintf('Existing: %d', $report['existing']));
    $output->writeln(sprintf('Updated: %d', $report['updated']));
    $output->writeln(sprintf('Skipped Fetch Failure: %d', $report['skipped_fetch_failure']));
    $output->writeln(sprintf('Corporate English: %d', $report['breakdown']['corporate_en']['discovered']));
    $output->writeln(sprintf('Corporate Kinyarwanda: %d', $report['breakdown']['corporate_rw']['discovered']));
    $output->writeln(sprintf('Sports English: %d', $report['breakdown']['sports_en']['discovered']));
    $output->writeln(sprintf('Sports Kinyarwanda: %d', $report['breakdown']['sports_rw']['discovered']));
    $output->writeln(sprintf('Needs Review: %d', $report['needs_review']));
    $output->writeln(sprintf('Translation Counterparts: %d', $report['translation_pairs']));
    $output->writeln(sprintf('Failed: %d', $report['failed']));
    $output->writeln(sprintf('Source Pages Scanned: %d', $report['source_pages_scanned']));
    $output->writeln('Year Coverage:');
    foreach ($report['year_coverage'] as $year => $count) {
      $output->writeln(sprintf('%s: %d', $year === 'unknown' ? 'Unknown date' : $year, $count));
    }
    if ($report['source_failures']) {
      $output->writeln('URLs Still Needing Retry:');
      foreach ($report['source_failures'] as $failure) {
        $output->writeln(sprintf('- %s (%s)', $failure['url'], $failure['reason']));
      }
    }
    if ($report['date_review_nodes']) {
      $output->writeln('Nodes Needing Date Review:');
      foreach ($report['date_review_nodes'] as $record) {
        $output->writeln(sprintf('- %d | %s | %s', $record['node_id'], $record['title'], $record['source_url']));
      }
    }
    return $report['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
  }

  /** Writes the diagnostic CSV without modifying Drupal content. */
  private function writeClassificationReport(array $records): string {
    $directory = 'private://migration-reports';
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new \RuntimeException(sprintf('Could not prepare %s.', $directory));
    }

    $path = $directory . '/reg-news-classification.csv';
    $handle = fopen($path, 'wb');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Could not open %s.', $path));
    }

    try {
      fputcsv($handle, [
        'title',
        'source_url',
        'publication_date',
        'language',
        'language_reason',
        'section',
        'section_reason',
        'record_state',
        'needs_review',
        'translation_counterpart',
      ], ',', '"', '\\');
      foreach ($records as $record) {
        fputcsv($handle, [
          $record['title'],
          $record['source_url'],
          $record['publication_date'],
          $record['language'],
          $record['language_reason'],
          $record['section'],
          $record['section_reason'],
          $record['record_state'],
          $record['needs_review'] ? 'yes' : 'no',
          $record['translation_counterpart'],
        ], ',', '"', '\\');
      }
    }
    finally {
      fclose($handle);
    }

    return $path;
  }

  /** Writes publication-date evidence without modifying Drupal content. */
  private function writeDateReport(array $records): string {
    $directory = 'private://migration-reports';
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new \RuntimeException(sprintf('Could not prepare %s.', $directory));
    }

    $path = $directory . '/reg-news-dates.csv';
    $handle = fopen($path, 'wb');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Could not open %s.', $path));
    }

    try {
      $columns = ['title', 'source_url', 'raw_date', 'parsed_date', 'date_source', 'confidence', 'needs_review', 'reason'];
      fputcsv($handle, $columns, ',', '"', chr(92));
      foreach ($records as $record) {
        fputcsv($handle, array_map(static fn(string $column): string => (string) ($record[$column] ?? ''), $columns), ',', '"', chr(92));
      }
    }
    finally {
      fclose($handle);
    }

    return $path;
  }
  /** Writes dry-run discovery decisions without enabling production logging. */
  private function writeDiscoveryReport(array $records): string {
    $directory = 'private://migration-reports';
    if (!$this->fileSystem->prepareDirectory(
      $directory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    )) {
      throw new \RuntimeException(sprintf('Could not prepare %s.', $directory));
    }

    $path = $directory . '/reg-news-discovery.csv';
    $handle = fopen($path, 'wb');
    if ($handle === FALSE) {
      throw new \RuntimeException(sprintf('Could not open %s.', $path));
    }

    try {
      fputcsv($handle, ['url', 'discovery_source', 'year', 'decision', 'reason'], ',', '"', chr(92));
      foreach ($records as $record) {
        fputcsv($handle, [
          $record['url'],
          $record['discovery_source'],
          $record['year'],
          $record['decision'],
          $record['reason'],
        ], ',', '"', chr(92));
      }
    }
    finally {
      fclose($handle);
    }

    return $path;
  }

}
