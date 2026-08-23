<?php

namespace Drupal\reg_core\PublicInformation;

/**
 * Records privacy-preserving aggregate document download counts.
 */
interface DocumentDownloadTrackerInterface {

  /**
   * Increments an aggregate counter without storing visitor identifiers.
   */
  public function record(string $content_type, int $nid, int $media_id, int $file_id, string $label): void;

}
