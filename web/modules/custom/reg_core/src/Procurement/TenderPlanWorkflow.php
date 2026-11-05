<?php

namespace Drupal\reg_core\Procurement;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Guided plan selection and immutable default snapshots on existing tenders. */
final class TenderPlanWorkflow {

  public const DEFAULTS = [
    'field_reg_entity' => 'field_reg_entity',
    'field_reg_project' => 'field_reg_project',
    'field_reg_department' => 'field_reg_department',
    'field_reg_funding_source' => 'field_reg_funding_source',
    'field_reg_procurement_method' => 'field_reg_procurement_method',
    'field_reg_method_code' => 'field_reg_method_code',
    'field_reg_procurement_plan' => 'field_reg_plan_reference',
    'title' => 'title',
    'field_reg_description' => 'field_reg_description',
  ];

  /**
   * Checks current approved plan, verified activity and editor access.
   */
  public static function eligible(NodeInterface $item): bool {
    if ($item->bundle() !== 'reg_procurement_plan_item' || $item->get('field_reg_review_status')->value !== 'verified' || in_array($item->get('field_reg_proc_item_status')->value, [
      'cancelled',
      'deferred',
      'closed',
    ], TRUE)) {
      return FALSE;
    }
    $plan = $item->get('field_reg_procurement_plan_ref')->entity;
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    return $plan instanceof NodeInterface && $plan->bundle() === 'reg_procurement_plan'
      && in_array($plan->get('field_reg_proc_plan_status')->value, ['approved', 'published'], TRUE)
      && (int) $storage->getLatestRevisionId($plan->id()) === (int) $plan->getRevisionId()
      && (int) $storage->getLatestRevisionId($item->id()) === (int) $item->getRevisionId()
      && $item->access('view') && $plan->access('view');
  }

