<?php

namespace Drupal\reg_core\Procurement;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Validates publication separately from tender lifecycle and planning.
 */
final class TenderPublicationValidation {

  public const MESSAGE = 'Select a tender status before publishing. Choose Open, Closed, Evaluation, Awarded or Cancelled as appropriate.';

  /**
   * Uses the submitted moderation state, before moderation presave runs.
   */
  public static function invalid(NodeInterface $node): bool {
    if ($node->bundle() !== 'reg_tender' || TenderSubmissionPolicy::plansEnabled()) {
      return FALSE;
    }
    $publishing = $node->isPublished();
    $info = \Drupal::service('content_moderation.moderation_information');
    if ($workflow = $info->getWorkflowForEntity($node)) {
      $state = $node->get('moderation_state')->value;
      if ($state && $workflow->getTypePlugin()->hasState($state)) {
        $publishing = $workflow->getTypePlugin()->getState($state)->isPublishedState();
      }
    }
    return $publishing && !in_array($node->get('field_reg_tender_status')->value, ['active', 'closed', 'evaluation', 'awarded', 'cancelled'], TRUE);
  }

  /**
   * Adds a safe edit destination to the inline moderation form.
   */
  public static function alterQuickForm(array &$form, FormStateInterface $state): void {
    $node = $state->get('entity');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'reg_tender' || TenderSubmissionPolicy::plansEnabled()) {
      return;
    }
    if ($node->get('field_reg_tender_status')->value === 'preparing' && $node->access('update')) {
      $form['reg_tender_edit'] = [
        '#type' => 'container',
        '#weight' => -10,
        'message' => ['#markup' => t('To publish this tender, first select its tender status on the edit form. ')],
        'link' => ['#type' => 'link', '#title' => t('Edit Tender'), '#url' => $node->toUrl('edit-form')],
      ];
    }
    $form['#validate'][] = [self::class, 'validateQuickForm'];
  }

  /**
   * Core's quick form does not validate the entity before calling save().
   */
  public static function validateQuickForm(array &$form, FormStateInterface $state): void {
    $node = clone $state->get('entity');
    $node->set('moderation_state', $state->getValue('new_state'));
    // Check all entity constraints, including required fields and transition access.
    foreach ($node->validate() as $violation) {
      $state->setErrorByName('new_state', $violation->getMessage());
    }
    if ($state->hasAnyErrors()) {
      return;
    }
    // Retain channel, document and date integrity checks on this alternate path.
    try {
      SimpleTenderWorkflow::presave($node);
    }
    catch (EntityStorageException $e) {
      $state->setErrorByName('new_state', $e->getMessage());
    }
  }

}
