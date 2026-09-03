<?php

namespace Drupal\reg_core\Social;

/**
 * Provides cached, presentation-ready public X posts.
 */
interface XFeedServiceInterface {

  /**
   * Returns homepage posts, fetching only when the cache requires it.
   *
   * @return array<string, mixed>
   *   Feed items and non-sensitive result metadata.
   */
  public function homepageFeed(bool $force_refresh = FALSE): array;

  /**
   * Refreshes the feed when enabled and its cache has expired.
   */
  public function refreshIfDue(): void;

  /**
   * Returns non-sensitive administration status.
   *
   * @return array<string, mixed>
   *   Credential, refresh, cache, and error status.
   */
  public function status(): array;

}