  /**
   * Applies defaults only when a relationship is first selected, not on edits. */
  public static function presave(NodeInterface $node): void {
    if (!TenderSubmissionPolicy::plansEnabled()) {
      SimpleTenderWorkflow::presave($node);
      return;
    }
    $original = $node->isNew() ? NULL : $node->getOriginal();
    $changed = $node->isNew() || ($original && !$node->get('field_reg_proc_plan_item')->equals($original->get('field_reg_proc_plan_item')));
    $item = $node->get('field_reg_proc_plan_item')->entity;
    if ($changed) {
      if (!$node->isNew()) {
        if (!\Drupal::currentUser()->hasPermission('confirm reg tender plan relationships') || $node->get('field_reg_plan_link_approval')->value !== ($item ? (string) $item->id() : 'REMOVE')) {
          throw new EntityStorageException('An authorized administrator must explicitly confirm the selected activity ID (or REMOVE) before changing an existing tender relationship.');
        }
      }
      if ($item) {
        if (!self::eligible($item)) {
          throw new EntityStorageException('Select an approved activity from an approved annual procurement plan.');
        }
        if ($node->get('field_reg_unplanned')->value) {
          throw new EntityStorageException('A tender cannot be both planned and an unplanned exception.');
        }
        $plan = $item->get('field_reg_procurement_plan_ref')->entity;
        if (!$node->get('field_reg_financial_year')->isEmpty() && $node->get('field_reg_financial_year')->value !== $plan->get('field_reg_financial_year')->value) {
          throw new EntityStorageException('Financial year does not match the selected approved plan.');
        }
        $node->set('field_reg_financial_year', $plan->get('field_reg_financial_year')->getValue());
        $category = self::category($item);
        if ($category && ($node->get('field_reg_tender_category')->isEmpty() || ($node->isNew() && !\Drupal::currentUser()->hasPermission('override reg procurement plan defaults')))) {
          $node->set('field_reg_tender_category', $category);
        }
        foreach (self::DEFAULTS as $target => $source) {
          $controlled = !in_array($target, [
            'title',
            'field_reg_description',
          ], TRUE) && $node->isNew() && !\Drupal::currentUser()->hasPermission('override reg procurement plan defaults');
          if (!$item->get($source)->isEmpty() && ($controlled || $node->get($target)->isEmpty())) {
            $node->set($target, $item->get($source)->getValue());
          }
        }
      }
      elseif ($node->isNew()) {
        if (!$node->get('field_reg_unplanned')->value || !\Drupal::currentUser()->hasPermission('override reg procurement plan defaults')) {
          throw new EntityStorageException('Select an approved plan activity or an authorized unplanned procurement exception.');
        }
      }
    }
    $exception_changed = $node->isNew();
    if ($original) {
      foreach ([
        'field_reg_unplanned',
        'field_reg_exception_reason',
        'field_reg_approval_ref',
        'field_reg_approval_date',
        'field_reg_approval_document',
      ] as $field) {
        if (!$node->get($field)->equals($original->get($field))) {
          $exception_changed = TRUE;
        }
      }
    }
    if ($item && $node->get('field_reg_unplanned')->value && $exception_changed) {
      throw new EntityStorageException('A tender cannot be both planned and an unplanned exception.');
    }
    if ($node->get('field_reg_unplanned')->value && $exception_changed) {
      if (!\Drupal::currentUser()->hasPermission('override reg procurement plan defaults')) {
        throw new EntityStorageException('An authorized approver must document procurement exceptions.');
      }
      foreach ([
        'field_reg_exception_reason',
        'field_reg_approval_ref',
        'field_reg_approval_date',
        'field_reg_approval_document',
      ] as $field) {
        if ($node->get($field)->isEmpty()) {
          throw new EntityStorageException('Exception reason, approval reference, approval date and approval document are required.');
        }
      }
    }
    // Existing legacy tenders keep their historical empty channel/link values.
    $channel_changed = $node->isNew();
    if ($original) {
      foreach ([
        'field_reg_submission_method',
        'field_reg_publication_channel',
        'field_reg_umucyo_url',
        'field_reg_submission_url',
      ] as $field) {
        if (!$node->get($field)->equals($original->get($field))) {
          $channel_changed = TRUE;
        }
      }
    }
    if ($channel_changed) {
      $method = $node->get('field_reg_submission_method')->value;
      $channel = $node->get('field_reg_publication_channel')->value;
      if (!in_array($method, [
        'reg_online',
        'umucyo',
        'external',
        'offline',
      ], TRUE) || !in_array($channel, [
        'reg',
        'umucyo',
        'both',
      ], TRUE)) {
        throw new EntityStorageException('Select publication channel and authoritative submission method.');
      }
      if (($method === 'umucyo' || in_array($channel, [
        'umucyo',
        'both',
      ], TRUE)) && !TenderSubmissionPolicy::umucyoUrl((string) $node->get('field_reg_umucyo_url')->uri)) {
        throw new EntityStorageException('Provide the approved tender-specific HTTPS UMUCYO URL; portal homepages are not tender destinations.');
      }
      if ($method === 'external' && $node->get('field_reg_submission_url')->isEmpty()) {
        throw new EntityStorageException('Provide the approved external submission URL.');
      }
    }
  }

