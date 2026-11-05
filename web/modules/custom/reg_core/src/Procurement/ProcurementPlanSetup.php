<?php

namespace Drupal\reg_core\Procurement;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;

/**
 * Additive, rerunnable extension of the existing annual planning model.
 */
final class ProcurementPlanSetup {

  /**
   * Adds missing planning fields and safe defaults without importing content.
   */
  public static function apply(): void {
    \Drupal::moduleHandler()->loadInclude('reg_core', 'install');
    foreach (['reg_procurement_plan', 'reg_procurement_plan_item', 'reg_tender'] as $bundle) {
      if (!NodeType::load($bundle)) {
        throw new \RuntimeException('Install existing plan-linked publishing foundation first.');
      }
    }
    $text = static fn($label) => ['type' => 'string_long', 'label' => $label, 'translatable' => FALSE, 'widget' => 'string_textarea'];
    $definitions = [
      'reg_procurement_plan' => [
        'field_reg_plan_version' => reg_core_string_field('Plan version', FALSE),
        'field_reg_private_documents' => ['type' => 'file', 'label' => 'Source documents (private)', 'translatable' => FALSE, 'cardinality' => -1, 'storage_settings' => ['uri_scheme' => 'private'], 'field_settings' => ['file_extensions' => 'pdf doc docx xls xlsx csv', 'file_directory' => 'procurement-plans/source/[date:custom:Y-m]', 'max_filesize' => '25 MB', 'description_field' => TRUE], 'widget' => 'file_generic'],
      ],
      'reg_procurement_plan_item' => [
        'field_reg_source_id' => reg_core_string_field('Stable import key', FALSE),
        'field_reg_source_row' => reg_core_integer_field('Source row number', FALSE),
        'field_reg_fy_budget' => reg_core_decimal_field('Budget for financial year', 18, 2, FALSE),
        'field_reg_document_prep' => reg_core_date_field('Planned document preparation date', FALSE),
        'field_reg_bid_opening' => reg_core_date_field('Planned bid opening date', FALSE),
        'field_reg_evaluation_start' => reg_core_date_field('Planned evaluation start date', FALSE),
        'field_reg_evaluation_end' => reg_core_date_field('Planned evaluation end date', FALSE),
        'field_reg_provisional_notice' => reg_core_date_field('Planned provisional notification date', FALSE),
        'field_reg_supervising_firm' => $text('Supervising firm requirement'),
        'field_reg_prior_study' => $text('Prior-study information'),
        'field_reg_review_status' => reg_core_list_field('Review status', ['needs_review' => 'Needs review', 'verified' => 'Verified / current'], TRUE, FALSE),
      ],
    ];
    // Do not rewrite shared field storage or another bundle's allowed values.
    foreach ($definitions as $fields) {
      foreach ($fields as $name => $d) {
        $s = FieldStorageConfig::loadByName('node', $name);
        if ($s && $s->getType() !== $d['type']) {
          throw new \RuntimeException('Conflicting field: ' . $name);
        }
        if ($name === 'field_reg_private_documents' && $s && $s->getSetting('uri_scheme') !== 'private') {
          throw new \RuntimeException('Source storage must be private.');
        }
      }
    }
    foreach ($definitions as $bundle => $fields) {
      foreach ($fields as $name => $d) {
        self::ensure($bundle, $name, $d);
      }
    }
    $related = FieldStorageConfig::loadByName('node', 'field_reg_related_tender');
    if ($related && $related->getCardinality() !== -1) {
      $related->setCardinality(-1)->save();
    }
    foreach (['field_reg_estimated_budget', 'field_reg_fy_budget'] as $name) {
      FieldConfig::loadByName('node', 'reg_procurement_plan_item', $name)->setRequired(FALSE)->setDefaultValue([])->save();
    }
    foreach (['field_reg_plan_reference' => 'Original activity number', 'field_reg_expected_contract' => 'Planned contract-signing date', 'field_reg_related_tender' => 'Related tenders'] as $name => $label) {
      FieldConfig::loadByName('node', 'reg_procurement_plan_item', $name)?->setLabel($label)->save();
    }
    FieldConfig::loadByName('node', 'reg_procurement_plan_item', 'field_reg_planned_quarter')?->setRequired(FALSE)->save();
    foreach (['reg_procurement_plan' => ['field_reg_proc_plan_status' => 'draft'], 'reg_procurement_plan_item' => ['field_reg_review_status' => 'needs_review', 'field_reg_budget_visibility' => 'private', 'field_reg_proc_item_status' => 'planned', 'field_reg_procurement_stage' => 'new', 'field_reg_contract_type' => 'regular', 'field_reg_planned_channel' => 'tbd']] as $bundle => $defaults) {
      foreach ($defaults as $name => $value) {
        $f = FieldConfig::loadByName('node', $bundle, $name);
        if ($f && !$f->getDefaultValueLiteral()) {
          $f->setDefaultValue($value)->save();
        }
      }
      $type = NodeType::load($bundle);
      $type->setNewRevision(TRUE);
      $type->save();
    }
    Role::load('reg_procurement_approver')?->grantPermission('approve reg procurement plans')->save();
    ProcurementImportSetup::apply();
    TenderPlanSetup::apply();
    SimpleTenderSetup::apply();
    self::configureReferences();
    self::normalizeEditorMediaTypes();
    \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  }

