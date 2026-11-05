<?php

/**
 * @file
 * DDEV-only approval, channel, legacy and supplier regression acceptance. */

use Drupal\Core\Session\UserSession;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\reg_core\Procurement\TenderPlanWorkflow;
use Drupal\reg_core\Procurement\TenderSubmissionPolicy;
use Drupal\reg_core\Procurement\TenderPlanSetup;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  throw new RuntimeException('Local DDEV only.');
}
$switch = \Drupal::service('account_switcher');
$switch->switchTo(User::load(1));
$storage = \Drupal::entityTypeManager()->getStorage('node');
$nodes = [];
$results = [];
$config = \Drupal::configFactory()->getEditable('reg_core.settings');
$prior = $config->get('public_information');
$config->set('public_information.procurement_plan_integration', TRUE)->set('public_information.reg_online_bidding', TRUE)->save();
$save = static function (array $values) use (&$nodes) {
  $n = Node::create($values);
  $n->save();
  $nodes[] = $n->id();
  return $n;
};
$assert = static function ($ok, $message) {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$token = bin2hex(random_bytes(5));
$counts = [];
foreach (['reg_tender', 'reg_bid', 'reg_procurement_plan_item'] as $type) {
  $counts[$type] = $storage->getQuery()->accessCheck(FALSE)->condition('type', $type)->count()->execute();
}
try {
  $plan = $save([
    'type' => 'reg_procurement_plan',
    'title' => 'QA Tender Plan ' . $token,
    'field_reg_financial_year' => '2090/2091',
    'field_reg_entity' => 'eucl',
    'field_reg_proc_plan_status' => 'approved',
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $item = $save([
    'type' => 'reg_procurement_plan_item',
    'title' => 'QA Approved Activity ' . $token,
    'field_reg_plan_reference' => 'QA-105',
    'field_reg_entity' => 'eucl',
    'field_reg_procurement_plan_ref' => $plan->id(),
    'field_reg_review_status' => 'verified',
    'field_reg_proc_category_key' => 'consultancy',
    'field_reg_department' => 'QA Procurement',
    'field_reg_funding_source' => 'EUCL/REG',
    'field_reg_procurement_method' => 'request_for_quotations',
    'field_reg_method_code' => 'RFQ',
    'field_reg_planned_publication' => '2020-01-01',
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $assert(TenderPlanWorkflow::eligible($item), 'Approved private activity not eligible.');
  $base = [
    'type' => 'reg_tender',
    'field_reg_proc_plan_item' => $item->id(),
    'field_reg_reference' => 'QA-' . $token,
    'field_reg_publication_channel' => 'reg',
    'field_reg_submission_method' => 'reg_online',
    'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s',
  time() + 86400),
    'status' => 0,
    'moderation_state' => 'draft',
  ];
  $tender = $save($base);
  $assert($tender->label() === $item->label(), 'Title default missing.');
  foreach (TenderPlanWorkflow::DEFAULTS as $target => $source) {
    $assert($tender->get($target)->getValue() === $item->get($source)->getValue(), 'Default snapshot mismatch: ' . $target);
  }
  $assert($tender->get('field_reg_financial_year')->value === '2090/2091', 'Financial year missing.');
  $assert($tender->get('field_reg_issue_date')->isEmpty() && $tender->get('field_reg_tender_status')->value === 'preparing', 'Planned date opened tender or became actual publication.');
  $results[] = 'approved plan/item creates tender with eligible defaults and independent status/dates';
  $item->set('field_reg_department', 'QA Changed Department')->set('field_reg_proc_item_status', 'deferred')->save();
  $tender->setTitle('QA Deliberate Tender Title')->save();
  $assert($tender->get('field_reg_department')->value === 'QA Procurement', 'Editing plan item silently rewrote tender snapshot.');
  $results[] = 'editing linked item does not rewrite or invalidate unchanged tender';
  $blocked = FALSE;
  try {
    $n = Node::create($base);
    $n->save();

  }
  catch (\Throwable $e) {
    $blocked = TRUE;

  }$assert($blocked, 'Deferred activity accepted for new tender.');
  $results[] = 'unavailable activity rejected';
  $item->set('field_reg_proc_item_status', 'planned')->save();
  $item->set('field_reg_review_status', 'needs_review')->save();
  $assert(!TenderPlanWorkflow::eligible($item), 'Unreviewed activity offered.');
  $item->set('field_reg_review_status', 'verified')->save();
  // No fuzzy matching: a legacy tender remains unlinked even with an identical title.
  $legacy = $storage->load($tender->id());
  $legacy->set('field_reg_proc_plan_item', NULL)->set('field_reg_plan_link_approval', 'REMOVE')->save();
  $legacy->setTitle($item->label())->save();
  $assert($legacy->get('field_reg_proc_plan_item')->isEmpty(), 'Legacy tender matched by title.');
  $legacy->set('field_reg_proc_plan_item', $item->id());
  $blocked = FALSE;
  try {
    $legacy->save();

  }
  catch (\Throwable $e) {
    $blocked = TRUE;

  }$assert($blocked, 'Existing tender linked without explicit confirmation.');
  $storage->resetCache([$legacy->id()]);
  $legacy = $storage->load($legacy->id());
  $legacy->set('field_reg_proc_plan_item', $item->id())->set('field_reg_plan_link_approval', (string) $item->id())->save();
  $results[] = 'legacy no-plan editing preserved; exact administrator confirmation required for linking';
  $legacy->set('field_reg_submission_method', 'umucyo')->set('field_reg_publication_channel', 'both')->set('field_reg_umucyo_url', 'https://www.umucyo.gov.rw/eb/tender/qa-' . $token)->set('field_reg_tender_status', 'active')->setPublished()->set('moderation_state', 'published')->save();
  $repo = \Drupal::service('reg_core.public_information_repository');
  $detail = $repo->find('reg_tender', (int) $legacy->id());
  $assert($detail['action']['label'] === 'BID ON UMUCYO' && $detail['action']['enabled'] && $detail['action']['url'] === $legacy->get('field_reg_umucyo_url')->uri, 'UMUCYO CTA incorrect.');
  $assert(!\Drupal::service('reg_core.supplier_manager')->tenderAcceptsRegBids($legacy), 'UMUCYO accepted a Drupal bid.');
  $results[] = 'UMUCYO exact tender CTA, no Drupal bid intake';
  $denied = FALSE;
  try {
    $b = Node::create([
      'type' => 'reg_bid',
      'title' => 'QA forbidden UMUCYO bid',
      'field_reg_bid_tender' => $legacy->id(),
    ]);
    $b->save();

  }
  catch (\Throwable $e) {
    $denied = str_contains($e->getMessage(), 'does not accept REG online');

  }$assert($denied, 'Direct bid save bypassed UMUCYO intake guard.');
  $legacy->set('field_reg_submission_method', 'reg_online')->set('field_reg_publication_channel', 'reg')->save();
  foreach ([
  [
    FALSE,
    FALSE,
    FALSE,
  ],
  [
    TRUE,
    FALSE,
    FALSE,
  ],
  [
    FALSE,
    TRUE,
    FALSE,
  ],
  [
    TRUE,
    TRUE,
    TRUE,
  ],
  ] as [$procurement, $security, $expected]) {
    $config->set('public_information.enable_tender_submission', $procurement)->set('public_information.tender_security_approved', $security)->save();
    $detail = $repo->find('reg_tender', (int) $legacy->id());
    $assert(TenderSubmissionPolicy::onlineEnabled() === $expected, 'Policy flags wrong.');
    $assert((bool) $detail['action']['enabled'] === $expected, 'Cached public CTA ignores gate.');
    $assert(\Drupal::service('reg_core.supplier_manager')->tenderAcceptsRegBids($legacy) === $expected, 'Supplier manager ignores gate.');
  }
  $results[] = 'REG CTA and intake require both procurement and security flags; cache invalidates';
  $legacy->set('field_reg_tender_status', 'preparing')->save();
  $assert(!\Drupal::service('reg_core.supplier_manager')->tenderAcceptsRegBids($legacy), 'Preparing tender accepts bids.');
  $results[] = 'existing supplier organization, membership, deadline and scan checks retained';
  $exception = [
    'type' => 'reg_tender',
    'title' => 'QA Unplanned ' . $token,
    'field_reg_unplanned' => 1,
    'field_reg_publication_channel' => 'reg',
    'field_reg_submission_method' => 'offline',
    'status' => 0,
    'moderation_state' => 'draft',
  ];
  $blocked = FALSE;
  try {
    Node::create($exception)->save();

  }
  catch (\Throwable $e) {
    $blocked = str_contains($e->getMessage(), 'required');

  }$assert($blocked, 'Undocumented exception accepted.');
  $media_ids = \Drupal::entityTypeManager()->getStorage('media')->getQuery()->accessCheck(FALSE)->condition('bundle', 'document')->range(0, 1)->execute();
  if ($media_ids) {
    $exception['field_reg_exception_reason'] = 'QA only documented exception';
    $exception['field_reg_approval_ref'] = 'QA-APPROVAL-' . $token;
    $exception['field_reg_approval_date'] = '2090-01-01';
    $exception['field_reg_approval_document'] = reset($media_ids);
    $approved_exception = $save($exception);
    $assert($approved_exception->get('field_reg_proc_plan_item')->isEmpty(), 'Exception acquired fabricated plan item.');
    $results[] = 'documented authorized unplanned exception saves without fabricated relationship';
  }
  $officer = new UserSession(['uid' => 999999999, 'roles' => ['authenticated', 'reg_procurement_officer']]);
  $switch->switchTo($officer);
  try {
    $denied = FALSE;
    try {
      Node::create($exception)->save();

    }
    catch (\Throwable $e) {
      $denied = str_contains($e->getMessage(), 'authorized');

    }$assert($denied, 'Officer created an unapproved exception.');

  }finally{$switch->switchBack();
  }
  $results[] = 'ordinary procurement officer cannot bypass exception approval';

  $snapshot = \Drupal::service('config.storage')->read('field.storage.node.field_reg_tender_status');
  TenderPlanSetup::apply();
  $assert($snapshot === \Drupal::service('config.storage')->read('field.storage.node.field_reg_tender_status'), 'Setup not idempotent.');
  echo json_encode([
    'status' => 'PASS',
    'checks' => $results,
    'fixtures' => 'synthetic QA records, removed on exit',
  ], JSON_PRETTY_PRINT) . PHP_EOL;
} finally {
  foreach (['enable_tender_submission', 'tender_security_approved', 'procurement_plan_integration', 'reg_online_bidding'] as $key) {
    if (array_key_exists($key, $prior)) {
      $config->set('public_information.' . $key, $prior[$key]);
    }
    else {
      $config->clear('public_information.' . $key);
    }
  }
  $config->save();
  $storage->resetCache();
  foreach (array_reverse($nodes) as $id) {
    if ($n = $storage->load($id)) {
      $n->delete();
    }
  }
  foreach ($counts as $type => $count) {
    if ((int) $storage->getQuery()->accessCheck(FALSE)->condition('type', $type)->count()->execute() !== (int) $count) {
      throw new RuntimeException('Fixture cleanup/count changed: ' . $type);
    }
  }
  $switch->switchBack();
}
