<?php

/**
 * @file
 * Procurement planning guards; never creates tenders or imports activities.
 */

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\NodeInterface;

/**
 * Validates approval, linkage, stable source keys and optional schedules.
 */
function reg_core_procurement_errors(NodeInterface $node): array {
  if (!in_array($node->bundle(), ['reg_procurement_plan', 'reg_procurement_plan_item'], TRUE)) {
    return [];
  }
  $errors = [];
  $approver = \Drupal::currentUser()->hasPermission('approve reg procurement plans');
  $original = $node->isNew() ? NULL : $node->getOriginal();
  if ($original && $node->bundle() === 'reg_procurement_plan' && $original->hasField('field_reg_source_id') && $original->get('field_reg_source_id')->value === 'EUCL-FY2025-2026' && !$node->get('field_reg_source_id')->equals($original->get('field_reg_source_id'))) {
    $errors['field_reg_source_id'] = t('The imported source plan key is immutable.');
  }
  if ($original && $node->bundle() === 'reg_procurement_plan_item' && $original->hasField('field_reg_import_state') && !$original->get('field_reg_import_state')->isEmpty()) {
    foreach (['field_reg_source_id', 'field_reg_procurement_plan_ref'] as $identity) {
      if (!$node->get($identity)->equals($original->get($identity))) $errors[$identity] = t('Imported item identity and parent plan cannot be changed. Reconcile the source explicitly.');
    }
  }
  if ($node->bundle() === 'reg_procurement_plan') {
    $status = (string) $node->get('field_reg_proc_plan_status')->value;
    if (!$approver && in_array($status, ['approved', 'published', 'superseded', 'archived'], TRUE) && (!$original || !$node->get('field_reg_proc_plan_status')->equals($original->get('field_reg_proc_plan_status')))) {
      $errors['field_reg_proc_plan_status'] = t('Only a Procurement Approver may approve or retire a plan.');
    }
    if (!$approver && ($original ? !$node->get('field_reg_approval_date')->equals($original->get('field_reg_approval_date')) : !$node->get('field_reg_approval_date')->isEmpty())) {
      $errors['field_reg_approval_date'] = t('Only a Procurement Approver may set the approval date.');
    }
    foreach ($node->get('field_reg_private_documents')->referencedEntities() as $file) {
      if (!str_starts_with($file->getFileUri(), 'private://')) {
        $errors['field_reg_private_documents'] = t('Source documents must use private storage.');
      }
    }
  }
  else {
    $plan = $node->get('field_reg_procurement_plan_ref')->entity;
    if (!$plan instanceof NodeInterface || $plan->bundle() !== 'reg_procurement_plan') {
      $errors['field_reg_procurement_plan_ref'] = t('Select an Annual Procurement Plan.');
    }
    elseif ((string) $node->get('field_reg_entity')->value !== (string) $plan->get('field_reg_entity')->value) {
      $errors['field_reg_entity'] = t('The owning organization must match the selected plan.');
    }
    $key = trim((string) $node->get('field_reg_source_id')->value);
    if ($key !== '' && $plan) {
      $q = \Drupal::entityQuery('node')->accessCheck(FALSE)->condition('type', 'reg_procurement_plan_item')->condition('field_reg_procurement_plan_ref.target_id', $plan->id())->condition('field_reg_source_id', $key);
      if (!$node->isNew()) {
        $q->condition('nid', $node->id(), '<>');
      }
      if ($q->count()->execute()) {
        $errors['field_reg_source_id'] = t('This import key already exists in the selected plan.');
      }
    }
    if (!$approver && $node->get('field_reg_review_status')->value === 'verified' && (!$original || !$node->get('field_reg_review_status')->equals($original->get('field_reg_review_status')))) {
      $errors['field_reg_review_status'] = t('Only a Procurement Approver may verify an item.');
    }
    $start = $node->get('field_reg_evaluation_start')->value;
    $end = $node->get('field_reg_evaluation_end')->value;
    if ($start && $end && $end < $start) {
      $errors['field_reg_evaluation_end'] = t('Evaluation end must not precede evaluation start.');
    }
    if (!$node->get('field_reg_source_row')->isEmpty() && (int) $node->get('field_reg_source_row')->value < 1) {
      $errors['field_reg_source_row'] = t('Source row number must be positive.');
    }
  }
  return $errors;
}

/**
 * Preserves nullable budgets and prevents unauthorized approval on saves.
 */
function reg_core_procurement_presave(NodeInterface $node): void {
  if (!in_array($node->bundle(), ['reg_procurement_plan', 'reg_procurement_plan_item'], TRUE)) {
    return;
  }
  if ($node->bundle() === 'reg_procurement_plan_item') {
    $plan = $node->get('field_reg_procurement_plan_ref')->entity;
    if ($plan && $node->get('field_reg_entity')->isEmpty()) {
      $node->set('field_reg_entity', $plan->get('field_reg_entity')->getValue());
    }
    // No conversion of empty/null budgets to zero; no tender creation side effects.
  }
  $errors = reg_core_procurement_errors($node);
  if ($errors) {
    throw new EntityStorageException((string) reset($errors));
  }
  if (!$node->isNew()) {
    $node->setNewRevision(TRUE);
  }
}

