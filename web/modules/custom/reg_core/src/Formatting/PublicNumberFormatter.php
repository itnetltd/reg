<?php

namespace Drupal\reg_core\Formatting;

use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Formats public numbers for the current interface language.
 */
final class PublicNumberFormatter {

  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  public function decimal(float|int $number, int $digits = 2): string {
    $digits = max(0, min(4, $digits));
    if (class_exists(\NumberFormatter::class)) {
      $langcode = $this->languageManager->getCurrentLanguage()->getId();
      $locale = $langcode === 'rw' ? 'rw_RW' : 'en_RW';
      $formatter = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
      $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $digits);
      $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $digits);
      $formatted = $formatter->format($number);
      if ($formatted !== FALSE) {
        return $formatted;
      }
    }
    return number_format($number, $digits, '.', ',');
  }

  public function integer(int $number): string {
    return $this->decimal($number, 0);
  }

}
