<?php

/**
 * @file
 * Reversible live acceptance checks for CMS-managed Public Alerts.
 *
 * Run with: drush php:script web/modules/custom/reg_core/tests/fixtures/public_alert_acceptance.php
 */

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

$storage = \Drupal::entityTypeManager()->getStorage('node');
$repository = \Drupal::service('reg_core.public_alert_repository');
$invalidator = \Drupal::service('cache_tags.invalidator');
$now = \Drupal::time()->getRequestTime();
$created = [];
$default = NULL;
$original = [];

$assert = static function (bool $condition, string $label): void {
  if (!$condition) {
    throw new RuntimeException('FAIL: ' . $label);
  }
  print 'PASS: ' . $label . PHP_EOL;
};
$refresh = static function () use ($invalidator): void {
  $invalidator->invalidateTags(['node_list', 'node_list:reg_public_alert']);
};
$current = static function (bool $homepage = TRUE) use ($repository, $refresh): ?array {
  $refresh();
  return $repository->current($homepage)['alert'] ?? NULL;
};
$create = static function (array $overrides) use (&$created, $now): NodeInterface {
  $values = array_replace([
    'type' => 'reg_public_alert',
    'langcode' => 'en',
    'uid' => 1,
    'title' => 'Acceptance test alert',
    'field_reg_alert_message' => 'Temporary automated Public Alert validation.',
    'field_reg_alert_type' => 'general',
    'field_reg_alert_severity' => 'information',
    'field_reg_alert_start' => gmdate('Y-m-d\\TH:i:s', $now - 300),
    'field_reg_alert_priority' => -100,
    'field_reg_alert_location' => 'homepage',
    'field_reg_active' => 1,
    'status' => 1,
    'moderation_state' => 'published',
  ], $overrides);
  $node = Node::create($values);
  $node->save();
  $created[] = $node;
  return $node;
};

try {
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', 'reg_public_alert')
    ->condition('uuid', '748eb19f-cc31-4d57-8ef0-b2cb803b95e4')
    ->range(0, 1)
    ->execute();
  $default = $ids ? $storage->load(reset($ids)) : NULL;
  $assert($default instanceof NodeInterface, 'A default Service Notice exists');
  $alert = $current();
  $assert(($alert['title'] ?? '') === 'Service Notice', 'A current Service Notice is selected for the homepage');

  $original = [
    'message' => $default->get('field_reg_alert_message')->value,
    'active' => $default->get('field_reg_active')->value,
  ];
  $default->set('field_reg_alert_message', 'Temporary acceptance edit');
  $default->save();
  $alert = $current();
  $assert(($alert['message'] ?? '') === 'Temporary acceptance edit', 'B an editor save changes the selected homepage message');
  $default->set('field_reg_alert_message', $original['message']);
  $default->save();

  $unpublished = $create([
    'title' => 'Unpublished acceptance alert',
    'field_reg_alert_priority' => 9999,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $assert(($current()['id'] ?? 0) !== (int) $unpublished->id(), 'C an unpublished alert is excluded');

  $future = $create([
    'title' => 'Future acceptance alert',
    'field_reg_alert_start' => gmdate('Y-m-d\\TH:i:s', $now + 3600),
    'field_reg_alert_priority' => 9999,
  ]);
  $assert(($current()['id'] ?? 0) !== (int) $future->id(), 'D a future alert is excluded before its start time');

  $expired = $create([
    'title' => 'Expired acceptance alert',
    'field_reg_alert_end' => gmdate('Y-m-d\\TH:i:s', $now - 60),
    'field_reg_alert_priority' => 9999,
  ]);
  $assert(($current()['id'] ?? 0) !== (int) $expired->id(), 'E an expired alert is excluded automatically');

  $high = $create([
    'title' => 'High priority acceptance alert',
    'field_reg_alert_severity' => 'critical',
    'field_reg_alert_priority' => 500,
  ]);
  $assert(($current()['id'] ?? 0) === (int) $high->id(), 'F a higher-priority eligible alert replaces the lower-priority alert');

  $without_cta = $create([
    'title' => 'No CTA acceptance alert',
    'field_reg_alert_severity' => 'critical',
    'field_reg_alert_priority' => 600,
  ]);
  $alert = $current();
  $assert(($alert['id'] ?? 0) === (int) $without_cta->id() && ($alert['cta_url'] ?? '') === '', 'G an alert without a CTA is valid');

  if (!$without_cta->hasTranslation('rw')) {
    $without_cta->addTranslation('rw', [
      'title' => 'Itangazo ry\'igerageza',
      'field_reg_alert_message' => 'Ubutumwa bw\'igerageza.',
      'field_reg_alert_cta_label' => '',
    ]);
    $without_cta->save();
  }
  $assert($without_cta->hasTranslation('rw'), 'H a Kinyarwanda translation can be saved on the alert');

  $default->set('field_reg_active', 0);
  $default->save();
  foreach ($created as $node) {
    if ($node->hasField('field_reg_active')) {
      $node->set('field_reg_active', 0);
      $node->save();
    }
  }
  $future->set('field_reg_active', 1);
  $future->save();
  $refresh();
  $scheduled_result = $repository->current(TRUE);
  $assert(
    empty($scheduled_result['alert'])
      && ($scheduled_result['max_age'] ?? 0) > 0
      && ($scheduled_result['max_age'] ?? 0) <= 3600,
    'D an empty strip retains a finite cache boundary for the next scheduled start',
  );
  $future->set('field_reg_active', 0);
  $future->save();
  $assert($current() === NULL, 'I no eligible active alerts returns no strip data');

  $sitewide = $create([
    'title' => 'Sitewide acceptance alert',
    'field_reg_alert_location' => 'sitewide',
    'field_reg_alert_severity' => 'critical',
    'field_reg_alert_priority' => 1,
  ]);
  $assert(($current(FALSE)['id'] ?? 0) === (int) $sitewide->id(), 'A sitewide critical alert is selected on interior pages');
}
finally {
  foreach (array_reverse($created) as $node) {
    if (!$node->isNew()) {
      $node->delete();
    }
  }
  if ($default instanceof NodeInterface && $original) {
    $default->set('field_reg_alert_message', $original['message']);
    $default->set('field_reg_active', $original['active']);
    $default->save();
  }
  $refresh();
}

print 'Public Alert acceptance fixtures restored.' . PHP_EOL;
