<?php

namespace Drupal\reg_core\Drush\Commands;

use Drupal\reg_core\Dms\DmsClientInterface;
use Drush\Commands\AutowireTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tests server-side connectivity to the configured DMS adapter.
 */
#[AsCommand(
  name: 'reg:dms-test',
  description: 'Test the configured DMS adapter without displaying credentials.',
  aliases: ['reg-dms-test'],
)]
final class DmsConnectivityCommand extends Command {

  use AutowireTrait;

  public function __construct(
    private readonly DmsClientInterface $dmsClient,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $result = $this->dmsClient->testConnectivity();
    $output->writeln($result['message']);
    if ($result['status_code'] !== NULL) {
      $output->writeln('HTTP status: ' . $result['status_code']);
    }
    $output->writeln('Credentials were not displayed.');
    return $result['success'] ? Command::SUCCESS : Command::FAILURE;
  }

}
