<?php

/**
 * @file
 * Creates temporary Public Information Hub acceptance fixtures.
 */

use Drupal\Core\File\FileExists;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;

$state = \Drupal::state();
if ($state->get('reg_core.public_information_acceptance')) {
  throw new RuntimeException('Public information acceptance fixtures already exist. Run the cleanup script first.');
}

$media_type = \Drupal::entityTypeManager()->getStorage('media_type')->load('document');
$source_field = (string) $media_type->getSource()->getConfiguration()['source_field'];
$directory = 'public://reg-acceptance';
\Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY);
$media_ids = [];
$file_ids = [];

$create_document = static function (string $name, string $filename, string $version) use ($directory, $source_field, &$media_ids, &$file_ids): Media {
  $file = \Drupal::service('file.repository')->writeData("%PDF-1.4\n% REG acceptance fixture\n", $directory . '/' . $filename, FileExists::Replace);
  $file->setPermanent();
  $file->save();
  $media = Media::create([
    'bundle' => 'document',
    'name' => $name,
    'status' => 1,
    $source_field => ['target_id' => $file->id(), 'description' => $name],
    'field_reg_document_date' => gmdate('Y-m-d'),
    'field_reg_document_version' => $version,
  ]);
  $media->save();
  $media_ids[] = (int) $media->id();
  $file_ids[] = (int) $file->id();
  return $media;
};

$tender_document = $create_document('Acceptance tender document', 'acceptance-tender.pdf', '1.0');
$job_document = $create_document('Acceptance recruitment notice', 'acceptance-job.pdf', 'Final');
$publication_document = $create_document('Acceptance annual report', 'acceptance-publication.pdf', '2026');

$terms = [];
foreach (['reg_procurement_category', 'reg_publication_type'] as $vocabulary) {
  $tree = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocabulary);
  $terms[$vocabulary] = (int) reset($tree)->tid;
}

$now = \Drupal::time()->getRequestTime();
$node_ids = [];
$create_node = static function (array $values) use (&$node_ids): Node {
  $node = Node::create($values + [
    'uid' => 1,
    'status' => 1,
    'moderation_state' => 'published',
  ]);
  $node->save();
  $node_ids[] = (int) $node->id();
  return $node;
};

$current_tender = $create_node([
  'type' => 'reg_tender',
  'title' => '[REG PI acceptance] Current grid equipment tender',
  'field_reg_summary' => 'Temporary current tender used only for acceptance testing.',
  'field_reg_reference' => 'REG-ACC-T-001',
  'field_reg_entity' => 'reg',
  'field_reg_tender_category' => $terms['reg_procurement_category'],
  'field_reg_procurement_method' => 'open_competitive',
  'field_reg_tender_status' => 'active',
  'field_reg_issue_date' => gmdate('Y-m-d\TH:i:s', $now - 86400),
  'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', $now + (3 * 86400)),
  'field_reg_description' => 'Public tender details with no bidder or supplier-sensitive information.',
  'field_reg_tender_documents' => [['target_id' => $tender_document->id()]],
  'field_reg_document_language' => 'en',
]);
$create_node([
  'type' => 'reg_tender',
  'title' => '[REG PI acceptance] Awarded transformer tender',
  'field_reg_summary' => 'Temporary awarded tender.',
  'field_reg_reference' => 'REG-ACC-T-002',
  'field_reg_entity' => 'eucl',
  'field_reg_tender_category' => $terms['reg_procurement_category'],
  'field_reg_tender_status' => 'awarded',
  'field_reg_issue_date' => gmdate('Y-m-d\TH:i:s', $now - (30 * 86400)),
  'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', $now - (10 * 86400)),
  'field_reg_tender_documents' => [['target_id' => $tender_document->id()]],
  'field_reg_award_documents' => [['target_id' => $tender_document->id()]],
  'field_reg_document_language' => 'en',
]);
$create_node([
  'type' => 'reg_tender',
  'title' => '[REG PI acceptance] Archived works tender',
  'field_reg_summary' => 'Temporary archived tender.',
  'field_reg_reference' => 'REG-ACC-T-003',
  'field_reg_entity' => 'edcl',
  'field_reg_tender_category' => $terms['reg_procurement_category'],
  'field_reg_tender_status' => 'archived',
  'field_reg_issue_date' => gmdate('Y-m-d\TH:i:s', $now - (60 * 86400)),
  'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', $now - (40 * 86400)),
  'field_reg_tender_documents' => [['target_id' => $tender_document->id()]],
  'field_reg_document_language' => 'en',
]);

