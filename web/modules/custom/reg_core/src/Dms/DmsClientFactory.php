<?php

namespace Drupal\reg_core\Dms;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Selects the configured DMS adapter without coupling consumers to GE.
 */
final class DmsClientFactory {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly GeDmsClient $geClient,
    private readonly MockDmsClient $mockClient,
  ) {}

  /**
   * Returns the configured DMS client.
   */
  public function create(): DmsClientInterface {
    return $this->configFactory->get('reg_core.settings')->get('dms.driver') === 'ge'
      ? $this->geClient
      : $this->mockClient;
  }

}