  /**
   * Adds three dependent selects to the existing core tender node form. */
  public static function form(array &$form, FormStateInterface $state): void {
    $node = $state->getFormObject()->getEntity();
    if (!$node instanceof NodeInterface || $node->bundle() !== 'reg_tender') {
      return;
    }
    if (!TenderSubmissionPolicy::plansEnabled()) {
      SimpleTenderWorkflow::form($form, $state);
      return;
    }
    $form['#prefix'] = '<div id="reg-tender-plan-form">' . ($form['#prefix'] ?? '');
    $form['#suffix'] = ($form['#suffix'] ?? '') . '</div>';
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $plans = [];
    $years = [];
    foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan')->execute()) as $p) {
      if (!$p->access('view')) {
        continue;
      }
      $years[$p->get('field_reg_financial_year')->value] = $p->get('field_reg_financial_year')->value;
      if (in_array($p->get('field_reg_proc_plan_status')->value, [
        'approved',
        'published',
      ], TRUE) && (int) $storage->getLatestRevisionId($p->id()) === (int) $p->getRevisionId()) {
        $plans[$p->id()] = $p;
      }
    }
    krsort($years);
    $current = $node->get('field_reg_proc_plan_item')->entity;
    $year = $state->getValue('reg_plan_year') ?? ($node->get('field_reg_financial_year')->value ?? $current?->get('field_reg_procurement_plan_ref')->entity?->get('field_reg_financial_year')->value ?? '');
    $plan_id = $state->getValue('reg_plan_id') ?? $current?->get('field_reg_procurement_plan_ref')->target_id ?? '';
    $trigger = $state->getTriggeringElement()['#parents'][0] ?? '';
    if ($trigger === 'reg_plan_year') {
      $plan_id = '';
    }
    $plan_options = [];
    foreach ($plans as $id => $p) {
      if ($p->get('field_reg_financial_year')->value === $year) {
        $plan_options[$id] = $p->label();
      }
    }
    if (!isset($plan_options[$plan_id])) {
      $plan_id = '';
    }
    $ajax = ['callback' => [self::class, 'ajax'], 'wrapper' => 'reg-tender-plan-form'];
    $form['reg_plan_year'] = [
      '#type' => 'select',
      '#title' => t('Financial Year'),
      '#options' => [
        '' => t('- Select year -'),
      ] + $years,
      '#default_value' => $year,
      '#weight' => -50,
      '#ajax' => $ajax,
    ];
    $form['reg_plan_id'] = [
      '#type' => 'select',
      '#title' => t('Annual Procurement Plan'),
      '#options' => [
        '' => t('- Select approved plan -'),
      ] + $plan_options,
      '#default_value' => $plan_id,
      '#weight' => -49,
      '#ajax' => $ajax,
    ];
    $options = [];
    if ($plan_id) {
      foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan_item')->condition('field_reg_procurement_plan_ref', $plan_id)->sort('field_reg_plan_reference')->execute()) as $item) {
        if (self::eligible($item)) {
          $options[$item->id()] = $item->get('field_reg_plan_reference')->value . ' - ' . $item->label();
        }
      }
    }
    $selected = $state->getValue(['field_reg_proc_plan_item', 0, 'target_id']) ?? $current?->id() ?? '';
    // Keep unchanged historical relationships editable.
    if (!$node->isNew() && $current && !isset($options[$current->id()])) {
      $options[$current->id()] = t('Current historical relationship: @title', ['@title' => $current->label()]);
    }
    if (in_array($trigger, ['reg_plan_year', 'reg_plan_id'], TRUE)) {
      $selected = '';
      $state->setValue(['field_reg_proc_plan_item', 0, 'target_id'], '');
      $input = $state->getUserInput();
      $input['field_reg_proc_plan_item'][0]['target_id'] = '';
      $input['reg_plan_id'] = $plan_id;
      $state->setUserInput($input);
    }
    if ($node->isNew()) {
      $form['field_reg_financial_year']['#access'] = FALSE;
    }
    $form['field_reg_proc_plan_item']['#weight'] = -48;
    $form['field_reg_proc_plan_item']['widget'][0]['target_id'] = [
      '#type' => 'select',
      '#title' => t('Procurement Plan Item'),
      '#options' => [
        '' => t('- Select available approved activity -'),
      ] + $options,
      '#default_value' => $selected,
      '#ajax' => $ajax,
    ];
    if ($trigger === 'field_reg_proc_plan_item' && isset($options[$selected]) && $node->isNew()) {
      $item = $storage->load($selected);
      $input = $state->getUserInput();
      foreach (self::DEFAULTS as $target => $source) {
        if (!$node->hasField($target) || $item->get($source)->isEmpty()) {
          continue;
        }
        $value = $item->get($source)->value;
        // Preserve a title deliberately entered by the editor.
        if ($target === 'title' && trim((string) ($input['title'][0]['value'] ?? '')) !== '') {
          continue;
        }
        if (isset($form[$target]['widget']['#options'])) {
          $input[$target] = $value;
          $form[$target]['widget']['#default_value'] = [$value];
        }
        else {
          $input[$target][0]['value'] = $value;
          $form[$target]['widget'][0]['value']['#default_value'] = $value;
        }
        $state->setValue($target, $input[$target]);
      }
      if ($category = self::category($item)) {
        $input['field_reg_tender_category'] = (string) $category;
        $state->setValue('field_reg_tender_category', (string) $category);
        $form['field_reg_tender_category']['widget']['#default_value'] = [$category];
      }
      $state->setUserInput($input);
    }
    if (isset($form['field_reg_plan_link_approval'])) {
      $form['field_reg_plan_link_approval']['#access'] = !$node->isNew() && \Drupal::currentUser()->hasPermission('confirm reg tender plan relationships');
      $form['field_reg_plan_link_approval']['widget'][0]['value']['#description'] = t('To confirm linking this existing tender, type the selected activity ID: @id. Type REMOVE to unlink. Title similarity never establishes a relationship.', [
        '@id' => $selected ?: t('select an activity first'),
      ]);
    }
    $form['field_reg_proc_plan_item']['widget'][0]['target_id']['#description'] = t('Only verified activities in approved plans are offered. Planned dates do not open a tender; enter actual dates and manage tender status separately.');
    foreach ([
      'field_reg_publication_channel' => [
        'reg' => t('REG PLATFORM'),
        'umucyo' => t('UMUCYO'),
        'both' => t('BOTH'),
      ],
      'field_reg_submission_method' => [
        'reg_online' => t('REG ONLINE BIDDING'),
        'umucyo' => t('UMUCYO'),
        'external' => t('OTHER APPROVED METHOD - external platform'),
        'offline' => t('OTHER APPROVED METHOD - offline'),
      ],
    ] as $field => $labels) {
      foreach ($labels as $key => $label) {
        if (isset($form[$field]['widget']['#options'][$key])) {
          $form[$field]['widget']['#options'][$key] = $label;
        }
      }
    }
    $form['#validate'][] = [self::class, 'validate'];
  }

  /**
   * Looks up the existing taxonomy term by its reviewed Drupal label.
   */
  private static function category(NodeInterface $item): ?int {
    $key = $item->get('field_reg_proc_category_key')->value;
    $label = $item->get('field_reg_proc_category_key')->getFieldDefinition()->getSetting('allowed_values')[$key] ?? '';
    if ($label === '') {
      return NULL;
    }
    $ids = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->condition('vid', 'reg_procurement_category')->condition('name', $label)->range(0, 1)->execute();
    return $ids ? (int) reset($ids) : NULL;
  }

  /**
   * Rebuilds the existing tender form for a dependent selection.
   */
  public static function ajax(array &$form, FormStateInterface $form_state): array {
    return $form;
  }

  /**
   * Reports planning and channel errors before the node is saved.
   */
  public static function validate(array &$form, FormStateInterface $state): void {
    $node = $state->getFormObject()->buildEntity($form, $state);
    if ($node->isNew() && $state->getValue('reg_plan_year')) {
      $node->set('field_reg_financial_year', $state->getValue('reg_plan_year'));
    }
    try {
      self::presave(clone $node);
    }
    catch (EntityStorageException $e) {
      $state->setErrorByName('field_reg_proc_plan_item', $e->getMessage());
    }
    $id = $state->getValue('reg_plan_id');
    $year = $state->getValue('reg_plan_year');
    $item = $node->get('field_reg_proc_plan_item')->entity;
    if ($item && $node->isNew()) {
      $plan = $item->get('field_reg_procurement_plan_ref')->entity;
      if (!$plan || (string) $plan->id() !== (string) $id || $plan->get('field_reg_financial_year')->value !== $year) {
        $state->setErrorByName('field_reg_proc_plan_item', t('Selected year, plan and activity must match.'));
      }
    }
  }

}
