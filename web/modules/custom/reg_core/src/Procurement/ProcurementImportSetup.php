<?php

namespace Drupal\reg_core\Procurement;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Additive importer metadata on the existing plan and item entities. */
final class ProcurementImportSetup {

  /**
   * Adds missing import fields and the source combined category.
   */
  public static function apply(): void {
    foreach ([
      'reg_procurement_plan' => ['field_reg_source_id' => ['string', 'Source plan key']],
      'reg_procurement_plan_item' => [
        'field_reg_method_code' => ['string', 'Original procurement method code'],
        'field_reg_source_category' => ['string', 'Original procurement category'],
        'field_reg_import_state' => ['string_long', 'Import reconciliation metadata'],
        'field_reg_import_warnings' => ['string_long', 'Source data review warnings'],
      ],
    ] as $bundle => $fields) {
      foreach ($fields as $name => [$type, $label]) {
        $storage = FieldStorageConfig::loadByName('node', $name);
        if ($storage && $storage->getType() !== $type) {
          throw new \RuntimeException('Conflicting import field: ' . $name);
        }
        if (!$storage) {
          FieldStorageConfig::create([
            'entity_type' => 'node',
            'field_name' => $name,
            'type' => $type,
            'translatable' => FALSE,
          ])->save();
        }
        if (!FieldConfig::loadByName('node', $bundle, $name)) {
          FieldConfig::create([
            'entity_type' => 'node',
            'bundle' => $bundle,
            'field_name' => $name,
            'label' => $label,
            'translatable' => FALSE,
          ])->save();
        }
        $display = EntityFormDisplay::load("node.$bundle.default");
        if ($display && $name !== 'field_reg_import_state' && !$display->getComponent($name)) {
          $display->setComponent($name, [
            'type' => $type === 'string' ? 'string_textfield' : 'string_textarea',
            'weight' => 70,
          ])->save();
        }
        foreach (EntityViewDisplay::loadMultiple() as $view) {
          if ($view->getTargetEntityTypeId() === 'node' && $view->getTargetBundle() === $bundle && $view->getComponent($name)) {
            $view->removeComponent($name)->save();
          }
        }
      }
    }
    $category = FieldStorageConfig::loadByName('node', 'field_reg_proc_category_key');
    $values = $category->getSetting('allowed_values');
    if (!isset($values['goods_non_consultancy'])) {
      $category->setSetting('allowed_values', $values + ['goods_non_consultancy' => 'Goods and Non-Consultancy Services (source combined category)'])->save();
    }
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
