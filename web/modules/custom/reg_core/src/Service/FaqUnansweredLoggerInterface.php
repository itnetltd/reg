<?php

namespace Drupal\reg_core\Service;

/**
 * Aggregates privacy-filtered searches that lack an approved FAQ answer.
 */
interface FaqUnansweredLoggerInterface {

  /**
   * Records a safe phrase occurrence, or ignores it when it may contain PII.
   */
  public function record(string $phrase, string $langcode): void;

}