/**
 * Adds field guidance and limits approval choices for procurement editors.
 */
function reg_core_procurement_form_alter(array &$form, FormStateInterface $state): void {
  $node = $state->getFormObject()->getEntity();
  if (!$node instanceof NodeInterface || !in_array($node->bundle(), ['reg_procurement_plan', 'reg_procurement_plan_item'], TRUE)) {
    return;
  }
  $form['#validate'][] = 'reg_core_procurement_form_validate';
  if (isset($form['title']['widget'][0]['value'])) {
    $form['title']['widget'][0]['value']['#title'] = $node->bundle() === 'reg_procurement_plan' ? t('Plan title') : t('Activity title');
  }
  if (isset($form['field_reg_source_id'])) {
    $form['field_reg_source_id']['widget'][0]['value']['#description'] = t('Stable source key for imported rows; leave blank for a manually entered item. Unique within its plan.');
  }
  foreach (['field_reg_estimated_budget', 'field_reg_fy_budget'] as $field) {
    if (isset($form[$field])) {
      $form[$field]['widget'][0]['value']['#description'] = t('Leave blank when the source does not provide a budget. Zero means an explicitly stated zero.');
    }
  }
  if (isset($form['field_reg_review_status']['widget']['#options'])) {
    $form['field_reg_review_status']['widget']['#options']['needs_review'] = t('Needs review');
    $form['field_reg_review_status']['widget']['#options']['verified'] = t('Verified / current');
  }
  if (!\Drupal::currentUser()->hasPermission('approve reg procurement plans')) {
    foreach (['field_reg_proc_plan_status' => ['approved', 'published', 'superseded', 'archived'], 'field_reg_review_status' => ['verified']] as $field => $options) {
      foreach ($options as $option) {
        unset($form[$field]['widget']['#options'][$option]);
      }
    }
  }
}

/**
 * Reports planning validation errors on ordinary Drupal node forms.
 */
function reg_core_procurement_form_validate(array &$form, FormStateInterface $state): void {
  foreach (reg_core_procurement_errors($state->getFormObject()->getEntity()) as $field => $message) {
    $state->setErrorByName($field, $message);
  }
}

/**
 * Implements hook_file_download(): deny public access to original source uploads.
 */
function reg_core_file_download(string $uri): array|int|null {
  if (!str_starts_with($uri, 'private://procurement-plans/')) {
    return NULL;
  }
  if (!\Drupal::currentUser()->hasPermission('administer reg procurement plans')) {
    return -1;
  }
  return [];
}

/**
 * Cacheable field privacy/approval access, scoped to procurement bundles only.
 */
function reg_core_procurement_field_access(string $operation, FieldDefinitionInterface $definition, AccountInterface $account, ?FieldItemListInterface $items): ?AccessResult {
  $node = $items?->getEntity();
  if ($operation === 'edit' && $definition->getTargetBundle() === 'reg_bid' && !\Drupal\reg_core\Procurement\TenderSubmissionPolicy::onlineEnabled()) {
    return AccessResult::forbidden()->addCacheTags(['config:reg_core.settings']);
  }
  if ($node instanceof NodeInterface && $node->bundle() === 'reg_tender' && $definition->getName() === 'field_reg_plan_link_approval') {
    return AccessResult::forbiddenIf(!$account->hasPermission('confirm reg tender plan relationships'))->cachePerPermissions();
  }
  if (!$node instanceof NodeInterface || !in_array($node->bundle(), ['reg_procurement_plan', 'reg_procurement_plan_item'], TRUE)) {
    return NULL;
  }
  $field = $definition->getName();
  if ($field === 'field_reg_import_state' && $operation === 'edit') return AccessResult::forbidden()->cachePerPermissions();
  if (in_array($field, ['field_reg_private_documents', 'field_reg_source_id', 'field_reg_source_row', 'field_reg_import_state', 'field_reg_import_warnings'], TRUE)) {
    return AccessResult::forbiddenIf(!$account->hasPermission('administer reg procurement plans'))->cachePerPermissions();
  }
  if ($operation === 'view' && in_array($field, ['field_reg_estimated_budget', 'field_reg_fy_budget'], TRUE) && $node->hasField('field_reg_budget_visibility') && $node->get('field_reg_budget_visibility')->value !== 'public') {
    return AccessResult::forbiddenIf(!$account->hasPermission('administer reg procurement plans'))->cachePerPermissions()->addCacheableDependency($node);
  }
  if ($operation === 'edit' && !$account->hasPermission('approve reg procurement plans') && ($field === 'field_reg_approval_date' || ($field === 'field_reg_proc_plan_status' && in_array($items->value, ['approved', 'published', 'superseded', 'archived'], TRUE)) || ($field === 'field_reg_review_status' && $items->value === 'verified'))) {
    return AccessResult::forbidden()->cachePerPermissions()->addCacheableDependency($node);
  }
  return NULL;
}
