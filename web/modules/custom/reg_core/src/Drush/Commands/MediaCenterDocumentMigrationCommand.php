<?php

namespace Drupal\reg_core\Drush\Commands;

use Drupal\reg_core\Migration\MediaCenterDocumentImporter;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Runs controlled legacy REG document migration. */
#[AsCommand(
  name: 'reg:migrate-media-documents',
  description: 'Discover or import approved legacy REG Media Center documents.',
  aliases: ['reg-migrate-media-documents'],
)]
final class MediaCenterDocumentMigrationCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly MediaCenterDocumentImporter $importer,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('type', NULL, InputOption::VALUE_REQUIRED, 'Required: press-releases, publications, announcements, newsletters, or company-laws.')
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Discover and report without writing entities or files.')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Maximum records to process.', '0')
      ->addOption('update-existing', NULL, InputOption::VALUE_NONE, 'Refresh an existing source-managed publication.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $type = trim((string) $input->getOption('type'));
    $types = ['press-releases', 'publications', 'announcements', 'newsletters', 'company-laws'];
    if (!in_array($type, $types, TRUE)) {
      $output->writeln('<error>Unsupported --type. Use: ' . implode(', ', $types) . '.</error>');
      return Command::INVALID;
    }
    $report = $this->importer->run([
      'type' => $type,
      'dry_run' => (bool) $input->getOption('dry-run'),
      'limit' => max(0, (int) $input->getOption('limit')),
      'update_existing' => (bool) $input->getOption('update-existing'),
    ]);
    $output->writeln(sprintf('Type: %s', $type));
    $output->writeln(sprintf('Source Pages Scanned: %d', $report['source_pages_scanned']));
    $output->writeln(sprintf('Discovered: %d', $report['discovered']));
    $output->writeln(sprintf('Would Create: %d', $report['would_create']));
    $output->writeln(sprintf('Existing: %d', $report['existing']));
    $output->writeln(sprintf('Needs Review: %d', $report['needs_review']));
    $output->writeln(sprintf('Missing Date: %d', $report['quality']['needs_date']));
    $output->writeln(sprintf('Needs Language Review: %d', $report['quality']['needs_language']));
    $output->writeln(sprintf('Missing File: %d', $report['quality']['needs_file']));
    $output->writeln(sprintf('Failed: %d', $report['failed']));
    foreach ($report['sample'] as $sample) {
      $output->writeln(sprintf(
        '- [%s] %s | %s | %s (%s)%s%s | %s',
        $sample['language'],
        $sample['title'],
        $sample['date'] ?: 'date unavailable',
        $sample['category'],
        $sample['publication_type'],
        $sample['issue_number'] !== '' ? ' | issue ' . $sample['issue_number'] : '',
        $sample['archived_outage'] ? ' | historical outage' : '',
        $sample['source_url'],
      ));
    }
    if ($report['source_failures'] || $report['failures']) {
      $output->writeln(json_encode([
        'source_failures' => $report['source_failures'],
        'record_failures' => $report['failures'],
      ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
    return $report['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
  }

}
