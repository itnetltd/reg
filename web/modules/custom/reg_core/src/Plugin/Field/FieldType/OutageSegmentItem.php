<?php

namespace Drupal\reg_core\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Stores one manually managed interruption window inside an outage revision.
 */
#[FieldType(
  id: 'reg_outage_segment',
  label: new TranslatableMarkup('Outage schedule window'),
  description: new TranslatableMarkup('A dated feeder window with controlled locations and exact public wording.'),
  default_widget: 'reg_outage_segment_widget',
  default_formatter: 'reg_outage_segment_formatter',
)]
final class OutageSegmentItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition): array {
    $properties = [];
    foreach (['start', 'end', 'feeder', 'substation', 'districts', 'sectors', 'affected_area', 'notes', 'restoration_status', 'actual_restoration'] as $name) {
      $properties[$name] = DataDefinition::create('string')->setLabel(new TranslatableMarkup(ucwords(str_replace('_', ' ', $name))));
    }
    $properties['restored_early'] = DataDefinition::create('boolean')->setLabel(new TranslatableMarkup('Restored early'));
    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition): array {
    return ['columns' => [
      'start' => ['type' => 'varchar', 'length' => 20],
      'end' => ['type' => 'varchar', 'length' => 20],
      'feeder' => ['type' => 'varchar', 'length' => 255],
      'substation' => ['type' => 'varchar', 'length' => 255],
      'districts' => ['type' => 'varchar', 'length' => 512],
      'sectors' => ['type' => 'varchar', 'length' => 1024],
      'affected_area' => ['type' => 'text', 'size' => 'big'],
      'notes' => ['type' => 'text', 'size' => 'normal'],
      'restoration_status' => ['type' => 'varchar', 'length' => 32],
      'actual_restoration' => ['type' => 'varchar', 'length' => 20],
      'restored_early' => ['type' => 'int', 'size' => 'tiny', 'default' => 0],
    ], 'indexes' => ['schedule' => ['start', 'end']]];
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty(): bool {
    return trim((string) $this->get('start')->getValue()) === ''
      && trim((string) $this->get('feeder')->getValue()) === ''
      && trim((string) $this->get('affected_area')->getValue()) === '';
  }

}
