<?php

/**
 * @file
 * Reversible live acceptance checks for homepage hero and alert rotation data.
 *
 * Run with: drush php:script web/modules/custom/reg_core/tests/fixtures/homepage_rotators_acceptance.php
 */

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

$storage = \Drupal::entityTypeManager()->getStorage('node');
$hero_repository = \Drupal::service('reg_core.homepage_repository');
$alert_repository = \Drupal::service('reg_core.public_alert_repository');
$invalidator = \Drupal::service('cache_tags.invalidator');
$now = \Drupal::time()->getRequestTime();
$created = [];
$original_active = [];

$assert = static function (bool $condition, string $label): void {
  if (!$condition) {
    throw new RuntimeException('FAIL: ' . $label);
  }
  print 'PASS: ' . $label . PHP_EOL;
};
$refresh = static function () use ($invalidator): void {
  $invalidator->invalidateTags([
    'node_list',
    'node_list:reg_homepage_hero',
    'node_list:reg_public_alert',
  ]);
};
$heroes = static function () use ($hero_repository, $refresh): array {
  $refresh();
  return $hero_repository->heroes()['heroes'] ?? [];
};
$alerts = static function (bool $homepage = TRUE) use ($alert_repository, $refresh): array {
  $refresh();
  return $alert_repository->current($homepage)['alerts'] ?? [];
};
$create = static function (string $type, array $overrides = []) use (&$created, $now): NodeInterface {
  $defaults = $type === 'reg_homepage_hero'
    ? [
      'type' => $type,
      'langcode' => 'en',
      'uid' => 1,
      'title' => 'Rotator acceptance hero',
      'field_reg_subtitle' => 'Temporary automated carousel validation.',
      'field_reg_order' => 0,
      'field_reg_active' => 1,
      'status' => 1,
      'moderation_state' => 'published',
    ]
    : [
      'type' => $type,
      'langcode' => 'en',
      'uid' => 1,
      'title' => 'Rotator acceptance alert',
      'field_reg_alert_message' => 'Temporary automated alert rotation validation.',
      'field_reg_alert_type' => 'general',
      'field_reg_alert_severity' => 'information',
      'field_reg_alert_start' => gmdate('Y-m-d\\TH:i:s', $now - 300),
      'field_reg_alert_priority' => 0,
      'field_reg_alert_location' => 'homepage',
      'field_reg_active' => 1,
      'status' => 1,
      'moderation_state' => 'published',
    ];
  $node = Node::create(array_replace($defaults, $overrides));
  $node->save();
  $created[] = $node;
  return $node;
};

