<?php

namespace Drupal\reg_core\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Stores aggregated unanswered searches without request or customer metadata.
 */
final class FaqUnansweredLogger implements FaqUnansweredLoggerInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(string $phrase, string $langcode): void {
    $phrase = self::sanitizePhrase($phrase);
    if ($phrase === NULL) {
      return;
    }
    $langcode = preg_replace('/[^a-z0-9-]/i', '', mb_substr($langcode, 0, 12)) ?: 'en';
    $hash = hash('sha256', $langcode . ':' . $phrase);
    $now = $this->time->getRequestTime();

    try {
      $updated = $this->increment($hash, $now);
      if ($updated === 0) {
        try {
          $this->database->insert('reg_core_faq_unanswered')
            ->fields([
              'query_hash' => $hash,
              'phrase' => $phrase,
              'langcode' => $langcode,
              'occurrences' => 1,
              'first_searched' => $now,
              'last_searched' => $now,
            ])
            ->execute();
        }
        catch (IntegrityConstraintViolationException) {
          // A concurrent request inserted the same aggregate after our update.
          $this->increment($hash, $now);
        }
      }
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not aggregate an unanswered FAQ search: @message', [
        '@message' => $exception->getMessage(),
      ]);
    }
  }

  /**
   * Increments an existing aggregate and returns the affected row count.
   */
  private function increment(string $hash, int $now): int {
    return $this->database->update('reg_core_faq_unanswered')
      ->fields([
        'last_searched' => $now,
      ])
      ->expression('occurrences', 'occurrences + 1')
      ->condition('query_hash', $hash)
      ->execute();
  }

  /**
   * Returns a bounded phrase, rejecting likely contact or account identifiers.
   */
  public static function sanitizePhrase(string $phrase): ?string {
    $phrase = Html::decodeEntities(strip_tags($phrase));
    $phrase = preg_replace('/\s+/u', ' ', trim($phrase)) ?? '';
    $phrase = mb_strtolower(mb_substr($phrase, 0, 160));
    if (mb_strlen($phrase) < 2) {
      return NULL;
    }
    if (filter_var($phrase, FILTER_VALIDATE_EMAIL) !== FALSE || preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', $phrase)) {
      return NULL;
    }
    if (preg_match('/(?:\+?\d[\s().-]*){7,}/u', $phrase) || preg_match('/\d{6,}/', $phrase)) {
      return NULL;
    }
    return $phrase;
  }

}
