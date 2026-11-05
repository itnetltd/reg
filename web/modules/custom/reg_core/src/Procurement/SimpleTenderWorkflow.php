<?php

namespace Drupal\reg_core\Procurement;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;

/**
 * Normal tender publishing while plan integration is switched off.
 */
final class SimpleTenderWorkflow {

  public const FIELDS = ['title', 'field_reg_reference', 'field_reg_entity', 'field_reg_tender_category', 'field_reg_description', 'field_reg_issue_date', 'field_reg_closing_date', 'field_reg_tender_status', 'field_reg_tender_documents', 'field_reg_tender_addenda', 'field_reg_bid_instructions', 'field_reg_contact'];

  /**
   * Maps existing storage values to the simplified channel selector.
   */
  public static function channel(NodeInterface $node): string {
    return match ($node->get('field_reg_submission_method')->value) {
      'umucyo' => 'umucyo',
      'external' => 'external',
      default => 'reg_notice',
    };
  }

  /**
   * Accepts HTTP(S) destinations without embedded credentials.
   */
  public static function externalUrl(string $url): bool {
    $parts = parse_url($url);
    return $parts && in_array(strtolower($parts['scheme'] ?? ''), ['https', 'http'], TRUE) && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']);
  }

  /**
   * Keeps existing plan links untouched, including on unrelated tender edits.
 */
  public static function presave(NodeInterface $node): void {
    $original = $node->isNew() ? NULL : $node->getOriginal();
    if ($node->isNew()) {
      foreach (['title', 'field_reg_reference', 'field_reg_entity', 'field_reg_tender_category', 'field_reg_description', 'field_reg_issue_date', 'field_reg_closing_date'] as $field) {
        if ($node->get($field)->isEmpty()) {
          throw new EntityStorageException('Complete required tender title, reference, organization, category, description, publication and closing dates.');
        }
      }
    }
    if (TenderPublicationValidation::invalid($node)) {
      throw new EntityStorageException(TenderPublicationValidation::MESSAGE);
    }
    $changed = $node->isNew();
    if ($original) {
      foreach (['field_reg_submission_method', 'field_reg_publication_channel', 'field_reg_umucyo_url', 'field_reg_submission_url'] as $field) {
        if (!$node->get($field)->equals($original->get($field))) {
          $changed = TRUE;
        }
      }
    }
    if ($changed) {
      $method = $node->get('field_reg_submission_method')->value;
      if (!in_array($method, ['offline', 'umucyo', 'external'], TRUE) || !in_array($node->get('field_reg_publication_channel')->value, ['reg', 'umucyo', 'both'], TRUE)) {
        throw new EntityStorageException('Choose REG notice only, UMUCYO or an approved external channel. REG online bids are disabled.');
      }
      if ($method === 'umucyo' && !TenderSubmissionPolicy::umucyoUrl((string) $node->get('field_reg_umucyo_url')->uri)) {
        throw new EntityStorageException('An approved tender-specific HTTPS UMUCYO URL is required.');
      }
      if ($method === 'external' && !self::externalUrl((string) $node->get('field_reg_submission_url')->uri)) {
        throw new EntityStorageException('A valid approved external submission URL is required.');
      }
    }
    $dates_changed = $node->isNew() || ($original && (!$node->get('field_reg_issue_date')->equals($original->get('field_reg_issue_date')) || !$node->get('field_reg_closing_date')->equals($original->get('field_reg_closing_date'))));
    if ($dates_changed && !$node->get('field_reg_issue_date')->isEmpty() && !$node->get('field_reg_closing_date')->isEmpty() && $node->get('field_reg_closing_date')->value <= $node->get('field_reg_issue_date')->value) {
      throw new EntityStorageException('Closing date/time must be after publication date/time.');
    }
    if ($node->isNew() || ($original && !$node->get('field_reg_tender_documents')->equals($original->get('field_reg_tender_documents')))) {
      foreach ($node->get('field_reg_tender_documents')->referencedEntities() as $media) {
        $source = $media->getSource()->getConfiguration()['source_field'] ?? '';
        $file = $source ? $media->get($source)->entity : NULL;
        if (!$file || strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)) !== 'pdf' || $file->getMimeType() !== 'application/pdf') {
          throw new EntityStorageException('New tender documents must be approved PDF documents. Existing legacy attachments are preserved.');
        }
      }
    }
  }

  /**
   * Builds the plan-free tender editor using existing fields.
   */
  public static function form(array &$form, FormStateInterface $state): void {
    $node = $state->getFormObject()->getEntity();
    foreach ($form as $name => &$element) {
      if (str_starts_with($name, 'field_') && !in_array($name, self::FIELDS, TRUE)) {
        $element['#access'] = FALSE;
      }
    }
    unset($element);
    $labels = ['title' => t('Tender Title'), 'field_reg_reference' => t('Tender Reference'), 'field_reg_entity' => t('Organization'), 'field_reg_tender_category' => t('Procurement Category'), 'field_reg_description' => t('Tender Description'), 'field_reg_issue_date' => t('Publication Date'), 'field_reg_closing_date' => t('Closing Date and Time'), 'field_reg_bid_instructions' => t('Additional Instructions'), 'field_reg_contact' => t('Contact Information'), 'field_reg_tender_documents' => t('Tender Documents (PDF, optional)'), 'field_reg_tender_addenda' => t('Clarification Documents')];
    foreach (self::FIELDS as $weight => $name) {
      if (!isset($form[$name])) {
        continue;
      }
      $form[$name]['#weight'] = $weight;
      if (isset($labels[$name])) {
        $form[$name]['#title'] = $labels[$name];
        if (isset($form[$name]['widget']['#title'])) {
          $form[$name]['widget']['#title'] = $labels[$name];
        }
        if (isset($form[$name]['widget'][0]['#title'])) {
          $form[$name]['widget'][0]['#title'] = $labels[$name];
        }
        if (isset($form[$name]['widget'][0]['value']['#title'])) {
          $form[$name]['widget'][0]['value']['#title'] = $labels[$name];
        }
      }
    }
    $channel = $state->getValue('reg_tender_channel') ?? ($node->isNew() ? '' : self::channel($node));
    $url = match ($channel) {'umucyo' => $node->get('field_reg_umucyo_url')->uri, 'external' => $node->get('field_reg_submission_url')->uri, default => $node->get('field_reg_external_url')->uri
    };
    $form['reg_tender_channel'] = ['#type' => 'select', '#title' => t('Publication / Submission Channel'), '#required' => TRUE, '#options' => ['' => t('- Select channel -'), 'reg_notice' => t('REG WEBSITE - NOTICE ONLY'), 'umucyo' => t('UMUCYO'), 'external' => t('OTHER APPROVED EXTERNAL CHANNEL')], '#default_value' => $channel, '#weight' => 7.5];
    $form['reg_tender_external_url'] = ['#type' => 'url', '#title' => t('External Tender URL'), '#default_value' => $url ?? '', '#weight' => 8.5, '#description' => t('Required for UMUCYO or another external channel. Supply the actual approved tender-specific destination. REG notice-only tenders do not accept online bids.'), '#states' => ['required' => [':input[name="reg_tender_channel"]' => [['value' => 'umucyo'], ['value' => 'external']]]]];
    if (isset($form['field_reg_tender_status']['widget']['#options'])) {
      $options = &$form['field_reg_tender_status']['widget']['#options'];
      foreach (['preparing' => t('Draft'), 'active' => t('Open'), 'closed' => t('Closed'), 'evaluation' => t('Evaluation'), 'awarded' => t('Awarded'), 'cancelled' => t('Cancelled')] as $key => $label) {
        if (isset($options[$key])) {
          $options[$key] = $label;
        }
      }
    }
    $form['#entity_builders'][] = [self::class, 'buildEntity'];
    $form['#validate'][] = [self::class, 'validate'];
  }

  /**
   * Maps the combined channel every time core rebuilds the submitted entity.
 */
  public static function buildEntity(string $entity_type, NodeInterface $node, array &$form, FormStateInterface $state): void {
    $channel = $state->getValue('reg_tender_channel');
    if (!in_array($channel, ['reg_notice', 'umucyo', 'external'], TRUE)) {
      return;
    }
    $url = trim((string) $state->getValue('reg_tender_external_url'));
    $original = $node->isNew() ? NULL : $node->getOriginal();
    // Hidden legacy metadata is retained even if a forged POST includes it.
    if ($original) {
      foreach ($node->getFieldDefinitions() as $name => $definition) {
        if (str_starts_with($name, 'field_') && !in_array($name, self::FIELDS, TRUE)) {
          $node->set($name, $original->get($name)->getValue());
        }
      }
    }
    if (!$original || self::channel($original) !== $channel) {
      $node->set('field_reg_publication_channel', $channel === 'umucyo' ? 'umucyo' : 'reg');
      $node->set('field_reg_submission_method', match ($channel) {'umucyo' => 'umucyo', 'external' => 'external', default => 'offline'
      });
    }
    $field = match ($channel) {'umucyo' => 'field_reg_umucyo_url', 'external' => 'field_reg_submission_url', default => 'field_reg_external_url'
    };
    $node->set($field, $url === '' ? [] : ['uri' => $url]);
  }

  /**
   * Validates submitted values before core saves the tender.
   */
  public static function validate(array &$form, FormStateInterface $state): void {
    $node = $state->getFormObject()->buildEntity($form, $state);
    $channel = $state->getValue('reg_tender_channel');
    if (!in_array($channel, ['reg_notice', 'umucyo', 'external'], TRUE)) {
      $state->setErrorByName('reg_tender_channel', t('Choose a publication/submission channel.'));
      return;
    }
    $url = trim((string) $state->getValue('reg_tender_external_url'));
    if ($channel === 'umucyo' && !TenderSubmissionPolicy::umucyoUrl($url)) {
      $state->setErrorByName('reg_tender_external_url', t('Enter the approved tender-specific HTTPS UMUCYO URL.'));
    }
    if ($channel === 'external' && !self::externalUrl($url)) {
      $state->setErrorByName('reg_tender_external_url', t('Enter a valid approved external URL.'));
    }
    try {
      self::presave($node);
    }
    catch (EntityStorageException $e) {
      $state->setErrorByName(TenderPublicationValidation::invalid($node) ? 'field_reg_tender_status' : 'reg_tender_channel', $e->getMessage());
    }
  }

}
