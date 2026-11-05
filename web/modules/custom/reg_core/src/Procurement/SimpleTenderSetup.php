<?php

namespace Drupal\reg_core\Procurement;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

/**
 * Additive notice-only configuration; never deletes or rewrites content.
 */
final class SimpleTenderSetup {

  /**
   * Applies additive field configuration without modifying tender content.
   */
  public static function apply(): void {
    foreach (['field_reg_reference', 'field_reg_entity', 'field_reg_tender_category', 'field_reg_description', 'field_reg_issue_date', 'field_reg_closing_date'] as $name) {
      FieldConfig::loadByName('node', 'reg_tender', $name)?->setRequired(TRUE)->save();
    }
    foreach (['field_reg_tender_documents', 'field_reg_proc_plan_item', 'field_reg_financial_year', 'field_reg_procurement_plan', 'field_reg_project'] as $name) {
      FieldConfig::loadByName('node', 'reg_tender', $name)?->setRequired(FALSE)->save();
    }
    $storage = FieldStorageConfig::loadByName('node', 'field_reg_tender_status');
    $values = $storage->getSetting('allowed_values');
    $values['evaluation'] ??= 'Evaluation';
    $storage->setSetting('allowed_values', $values)->save();
    $form = EntityFormDisplay::load('node.reg_tender.default');
    foreach (['field_reg_description' => 'text_textarea', 'field_reg_bid_instructions' => 'text_textarea', 'field_reg_contact' => 'string_textarea'] as $name => $widget) {
      if ($form && !$form->getComponent($name)) {
        $form->setComponent($name, ['type' => $widget])->save();
      }
    }
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