try {
  $existing_ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', ['reg_homepage_hero', 'reg_public_alert'], 'IN')
    ->execute();
  foreach ($storage->loadMultiple($existing_ids) as $node) {
    if ($node instanceof NodeInterface && $node->hasField('field_reg_active')) {
      $original_active[$node->id()] = (int) $node->get('field_reg_active')->value;
      $node->set('field_reg_active', 0)->save();
    }
  }

  $assert(count($heroes()) === 0, 'Hero: zero eligible items returns an empty rotation');

  $hero_nodes = [];
  for ($index = 1; $index <= 5; $index++) {
    $hero_nodes[] = $create('reg_homepage_hero', [
      'title' => "Rotator hero $index",
      'field_reg_order' => $index,
      'created' => $now + $index,
    ]);
    $assert(count($heroes()) === $index, "Hero: $index eligible item(s) returned");
  }
  $assert(array_column($heroes(), 'title') === [
    'Rotator hero 1',
    'Rotator hero 2',
    'Rotator hero 3',
    'Rotator hero 4',
    'Rotator hero 5',
  ], 'Hero: five items follow ascending editorial weight');

  $future_hero = $create('reg_homepage_hero', [
    'title' => 'Future rotator hero',
    'field_reg_hero_start' => gmdate('Y-m-d\\TH:i:s', $now + 3600),
    'field_reg_order' => -100,
  ]);
  $expired_hero = $create('reg_homepage_hero', [
    'title' => 'Expired rotator hero',
    'field_reg_hero_end' => gmdate('Y-m-d\\TH:i:s', $now - 60),
    'field_reg_order' => -100,
  ]);
  $draft_hero = $create('reg_homepage_hero', [
    'title' => 'Draft rotator hero',
    'field_reg_order' => -100,
    'status' => 0,
    'moderation_state' => 'draft',
  ]);
  $rw_hero = $create('reg_homepage_hero', [
    'langcode' => 'rw',
    'title' => 'Intwari y\'igerageza',
    'field_reg_order' => -100,
  ]);
  $excluded_hero_ids = array_map('intval', [
    $future_hero->id(),
    $expired_hero->id(),
    $draft_hero->id(),
    $rw_hero->id(),
  ]);
  $assert(!array_intersect($excluded_hero_ids, array_column($heroes(), 'id')), 'Hero: future, expired, unpublished, and other-language items are excluded');

  foreach ($hero_nodes as $node) {
    $node->set('field_reg_active', 0)->save();
  }
  $assert(count($alerts()) === 0, 'Alert: zero eligible items returns an empty stream');

  $information = $create('reg_public_alert', [
    'title' => 'Information rotator alert',
    'field_reg_alert_severity' => 'information',
    'field_reg_alert_priority' => 500,
    'field_reg_alert_cta_label' => 'Read notice',
    'field_reg_alert_cta_url' => 'internal:/contact',
  ]);
  $assert(count($alerts()) === 1, 'Alert: one eligible item is returned');
  $critical_old = $create('reg_public_alert', [
    'title' => 'Older critical rotator alert',
    'field_reg_alert_severity' => 'critical',
    'field_reg_alert_priority' => 10,
    'field_reg_alert_start' => gmdate('Y-m-d\\TH:i:s', $now - 600),
  ]);
  $assert(count($alerts()) === 2 && ($alerts()[0]['id'] ?? 0) === (int) $critical_old->id(), 'Alert: Critical sorts before higher-priority Information');
  $critical_new = $create('reg_public_alert', [
    'title' => 'Newer critical rotator alert',
    'field_reg_alert_severity' => 'critical',
    'field_reg_alert_priority' => 10,
    'field_reg_alert_start' => gmdate('Y-m-d\\TH:i:s', $now - 60),
  ]);
  $ordered_alerts = $alerts();
  $assert(array_slice(array_column($ordered_alerts, 'id'), 0, 2) === [(int) $critical_new->id(), (int) $critical_old->id()], 'Alert: equally prioritized Critical items sort by newest start');
  $information_alert = NULL;
  foreach ($ordered_alerts as $candidate) {
    if (($candidate['id'] ?? 0) === (int) $information->id()) {
      $information_alert = $candidate;
      break;
    }
  }
  $assert(
    ($information_alert['cta_label'] ?? '') === 'Read notice'
      && str_ends_with((string) ($information_alert['cta_url'] ?? ''), '/contact'),
    'Alert: each item retains its own CTA',
  );

  $future_alert = $create('reg_public_alert', [
    'title' => 'Future rotator alert',
    'field_reg_alert_severity' => 'critical',
    'field_reg_alert_start' => gmdate('Y-m-d\\TH:i:s', $now + 3600),
  ]);
  $expired_alert = $create('reg_public_alert', [
    'title' => 'Expired rotator alert',
    'field_reg_alert_severity' => 'critical',
    'field_reg_alert_end' => gmdate('Y-m-d\\TH:i:s', $now - 60),
  ]);
  $excluded_alert_ids = [(int) $future_alert->id(), (int) $expired_alert->id()];
  $assert(!array_intersect($excluded_alert_ids, array_column($alerts(), 'id')), 'Alert: future and expired items are excluded');

  $sitewide = $create('reg_public_alert', [
    'title' => 'Sitewide rotator alert',
    'field_reg_alert_location' => 'sitewide',
    'field_reg_alert_severity' => 'warning',
  ]);
  $homepage_ids = array_column($alerts(TRUE), 'id');
  $interior_ids = array_column($alerts(FALSE), 'id');
  $assert(in_array((int) $sitewide->id(), $homepage_ids, TRUE) && in_array((int) $sitewide->id(), $interior_ids, TRUE), 'Alert: a sitewide item appears on homepage and interior scopes');
  $assert(in_array((int) $information->id(), $homepage_ids, TRUE) && !in_array((int) $information->id(), $interior_ids, TRUE), 'Alert: a homepage-only item is excluded from interior scope');

  $renderer = \Drupal::service('renderer');
  $hero_sample = [
    'id' => 'acceptance-hero',
    'title' => 'Rendered acceptance hero',
    'eyebrow' => '',
    'subtitle' => '',
    'desktop_image' => [],
    'mobile_image' => [],
    'primary_cta' => [],
    'secondary_cta' => [],
    'alignment' => 'left',
    'overlay' => 'medium',
    'text_theme' => 'dark_background',
  ];
  $static_hero_build = ['#theme' => 'reg_homepage_hero', '#heroes' => [$hero_sample]];
  $carousel_hero_build = ['#theme' => 'reg_homepage_hero', '#heroes' => [$hero_sample, array_replace($hero_sample, ['id' => 'acceptance-hero-2'])]];
  $static_hero = (string) $renderer->renderInIsolation($static_hero_build);
  $carousel_hero = (string) $renderer->renderInIsolation($carousel_hero_build);
  $assert(!str_contains($static_hero, 'data-reg-hero-carousel') && !str_contains($static_hero, 'data-reg-hero-pause'), 'Hero: one rendered item remains static without carousel controls');
  $assert(str_contains($carousel_hero, 'data-reg-hero-carousel') && str_contains($carousel_hero, 'data-reg-hero-prev') && str_contains($carousel_hero, 'data-reg-hero-indicator') && str_contains($carousel_hero, 'data-reg-hero-pause') && str_contains($carousel_hero, 'inert'), 'Hero: two rendered items automatically include accessible carousel controls');

  $alert_sample = $ordered_alerts[0];
  $static_alert_build = ['#theme' => 'reg_public_alert', '#alerts' => [$alert_sample]];
  $carousel_alert_build = ['#theme' => 'reg_public_alert', '#alerts' => array_slice($ordered_alerts, 0, 2)];
  $static_alert = (string) $renderer->renderInIsolation($static_alert_build);
  $carousel_alert = (string) $renderer->renderInIsolation($carousel_alert_build);
  $assert(!str_contains($static_alert, 'data-reg-alert-carousel') && !str_contains($static_alert, 'data-reg-alert-pause'), 'Alert: one rendered item remains static without carousel controls');
  $assert(str_contains($carousel_alert, 'data-reg-alert-carousel') && str_contains($carousel_alert, 'data-reg-alert-prev') && str_contains($carousel_alert, 'data-reg-alert-pause') && str_contains($carousel_alert, '12000'), 'Alert: multiple rendered items include controls and the Critical reading interval');
}
finally {
  foreach (array_reverse($created) as $node) {
    if (!$node->isNew()) {
      $node->delete();
    }
  }
  foreach ($storage->loadMultiple(array_keys($original_active)) as $node) {
    if ($node instanceof NodeInterface && isset($original_active[$node->id()])) {
      $node->set('field_reg_active', $original_active[$node->id()])->save();
    }
  }
  $refresh();
}

print 'Homepage rotator acceptance fixtures restored.' . PHP_EOL;
