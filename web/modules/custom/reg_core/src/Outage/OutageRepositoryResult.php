<?php

namespace Drupal\reg_core\Outage;

/**
 * Immutable outage repository response with source/fallback metadata.
 */
final readonly class OutageRepositoryResult {

  /**
   * @param array<int, array<string, mixed>> $outages
   *   Public outage records.
   * @param string $source
   *   One of mock, dms, dms_cache, dms_stale, cms_emergency, or unavailable.
   * @param string $message
   *   Safe public source or availability message.
   */
  public function __construct(
    public array $outages,
    public string $source,
    public string $message = '',
  ) {}

  /**
   * Indicates whether CMS emergency notices replaced the DMS feed.
   */
  public function isFallback(): bool {
    return in_array($this->source, ['cms_emergency', 'dms_stale', 'unavailable'], TRUE);
  }

}