  /**
   * Creates missing bundle fields and form widgets.
   */
  private static function ensure(string $bundle, string $name, array $d): void {
    if (!FieldStorageConfig::loadByName('node', $name)) {
      FieldStorageConfig::create(['entity_type' => 'node', 'field_name' => $name, 'type' => $d['type'], 'cardinality' => $d['cardinality'] ?? 1, 'translatable' => $d['translatable'] ?? FALSE, 'settings' => $d['storage_settings'] ?? []])->save();
    }
    if (!FieldConfig::loadByName('node', $bundle, $name)) {
      FieldConfig::create(['entity_type' => 'node', 'bundle' => $bundle, 'field_name' => $name, 'label' => $d['label'], 'required' => $d['required'] ?? FALSE, 'translatable' => $d['translatable'] ?? FALSE, 'settings' => $d['field_settings'] ?? []])->save();
    }
    $form = EntityFormDisplay::load("node.$bundle.default");
    if ($form && !$form->getComponent($name)) {
      $form->setComponent($name, ['type' => $d['widget'], 'weight' => 40 + count($form->getComponents())])->save();
    }
    foreach (EntityViewDisplay::loadMultiple() as $view) {
      if ($view->getTargetEntityTypeId() === 'node' && $view->getTargetBundle() === $bundle && in_array($name, ['field_reg_private_documents', 'field_reg_source_id', 'field_reg_source_row'], TRUE) && $view->getComponent($name)) {
        $view->removeComponent($name)->save();
      }
    }
  }

  /**
   * Retains precisely the existing media types in the CKEditor keyed format.
   */
  public static function normalizeEditorMediaTypes(): void {
    foreach (\Drupal\filter\Entity\FilterFormat::loadMultiple() as $format) {
      $filters = $format->get('filters');
      $types = $filters['media_embed']['settings']['allowed_media_types'] ?? [];
      if ($types && array_is_list($types) && count(array_filter($types, 'is_string')) === count($types)) {
        $filters['media_embed']['settings']['allowed_media_types'] = array_combine($types, $types);
        $format->set('filters', $filters)->save();
      }
    }
  }

  /**
   * Retains reference fields while allowing editable unpublished planning data.
   */
  public static function configureReferences(): void {
    \Drupal::service('plugin.manager.entity_reference_selection')->clearCachedDefinitions();
    foreach ([['reg_procurement_plan_item','field_reg_procurement_plan_ref'],['reg_procurement_plan_item','field_reg_related_tender']] as [$bundle,$name]) {
      $field = FieldConfig::loadByName('node', $bundle, $name);
      if (!$field || !in_array($field->getSetting('handler'), ['default:node','reg_procurement'], TRUE)) throw new \RuntimeException('Review existing procurement reference handler before changing it.');
      if ($field->getSetting('handler') !== 'reg_procurement') $field->setSetting('handler','reg_procurement')->save();
    }
  }

}
