<?php

declare(strict_types=1);

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\content_translation\ContentTranslationManagerInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\user\Entity\Role;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\workflows\Entity\Workflow;

$assert = static function (bool $condition, string $message): void {
  if (!$condition) throw new RuntimeException($message);
};

$required = ['field_reg_announcement_ref', 'field_reg_announcement_date', 'field_reg_outage_type', 'field_reg_network_element', 'field_reg_outage_status', 'field_reg_outage_segments', 'field_reg_public_summary', 'field_reg_safety_message', 'field_reg_customer_message', 'field_reg_official_documents', 'field_reg_featured', 'field_reg_show_public_alert', 'field_reg_priority', 'field_reg_outage_source', 'field_reg_status_note', 'field_reg_actual_restoration', 'field_reg_restored_early'];
foreach ($required as $field) $assert(FieldConfig::loadByName('node', 'reg_outage', $field) !== NULL, "Missing outage field $field.");
$segment_field = FieldConfig::loadByName('node', 'reg_outage', 'field_reg_outage_segments');
$assert($segment_field?->getFieldStorageDefinition()->getType() === 'reg_outage_segment', 'Schedule windows do not use the repeatable outage segment field.');
$assert($segment_field?->getFieldStorageDefinition()->getCardinality() === -1, 'Schedule windows must be unlimited.');
$document_field = FieldConfig::loadByName('node', 'reg_outage', 'field_reg_official_documents');
$assert(($document_field?->getSetting('handler_settings')['target_bundles']['document'] ?? '') === 'document', 'Official documents are not restricted to document Media.');
$form = EntityFormDisplay::load('node.reg_outage.default');
$assert(($form?->getComponent('field_reg_outage_segments')['type'] ?? '') === 'reg_outage_segment_widget', 'Outage window editor is not active.');
$edit_build = \Drupal::service('entity.form_builder')->getForm(Node::create(['type' => 'reg_outage']), 'default');
$edit_html = (string) \Drupal::service('renderer')->renderRoot($edit_build);
$assert(str_contains($edit_html, 'Add outage window') && str_contains($edit_html, 'Window 1'), 'The editor does not expose the clear repeatable outage-window controls.');
$workflow = Workflow::load('reg_customer_service_editorial');
$assert($workflow?->getTypePlugin()->appliesToEntityTypeAndBundle('node', 'reg_outage') === TRUE, 'Outages are not covered by the editorial workflow.');
$assert(\Drupal::service('content_translation.manager')->isEnabled('node', 'reg_outage'), 'Outage announcements are not translation-enabled.');
$outage_editor = Role::load('reg_outage_editor');
$outage_approver = Role::load('reg_content_approver');
$translator = Role::load('reg_translator');
$assert($outage_editor?->hasPermission('create reg_outage content') && $outage_editor->hasPermission('use reg_customer_service_editorial transition submit_for_review'), 'Outage editor authoring/workflow permissions are incomplete.');
$assert($outage_editor->hasPermission('create document media') && $outage_editor->hasPermission('create terms in reg_sector'), 'Outage editor document/geography permissions are incomplete.');
$assert($outage_approver?->hasPermission('use reg_customer_service_editorial transition publish'), 'Outage approver cannot publish reviewed notices.');
$assert($translator?->hasPermission('translate reg_outage node'), 'Translator cannot translate outage notices.');

$terms = [];
foreach (['reg_district', 'reg_sector'] as $vid) foreach (\Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vid) as $term) $terms[$vid][$term->name] = (int) $term->tid;
$now = \Drupal::time()->getRequestTime();
$date = static fn(int $offset): string => gmdate('Y-m-d\\TH:i:s', $now + $offset);
$created = [];
$make = static function (string $reference, string $title, string $status, int $start, int $end, string $feeder, string $area, bool $alert = FALSE) use (&$created, $terms, $date): NodeInterface {
  $node = Node::create(['type' => 'reg_outage', 'langcode' => 'en', 'uid' => 1, 'title' => $title, 'field_reg_announcement_ref' => $reference, 'field_reg_announcement_date' => substr($date($start), 0, 10), 'field_reg_outage_type' => 'planned_maintenance', 'field_reg_reason' => 'Acceptance maintenance work', 'field_reg_network_element' => $feeder, 'field_reg_outage_status' => $status, 'field_reg_outage_segments' => [['start' => $date($start), 'end' => $date($end), 'feeder' => $feeder, 'districts' => (string) $terms['reg_district']['Gasabo'], 'sectors' => (string) $terms['reg_sector']['Kacyiru'], 'affected_area' => $area]], 'field_reg_public_summary' => 'Acceptance outage summary.', 'field_reg_outage_source' => 'manual', 'field_reg_featured' => 1, 'field_reg_show_public_alert' => $alert, 'field_reg_priority' => 90, 'status' => 1, 'moderation_state' => 'published']);
  $node->save();
  $created[] = (int) $node->id();
  return $node;
};

