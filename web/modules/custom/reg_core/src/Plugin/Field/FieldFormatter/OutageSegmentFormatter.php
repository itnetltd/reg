<?php

namespace Drupal\reg_core\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Provides a safe administrative summary; public pages use the repository.
 */
#[FieldFormatter(id: 'reg_outage_segment_formatter', label: new TranslatableMarkup('Outage window summary'), field_types: ['reg_outage_segment'])]
final class OutageSegmentFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      $elements[$delta] = ['#markup' => $this->t('@start to @end — @feeder', ['@start' => $item->start, '@end' => $item->end, '@feeder' => $item->feeder])];
    }
    return $elements;
  }

}
