<?php

/**
 * @file
 * Local DDEV-only procurement management acceptance. Unique fixtures always removed. */

use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Form\FormState;
use Drupal\reg_core\Form\ProcurementPlanAdminForm;
use Drupal\reg_core\Form\ProcurementPlanApproveForm;
use Drupal\reg_core\Procurement\ProcurementPlanSetup;
use Symfony\Component\HttpFoundation\Request;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  throw new RuntimeException('Local DDEV only.');
}
$assert = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$nodes = [];
$users = [];
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(User::load(1));
$storage = \Drupal::entityTypeManager()->getStorage('node');
$token = substr(hash('sha256', random_bytes(16)), 0, 10);
$planning_config = \Drupal::configFactory()->getEditable('reg_core.settings');
$prior_planning_flag = $planning_config->get('public_information.procurement_plan_integration');
$planning_config->set('public_information.procurement_plan_integration', TRUE)->save();
$tender_count = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->count()->execute();
$save = static function (array $values) use (&$nodes) {
  $n = Node::create($values);
  $n->save();
  $nodes[] = $n->id();
  return $n;
};
try {
  $assert(Role::load('reg_procurement_approver')->hasPermission('approve reg procurement plans'), 'Approver permission missing.');
  $assert(!Role::load('reg_procurement_officer')->hasPermission('approve reg procurement plans'), 'Editor can approve.');
  foreach (['reg_procurement_officer', 'reg_procurement_approver'] as $role) {
    $u = User::create(['name' => 'qa-procurement-' . $role . '-' . $token, 'mail' => $token . '-' . $role . '@example.invalid', 'status' => 1, 'roles' => [$role]]);
    $u->save();
    $users[$role] = $u;
  }
  $plan = $save(['type' => 'reg_procurement_plan', 'title' => 'QA PLAN ' . $token, 'field_reg_financial_year' => '2025/2026', 'field_reg_entity' => 'eucl', 'field_reg_plan_version' => 'QA-v1', 'status' => 0, 'moderation_state' => 'draft']);
  $values = ['type' => 'reg_procurement_plan_item', 'title' => 'QA ACTIVITY ' . $token, 'field_reg_procurement_plan_ref' => $plan->id(), 'field_reg_entity' => 'eucl', 'field_reg_plan_reference' => 'QA-1', 'field_reg_department' => 'QA Engineering', 'field_reg_proc_category_key' => 'goods', 'field_reg_procurement_method' => 'open_competitive', 'field_reg_source_id' => 'QA-' . $token, 'field_reg_source_row' => 7, 'status' => 0, 'moderation_state' => 'draft'];
  $item = $save($values);
  $assert(count($item->validate()) === 0, 'Plan item validation failed: ' . (string) $item->validate());
  $storage->resetCache([$item->id()]);
  $item = $storage->load($item->id());
  $assert($item->get('field_reg_estimated_budget')->isEmpty() && $item->get('field_reg_fy_budget')->isEmpty(), 'Missing budgets became zero.');
  $item->set('field_reg_estimated_budget', '0.00')->save();
  $storage->resetCache([$item->id()]);
  $item = $storage->load($item->id());
  $assert(!$item->get('field_reg_estimated_budget')->isEmpty() && (float) $item->get('field_reg_estimated_budget')->value === 0.0, 'Explicit zero did not survive.');
  $item->set('field_reg_estimated_budget', NULL)->setTitle('QA ACTIVITY UPDATED ' . $token)->save();
  $assert($item->get('field_reg_source_id')->value === 'QA-' . $token, 'Stable source key changed with title.');
  $assert($item->get('field_reg_estimated_budget')->isEmpty(), 'Cleared budget became zero.');
  $duplicate = Node::create($values);
  $denied = FALSE;
  try {
    $duplicate->save();
  }
  catch (EntityStorageException $e) {
    $denied = TRUE;
  }
  $assert($denied, 'Duplicate plan-scoped import key accepted.');
  $item->set('field_reg_evaluation_start', '2026-02-10')->set('field_reg_evaluation_end', '2026-02-09');
  $assert(isset(reg_core_procurement_errors($item)['field_reg_evaluation_end']), 'Inverted evaluation dates accepted.');
  $item->set('field_reg_evaluation_start', NULL)->set('field_reg_evaluation_end', NULL);
  $assert($storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->count()->execute() === $tender_count, 'Saving plan items created a tender.');
  // Explicit QA tender fixtures, never a side effect of importing/saving an item.
  $plan->set('field_reg_proc_plan_status', 'approved')->save();
  $item->set('field_reg_review_status', 'verified')->save();
  $tenders = [];
  foreach ([1, 2] as $index) {
    $t = $save(['type' => 'reg_tender', 'title' => 'QA TENDER ' . $token . ' ' . $index, 'field_reg_reference' => 'QA-' . $token . '-' . $index, 'field_reg_proc_plan_item' => $item->id(), 'field_reg_publication_channel' => 'reg', 'field_reg_submission_method' => 'offline', 'status' => 0, 'moderation_state' => 'draft']);
    $tenders[] = $t->id();
  }
  $plan->set('field_reg_proc_plan_status', 'draft')->save();
  $item->set('field_reg_review_status', 'needs_review')->save();
  $item->set('field_reg_related_tender', $tenders)->save();
  $storage->resetCache([$item->id()]);
  $item = $storage->loadRevision($storage->getLatestRevisionId($item->id()));
  $assert($item->get('field_reg_related_tender')->count() === 2, 'Multiple related tenders not retained.');
  $assert((int) $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_tender')->count()->execute() === (int) $tender_count + 2, 'Item update created extra tenders.');
  $anonymous = new AnonymousUserSession();
  $assert(!$plan->get('field_reg_private_documents')->access('view', $anonymous), 'Private source field public.');
  $assert(!$item->get('field_reg_estimated_budget')->access('view', $anonymous), 'Private budget exposed.');
  $switcher->switchTo($users['reg_procurement_officer']);
  try {
    $assert($plan->access('update'), 'Officer cannot edit plan.');
    $selector = \Drupal::service('plugin.manager.entity_reference_selection')->getSelectionHandler($item->getFieldDefinition('field_reg_procurement_plan_ref'));
    $assert(count($selector->validateReferenceableEntities([$plan->id()])) === 1, 'Editor cannot reference an editable draft plan.');
    $assert(!ProcurementPlanApproveForm::access($plan, \Drupal::currentUser())->isAllowed(), 'Officer can approve.');
    $plan->set('field_reg_proc_plan_status', 'approved');
    $assert(isset(reg_core_procurement_errors($plan)['field_reg_proc_plan_status']), 'Server approval guard missing.');
    $plan->set('field_reg_proc_plan_status', 'draft');
    $item->set('field_reg_review_status', 'verified');
    $assert(isset(reg_core_procurement_errors($item)['field_reg_review_status']), 'Officer can verify item.');
    $item->set('field_reg_review_status', 'needs_review');
    $stack = \Drupal::service('request_stack');
    foreach ([['year' => '2025/2026', 'entity' => 'eucl', 'department' => 'Engineering', 'method' => 'open_competitive', 'review' => 'needs_review'], ['year' => '2099/2100']] as $index => $query) {
      $stack->push(Request::create('/admin/content/procurement-plan-items', 'GET', $query));
      try {
        $f = new ProcurementPlanAdminForm();
        $state = new FormState();
        $built = $f->buildForm([], $state, 'reg_procurement_plan_item');
        $assert(count($built['records']['#rows']) === ($index === 0 ? 1 : 0), 'Admin filters failed.');
      } finally {$stack->pop();
      }
    }
  } finally {$switcher->switchBack();
  }
  $switcher->switchTo($users['reg_procurement_approver']);
  try {
    $assert(ProcurementPlanApproveForm::access($plan, \Drupal::currentUser())->isAllowed(), 'Approver access denied.');
    $form = new ProcurementPlanApproveForm();
    $state = new FormState();
    $built = $form->buildForm([], $state, $plan);
    $state->setValue('approval_date', '2026-01-10');
    $form->submitForm($built, $state);
  } finally {$switcher->switchBack();
  }
  $plan = $storage->loadRevision($storage->getLatestRevisionId($plan->id()));
  $assert($plan->get('field_reg_proc_plan_status')->value === 'approved' && !$plan->isPublished(), 'Approval did not remain separate from publication.');
  $assert(reg_core_file_download('private://procurement-plans/source/qa.pdf') === [], 'Authorized private file hook failed.');
  $switcher->switchTo($anonymous);
  try {
    $assert(reg_core_file_download('private://procurement-plans/source/qa.pdf') === -1, 'Anonymous source download not denied.');
  }
  finally {$switcher->switchBack();
  }
  $config = \Drupal::service('config.storage');
  $snapshot = static function () use ($config) {
    $all = [];
    foreach ($config->listAll() as $name) {
      $all[$name] = $config->read($name);
    } return $all;
  };
  $before = $snapshot();
  ProcurementPlanSetup::apply();
  $assert($before === $snapshot(), 'Setup rerun changed configuration.');
  print "PASS: reusable types, nullable/zero budgets, stable unique keys, dates, multi-tender links, no tender creation, private access, role separation, five filters, approve-without-publish, idempotent setup.\n";
} finally {
  foreach (array_reverse($nodes) as $nid) {
    $storage->load($nid)?->delete();
  }
  foreach ($users as $user) {
    $user->delete();
  }
  if ($prior_planning_flag === NULL) $planning_config->clear('public_information.procurement_plan_integration');
  else $planning_config->set('public_information.procurement_plan_integration', $prior_planning_flag);
  $planning_config->save();
  $switcher->switchBack();
}
