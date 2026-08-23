<?php

namespace Drupal\reg_core\Outage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Performs interval-controlled DMS cache refreshes from Drupal cron.
 */
final class DmsOutageSynchronizer {

  private const LAST_ATTEMPT_STATE = 'reg_core.dms_last_sync_attempt';

  public function __construct(
    private readonly OutageRepositoryInterface $repository,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Refreshes the repository cache when its configured interval has elapsed.
   */
  public function synchronizeIfDue(): void {
    $now = $this->time->getRequestTime();
    $last = (int) $this->state->get(self::LAST_ATTEMPT_STATE, 0);
    $interval = max(60, min(86400, (int) ($this->configFactory->get('reg_core.settings')->get('dms.synchronization_interval') ?: 300)));
    if (($now - $last) < $interval) {
      return;
    }

    $this->state->set(self::LAST_ATTEMPT_STATE, $now);
    $result = $this->repository->refresh();
    if (in_array($result->source, ['dms', 'dms_cache', 'mock'], TRUE)) {
      $this->state->set('reg_core.dms_last_sync_success', $now);
      $this->state->set('reg_core.dms_last_sync_source', $result->source);
    }
    else {
      $this->state->set('reg_core.dms_last_sync_failure', $now);
      $this->state->set('reg_core.dms_last_sync_source', $result->source);
    }
    $this->logger->info('Scheduled outage synchronization completed with source @source.', [
      '@source' => $result->source,
    ]);
  }

}
