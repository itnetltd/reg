<?php

/**
 * @file
 * DDEV-only publication validation regression; owned fixtures are removed.
 */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Form\FormState;
use Drupal\node\Entity\Node;
use Drupal\reg_core\Procurement\SimpleTenderWorkflow;
use Drupal\reg_core\Procurement\TenderPublicationValidation;
use Drupal\reg_core\Procurement\TenderSubmissionPolicy;
use Drupal\user\Entity\User;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  throw new RuntimeException('DDEV only.');
}
$assert = static function (bool $ok, string $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$assert(!TenderSubmissionPolicy::plansEnabled() && !TenderSubmissionPolicy::onlineEnabled(), 'Feature flags must remain OFF.');
$switch = \Drupal::service('account_switcher');
$switch->switchTo(User::load(1));
$nodes = [];
try {
  $terms = \Drupal::entityQuery('taxonomy_term')->accessCheck(FALSE)->condition('vid', 'reg_procurement_category')->range(0, 1)->execute();
  $assert((bool) $terms, 'An existing procurement category is required.');
  $base = [
    'type' => 'reg_tender',
    'title' => 'QA publication constraint ' . bin2hex(random_bytes(6)),
    'field_reg_reference' => 'QA-PUBLICATION-CONSTRAINT',
    'field_reg_entity' => 'eucl',
    'field_reg_tender_category' => reset($terms),
    'field_reg_description' => ['value' => 'QA publication regression.', 'format' => 'plain_text'],
    'field_reg_issue_date' => '2026-01-01T09:00:00',
    'field_reg_closing_date' => '2030-01-01T17:00:00',
    'field_reg_publication_channel' => 'reg',
    'field_reg_submission_method' => 'offline',
    'field_reg_tender_status' => 'preparing',
    'moderation_state' => 'draft',
    'status' => 0,
  ];
  $node = Node::create($base);
  $assert($node->validate()->count() === 0, 'New unpublished Draft must validate.');
  $new_publish = clone $node;
  $new_publish->set('moderation_state', 'published');
  $assert(str_contains((string) $new_publish->validate(), TenderPublicationValidation::MESSAGE), 'New Published + Draft must return a lifecycle violation.');
  $node->save();
  $nodes[] = $node->id();
  $node->set('moderation_state', 'needs_review')->save();
  $candidate = clone $node;
  $candidate->set('moderation_state', 'published');
  $errors = $candidate->validate();
  $assert(str_contains((string) $errors, TenderPublicationValidation::MESSAGE), 'Publishing Draft must fail entity validation before presave.');
  $assert(str_contains((string) $errors, 'field_reg_tender_status'), 'Violation must identify the visible lifecycle field.');
  $blocked = FALSE;
  try {
    SimpleTenderWorkflow::presave($candidate);
  }
  catch (EntityStorageException $e) {
    $blocked = $e->getMessage() === TenderPublicationValidation::MESSAGE;
  }
  $assert($blocked, 'Unvalidated programmatic saves must retain an integrity guard.');
  $form = [];
  $state = (new FormState())->set('entity', $node)->setValue('new_state', 'published');
  TenderPublicationValidation::alterQuickForm($form, $state);
  $assert(isset($form['reg_tender_edit']['link']), 'Draft quick form must offer Edit Tender.');
  TenderPublicationValidation::validateQuickForm($form, $state);
  $assert($state->hasAnyErrors(), 'Quick publication must reject lifecycle Draft.');
  $assert($node->get('moderation_state')->value === 'needs_review', 'Failed validation mutated the stored/form entity.');
  $state->clearErrors();
  foreach (['active', 'closed', 'evaluation', 'awarded', 'cancelled'] as $lifecycle) {
    $candidate = clone $node;
    $candidate->set('field_reg_tender_status', $lifecycle)->set('moderation_state', 'published');
    $assert(!TenderPublicationValidation::invalid($candidate), 'Explicit published lifecycle rejected: ' . $lifecycle);
    $assert($candidate->validate()->count() === 0, 'Valid entity publication rejected: ' . $lifecycle);
    $state = (new FormState())->set('entity', $candidate)->setValue('new_state', 'published');
    TenderPublicationValidation::validateQuickForm($form, $state);
    $assert(!$state->hasAnyErrors(), 'Valid quick publication rejected: ' . $lifecycle);
  }
  $node->set('field_reg_tender_status', 'closed')->set('field_reg_closing_date', '2026-02-01T17:00:00')->set('moderation_state', 'published')->save();
  $item = \Drupal::service('reg_core.public_information_repository')->find('reg_tender', (int) $node->id());
  $assert($item && !$item['is_open'] && !$item['action']['enabled'], 'Expired publication must not enable bidding.');
  $assert($node->get('field_reg_proc_plan_item')->isEmpty(), 'Publication fabricated a plan relationship.');
  echo "PASS publication entity constraint; field error path; Draft save; all five published lifecycles; quick validation/edit link; programmatic guard; no entity mutation; plan-free publication; expired CTA protection.\n";
}
finally {
  foreach ($nodes as $id) {
    Node::load($id)?->delete();
  }
  $switch->switchBack();
}
