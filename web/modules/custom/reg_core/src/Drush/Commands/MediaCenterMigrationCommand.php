<?php

namespace Drupal\reg_core\Drush\Commands;

use Drupal\reg_core\Migration\MediaCenterLegacyImporter;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Runs the controlled legacy Media Center migration. */
#[AsCommand(
  name: 'reg:migrate-media-center',
  description: 'Discover or import official legacy REG Media Center content.',
  aliases: ['reg-migrate-media-center'],
)]
final class MediaCenterMigrationCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly MediaCenterLegacyImporter $importer,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('type', NULL, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Content type(s): news, sports, press-releases, announcements, publications, newsletters, company-laws.')
      ->addOption('language', NULL, InputOption::VALUE_REQUIRED, 'Limit to en or rw.')
      ->addOption('dry-run', NULL, InputOption::VALUE_NONE, 'Discover and report without writing entities or files.')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Maximum records after filtering.', '0')
      ->addOption('update-existing', NULL, InputOption::VALUE_NONE, 'Refresh source-managed records; manual records are only enriched.');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $types = (array) $input->getOption('type');
    $language = trim((string) $input->getOption('language'));
    if ($language !== '' && !in_array($language, ['en', 'rw'], TRUE)) {
      $output->writeln('<error>--language must be en or rw.</error>');
      return Command::INVALID;
    }
    $allowed = ['news', 'sports', 'press-releases', 'announcements', 'publications', 'newsletters', 'company-laws'];
    if (array_diff($types, $allowed)) {
      $output->writeln('<error>Unsupported --type value.</error>');
      return Command::INVALID;
    }
    $report = $this->importer->run([
      'types' => $types,
      'language' => $language,
      'dry_run' => (bool) $input->getOption('dry-run'),
      'limit' => max(0, (int) $input->getOption('limit')),
      'update_existing' => (bool) $input->getOption('update-existing'),
    ]);
    $output->writeln(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $report['failed'] > 0 ? Command::FAILURE : Command::SUCCESS;
  }

}
