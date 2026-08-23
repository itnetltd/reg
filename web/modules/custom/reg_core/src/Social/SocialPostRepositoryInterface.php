<?php

namespace Drupal\reg_core\Social;

/**
 * Provides approved, Drupal-managed social posts for public presentation.
 */
interface SocialPostRepositoryInterface {

  /**
   * Returns at most two published, homepage-featured X posts.
   */
  public function homepageXPosts(int $limit = 2): array;

}
