<?php

/**
 * @file
 * Local DDEV-only notice publishing QA; no real records are modified. */

use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\node\Entity\Node;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\user\Entity\User;
use Drupal\reg_core\Procurement\TenderSubmissionPolicy;

if (getenv('IS_DDEV_PROJECT') !== 'true') {
  throw new RuntimeException('DDEV only.');
}
$assert = static function ($ok, $message) {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$switch = \Drupal::service('account_switcher');
$switch->switchTo(User::load(1));
$storage = \Drupal::entityTypeManager()->getStorage('node');
$nodes = [];
$media = NULL;
$file = NULL;
$checks = [];
$token = bin2hex(random_bytes(5));
$config = \Drupal::configFactory()->getEditable('reg_core.settings');
$prior = $config->get('public_information');
$save = static function ($data) use (&$nodes) {
  $n = Node::create($data);
  $n->save();
  $nodes[] = $n->id();
  return $n;

};
try {
  $assert(!TenderSubmissionPolicy::plansEnabled()&&!TenderSubmissionPolicy::onlineEnabled(), 'Feature flags must start OFF.');
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->getQuery()->accessCheck(FALSE)->condition('vid', 'reg_procurement_category')->range(0, 1)->execute();
  $assert((bool) $terms, 'Existing procurement categories required.');
  $base = ['type' => 'reg_tender', 'title' => 'QA Notice ' . $token, 'field_reg_reference' => 'QA-NOTICE-' . $token, 'field_reg_entity' => 'eucl', 'field_reg_tender_category' => reset($terms), 'field_reg_description' => ['value' => 'QA standalone tender description.', 'format' => 'plain_text'], 'field_reg_issue_date' => gmdate('Y-m-d\TH:i:s', time() - 3600), 'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', time() + 86400), 'field_reg_publication_channel' => 'reg', 'field_reg_submission_method' => 'offline', 'field_reg_tender_status' => 'preparing', 'status' => 0, 'moderation_state' => 'draft'];
  $draft = $save($base);
  $assert($draft->get('field_reg_proc_plan_item')->isEmpty()&&$draft->get('field_reg_tender_documents')->isEmpty(), 'Draft requires invented plan/documents.');
  $assert($draft->validate()->count() === 0, 'Standalone entity validation: ' . (string) $draft->validate());
  $checks[] = 'draft saves and validates without plan, year, exception or documents';
  $repo = \Drupal::service('reg_core.public_information_repository');
  $assert($repo->find('reg_tender', (int) $draft->id()) === NULL, 'Draft became public.');
  $draft->set('field_reg_tender_status', 'active')->set('moderation_state', 'published')->setPublished()->save();
  $item = $repo->find('reg_tender', (int) $draft->id());
  $assert($item && !$item['action']['enabled'] && !$item['supplier_ctas'], 'Notice exposes local bid action.');
  $assert(in_array((int) $draft->id(), array_column($repo->search(['reg_tender'], ['query' => $token]), 'id'), TRUE), 'Published notice absent from archive.');
  $assert(in_array((int) $draft->id(), array_column($repo->homepageTenders(3), 'id'), TRUE), 'Latest published notice absent from homepage.');
  $checks[] = 'notice publishes and appears in archive/homepage with no online bid CTA';
  $umucyo = $save(array_replace($base, ['title' => 'QA UMUCYO ' . $token, 'field_reg_submission_method' => 'umucyo', 'field_reg_publication_channel' => 'umucyo', 'field_reg_umucyo_url' => 'https://www.umucyo.gov.rw/eb/tender/qa-' . $token, 'field_reg_tender_status' => 'active', 'moderation_state' => 'published', 'status' => 1]));
  $item = $repo->find('reg_tender', (int) $umucyo->id());
  $assert($item['action']['enabled']&&$item['action']['label'] === 'BID ON UMUCYO'&&$item['action']['url'] === $umucyo->get('field_reg_umucyo_url')->uri, 'UMUCYO CTA incorrect.');
  $bad = FALSE;
  try {
    Node::create(array_replace($base, ['field_reg_submission_method' => 'umucyo']))->save();

  }
  catch (\Throwable $e) {
    $bad = str_contains($e->getMessage(), 'UMUCYO');

  }$assert($bad, 'Missing UMUCYO URL accepted.');
  $checks[] = 'UMUCYO tender-specific CTA works; missing URL rejected';
  $external = $save(array_replace($base, ['title' => 'QA External ' . $token, 'field_reg_submission_method' => 'external', 'field_reg_submission_url' => 'https://example.invalid/qa-tender-' . $token, 'field_reg_bid_instructions' => ['value' => 'QA only approved external instructions', 'format' => 'plain_text'], 'field_reg_tender_status' => 'active', 'moderation_state' => 'published', 'status' => 1]));
  $assert($repo->find('reg_tender', (int) $external->id())['action']['label'] === 'OPEN APPROVED PLATFORM', 'External CTA missing.');
  $checks[] = 'configured approved external channel supported without local intake';
  $expired = $save(array_replace($base, ['title' => 'QA Expired ' . $token, 'field_reg_issue_date' => gmdate('Y-m-d\TH:i:s', time() - 86400), 'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', time() - 3600), 'field_reg_submission_method' => 'umucyo', 'field_reg_umucyo_url' => 'https://www.umucyo.gov.rw/eb/tender/qa-expired-' . $token, 'field_reg_tender_status' => 'active', 'moderation_state' => 'published', 'status' => 1]));
  $item = $repo->find('reg_tender', (int) $expired->id());
  $assert(!$item['is_open']&&!$item['action']['enabled']&&$item['status_key'] === 'closed', 'Expired tender appears open.');
  $assert(in_array((int) $expired->id(), array_column($repo->search(['reg_tender'], ['status' => 'closed', 'query' => $token]), 'id'), TRUE), 'Expired notice missing from Closed filter.');
  $assert(!in_array((int) $expired->id(), array_column($repo->search(['reg_tender'], ['status' => 'active', 'query' => $token]), 'id'), TRUE), 'Expired notice in Open filter.');
  $checks[] = 'expired notices display/filter Closed without changing stored lifecycle';
  $evaluation = $save(array_replace($base, ['title' => 'QA Evaluation ' . $token, 'field_reg_tender_status' => 'evaluation', 'moderation_state' => 'published', 'status' => 1]));
  $assert(in_array((int) $evaluation->id(), array_column($repo->search(['reg_tender'], ['status' => 'evaluation', 'query' => $token]), 'id'), TRUE), 'Evaluation filter missing.');
  // A minimal public PDF fixture reuses the existing protected Media architecture.
  $directory = 'public://qa-tender-notices';
  \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
  $pdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
  $file = \Drupal::service('file.repository')->writeData($pdf, $directory . '/qa-' . $token . '.pdf', FileExists::Rename);
  $file->setMimeType('application/pdf');
  $file->save();
  $media = Media::create(['bundle' => 'document', 'name' => 'QA Tender PDF ' . $token, 'status' => 1, 'field_media_document' => $file->id(), 'field_reg_public_asset' => 1, 'field_reg_asset_scope' => ['procurement']]);
  $media->save();
  $draft->set('field_reg_tender_documents', $media->id())->save();
  $item = $repo->find('reg_tender', (int) $draft->id());
  $assert((bool) $item['primary_document']['url'], 'Published PDF missing from public tender.');
  $checks[] = 'optional PDF uses existing Media and public download URL';
  $config->set('public_information.enable_tender_submission', TRUE)->set('public_information.tender_security_approved', TRUE)->save();
  $assert(!TenderSubmissionPolicy::onlineEnabled(), 'Old approval flags bypass new OFF gate.');
  $assert(!\Drupal::service('reg_core.supplier_manager')->tenderAcceptsRegBids($draft), 'Supplier intake enabled.');
  $checks[] = 'REG intake remains OFF even with historical approval flags';
  $links = \Drupal::service('plugin.manager.menu.link');
  foreach (['reg_core.procurement_plans_admin', 'reg_core.procurement_plan_items_admin', 'reg_core.procurement_import'] as $id) {
    $assert(!$links->getDefinition($id)['enabled'], 'Plan navigation still enabled.');
  }$checks[] = 'plan navigation hidden; routes and permission definitions retained';
  echo json_encode(['status' => 'PASS', 'checks' => $checks, 'fixture_count' => count($nodes)], JSON_PRETTY_PRINT) . PHP_EOL;
}finally{
  foreach (['enable_tender_submission', 'tender_security_approved'] as $key) {
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
  }if ($media) {
    $media->delete();
  }if ($file&&($f = File::load($file->id()))) {
    $f->delete();
  }$switch->switchBack();
}
