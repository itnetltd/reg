<?php

namespace Drupal\reg_core\Procurement;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;

/**
 * Additive integration with the existing tender/plan and supplier entities. */
final class TenderPlanSetup {

  /**
   * Adds missing tender metadata without updating existing content.
   */
  public static function apply(): void {
    foreach ([
      'field_reg_department' => ['string', 'Department'],
      'field_reg_funding_source' => ['string', 'Source funding'],
      'field_reg_method_code' => ['string', 'Original procurement method code'],
      'field_reg_plan_link_approval' => ['string', 'Confirm existing tender relationship'],
      'field_reg_bid_instructions' => ['text_long', 'Submission instructions'],
    ] as $name => [$type, $label]) {
      $storage = FieldStorageConfig::loadByName('node', $name);
      if ($storage && $storage->getType() !== $type) {
        throw new \RuntimeException('Conflicting tender field: ' . $name);
      }
      if (!$storage) {
        FieldStorageConfig::create([
          'entity_type' => 'node',
          'field_name' => $name,
          'type' => $type,
          'translatable' => FALSE,
        ])->save();
      }
      if (!FieldConfig::loadByName('node', 'reg_tender', $name)) {
        FieldConfig::create([
          'entity_type' => 'node',
          'bundle' => 'reg_tender',
          'field_name' => $name,
          'label' => $label,
          'translatable' => FALSE,
        ])->save();
      }
      $form = EntityFormDisplay::load('node.reg_tender.default');
      if ($form && !$form->getComponent($name)) {
        $form->setComponent($name, [
          'type' => $type === 'text_long' ? 'text_textarea' : 'string_textfield',
          'weight' => 20,
        ])->save();
      }
    }
    $form = EntityFormDisplay::load('node.reg_tender.default');
    if ($form && !$form->getComponent('field_reg_procurement_plan')) {
      $form->setComponent('field_reg_procurement_plan', ['type' => 'string_textfield', 'weight' => 22])->save();
    }
    $ref = FieldConfig::loadByName('node', 'reg_tender', 'field_reg_proc_plan_item');
    if (!in_array($ref->getSetting('handler'), ['default:node', 'reg_procurement'], TRUE)) {
      throw new \RuntimeException('Review custom tender selection handler before migration.');
    }
    $ref->setSetting('handler', 'reg_procurement')->save();
    $status = FieldStorageConfig::loadByName('node', 'field_reg_tender_status');
    $values = $status->getSetting('allowed_values');
    if (!isset($values['preparing'])) {
      $status->setSetting('allowed_values', $values + ['preparing' => 'Preparing / not open'])->save();
    }
    FieldConfig::loadByName('node', 'reg_tender', 'field_reg_tender_status')->setDefaultValue('preparing')->save();
    foreach (EntityViewDisplay::loadMultiple() as $view) {
      if ($view->getTargetEntityTypeId() === 'node' && $view->getTargetBundle() === 'reg_tender' && $view->getComponent('field_reg_plan_link_approval')) {
        $view->removeComponent('field_reg_plan_link_approval')->save();
      }
    }
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

}