$current_job = $create_node([
  'type' => 'reg_job',
  'title' => '[REG PI acceptance] Distribution engineer',
  'field_reg_summary' => 'Temporary current vacancy.',
  'field_reg_reference' => 'REG-ACC-J-001',
  'field_reg_entity' => 'eucl',
  'field_reg_department' => 'Network Operations',
  'field_reg_job_type' => 'contract',
  'field_reg_location' => 'Kigali',
  'field_reg_vacancies' => 2,
  'field_reg_posting_date' => gmdate('Y-m-d\TH:i:s', $now - 86400),
  'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', $now + (5 * 86400)),
  'field_reg_job_status' => 'active',
  'field_reg_job_documents' => [['target_id' => $job_document->id()]],
  'field_reg_document_language' => 'en',
]);
$create_node([
  'type' => 'reg_job',
  'title' => '[REG PI acceptance] Recruitment shortlist results',
  'field_reg_summary' => 'Temporary recruitment result.',
  'field_reg_reference' => 'REG-ACC-J-002',
  'field_reg_entity' => 'reg',
  'field_reg_posting_date' => gmdate('Y-m-d\TH:i:s', $now - (20 * 86400)),
  'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', $now - (10 * 86400)),
  'field_reg_job_status' => 'results',
  'field_reg_result_documents' => [['target_id' => $job_document->id()]],
  'field_reg_document_language' => 'en',
]);
$create_node([
  'type' => 'reg_job',
  'title' => '[REG PI acceptance] Archived finance vacancy',
  'field_reg_summary' => 'Temporary archived vacancy.',
  'field_reg_reference' => 'REG-ACC-J-003',
  'field_reg_entity' => 'edcl',
  'field_reg_posting_date' => gmdate('Y-m-d\TH:i:s', $now - (60 * 86400)),
  'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', $now - (30 * 86400)),
  'field_reg_job_status' => 'archived',
  'field_reg_job_documents' => [['target_id' => $job_document->id()]],
  'field_reg_document_language' => 'en',
]);

$publication = $create_node([
  'type' => 'reg_publication',
  'title' => '[REG PI acceptance] Annual performance report',
  'field_reg_summary' => 'Temporary approved report used only for acceptance testing.',
  'field_reg_entity' => 'reg',
  'field_reg_publication_type' => $terms['reg_publication_type'],
  'field_reg_publication_date' => gmdate('Y-m-d\TH:i:s', $now - 86400),
  'field_reg_description' => 'Approved publication description.',
  'field_reg_publication_document' => [['target_id' => $publication_document->id()]],
  'field_reg_document_language' => 'en',
]);

$draft = Node::create([
  'type' => 'reg_publication',
  'title' => '[REG PI acceptance] Unpublished confidential draft',
  'uid' => 1,
  'status' => 0,
  'moderation_state' => 'draft',
  'field_reg_entity' => 'reg',
  'field_reg_publication_type' => $terms['reg_publication_type'],
  'field_reg_publication_date' => gmdate('Y-m-d\TH:i:s', $now),
  'field_reg_publication_document' => [['target_id' => $publication_document->id()]],
]);
$draft->save();
$node_ids[] = (int) $draft->id();

$state->set('reg_core.public_information_acceptance', [
  'nodes' => $node_ids,
  'media' => $media_ids,
  'files' => $file_ids,
  'current_tender' => (int) $current_tender->id(),
  'current_job' => (int) $current_job->id(),
  'publication' => (int) $publication->id(),
  'category' => $terms['reg_procurement_category'],
]);

print json_encode($state->get('reg_core.public_information_acceptance'), JSON_PRETTY_PRINT) . PHP_EOL;