try {
  $ongoing = $make('REG-ACCEPT-ONGOING', 'Acceptance Ongoing Outage', 'scheduled', -1800, 1800, 'Acceptance Kacyiru feeder', 'parts of Kacyiru');
  $planned = $make('REG-ACCEPT-PLANNED', 'Acceptance Planned Outage', 'scheduled', 86400, 90000, 'Acceptance Kigali Nord', 'Kacyiru and Gasabo', TRUE);
  $make('REG-ACCEPT-CANCELLED', 'Acceptance Cancelled Outage', 'cancelled', 172800, 176400, 'Cancelled feeder', 'Kacyiru');
  $make('REG-ACCEPT-POSTPONED', 'Acceptance Postponed Outage', 'postponed', 259200, 262800, 'Postponed feeder', 'Kacyiru');

  $repository = \Drupal::service(\Drupal\reg_core\Outage\OutageRepositoryInterface::class);
  $repository->refresh();
  $current = $repository->getOutages(['mode' => 'current'])->outages;
  $future = $repository->getOutages(['mode' => 'planned'])->outages;
  $assert(in_array('notice-' . $ongoing->id(), array_column($current, 'outage_id'), TRUE), 'Time-aware current status failed.');
  $assert(in_array('notice-' . $planned->id(), array_column($future, 'outage_id'), TRUE), 'Future planned status failed.');
  $assert(!in_array('cancelled', array_column($future, 'status'), TRUE) && !in_array('postponed', array_column($future, 'status'), TRUE), 'Cancelled or postponed outage leaked into planned results.');
  foreach (['Kacyiru', 'Gasabo', 'Kigali Nord', 'UTEXRWA', 'Kinyinya'] as $search) $assert($repository->getOutages(['search' => $search])->outages !== [], "Search failed for $search.");

  $history = (string) \Drupal::httpClient()->get('http://localhost/outages/history')->getBody();
  $assert(str_contains($history, 'Birembo Substation Maintenance') && str_contains($history, '3 scheduled interruption windows'), 'Historical multi-window card failed.');
  $birembo_ids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('type', 'reg_outage')->condition('field_reg_announcement_ref', 'REG-HIST-BIREMBO-2025-11-28')->range(0, 1)->execute();
  $detail = (string) \Drupal::httpClient()->get('http://localhost/outages/notice-' . reset($birembo_ids))->getBody();
  foreach (['09:00', '11:00', 'Kibagabaga-Nyarutarama', 'parts of Remera and Kimironko', 'Safety notice', 'Customer support'] as $text) $assert(str_contains($detail, $text), "Multi-window detail is missing $text.");
  $homepage = (string) \Drupal::httpClient()->get('http://localhost/')->getBody();
  $assert(!str_contains($homepage, 'official outage and GIS APIs') && !str_contains($homepage, 'structured Drupal content or an approved source system'), 'Homepage exposes implementation language.');
  $assert(str_contains($homepage, 'Acceptance Kacyiru feeder'), 'Homepage Service Status did not show the current manual outage.');
  $alerts = \Drupal::service(\Drupal\reg_core\Alert\PublicAlertRepositoryInterface::class)->current(TRUE)['alerts'];
  $assert((bool) array_filter($alerts, static fn(array $alert): bool => ($alert['id'] ?? '') === 'outage-' . $planned->id()), 'Outage promotion did not reach the Public Alert system.');
}
finally {
  if ($created) \Drupal::entityTypeManager()->getStorage('node')->delete(\Drupal::entityTypeManager()->getStorage('node')->loadMultiple($created));
  \Drupal::service('cache_tags.invalidator')->invalidateTags(['reg_core:outages', 'node_list:reg_outage', 'node_list:reg_public_alert']);
}

print json_encode(['model' => 'reg_outage', 'repeatable_windows' => TRUE, 'historical_samples' => 3, 'search_terms' => ['Kacyiru', 'Gasabo', 'Kigali Nord', 'UTEXRWA', 'Kinyinya'], 'admin_path' => '/admin/content/outages', 'source' => 'manual'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
