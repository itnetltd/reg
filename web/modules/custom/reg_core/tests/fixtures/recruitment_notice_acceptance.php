<?php

/** Standalone job lifecycle acceptance; deletes only this run's disposable fixture. */
use Drupal\Core\Form\FormState;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\reg_core\Recruitment\RecruitmentPlanWorkflow;

$assert = static function (bool $ok, string $message): void {
  if (!$ok) throw new RuntimeException($message);
  echo "PASS: $message\n";
};
$config = \Drupal::configFactory()->getEditable('reg_core.settings');
$assert(!RecruitmentPlanWorkflow::enabled(), 'Recruitment plan integration defaults OFF');
$before_config = $config->getRawData();
$storage = \Drupal::entityTypeManager()->getStorage('node');
$bundles = ['reg_job', 'reg_recruitment_plan', 'reg_recruitment_plan_item', 'reg_job_application', 'reg_applicant_profile', 'reg_tender', 'reg_procurement_plan', 'reg_procurement_plan_item', 'reg_bid'];
$existing = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundles, 'IN')->execute();
$snapshot = static function (array $ids) use ($storage): string {
  $storage->resetCache($ids);
  $data = [];
  foreach ($storage->loadMultiple($ids) as $id => $node) $data[$id] = $node->toArray();
  ksort($data);
  return hash('sha256', serialize($data));
};
$before = $snapshot($existing);
$repo = \Drupal::service('reg_core.public_information_repository');
$anonymous = new AnonymousUserSession();
$job = NULL;
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal\user\Entity\User::load(1));
try {
  $assert(RecruitmentPlanWorkflow::access()->isNeutral(), 'Plan management access disabled');
  foreach (['reg_core.recruitment_plans_admin', 'reg_core.recruitment_plan_items_admin'] as $route) {
    $assert(!\Drupal::service('access_manager')->checkNamedRoute($route, [], \Drupal::currentUser()), $route . ' denied while OFF');
  }
  $links = []; foreach (['reg_core.recruitment_plans_admin', 'reg_core.recruitment_plan_items_admin'] as $id) $links[$id] = ['enabled' => TRUE];
  reg_core_menu_links_discovered_alter($links);
  $assert(!$links['reg_core.recruitment_plans_admin']['enabled'] && !$links['reg_core.recruitment_plan_items_admin']['enabled'], 'Plan management navigation hidden');
  $token = bin2hex(random_bytes(5));
  $job = Node::create(['type' => 'reg_job', 'title' => 'Standalone recruitment acceptance ' . $token, 'langcode' => 'en', 'uid' => 1, 'field_reg_entity' => 'reg', 'field_reg_department' => 'ICT', 'field_reg_vacancies' => 1, 'field_reg_job_type' => 'contract', 'field_reg_reference' => 'LOCAL-TEST-' . $token, 'field_reg_description' => 'Disposable local acceptance vacancy.', 'field_reg_posting_date' => gmdate('Y-m-d\TH:i:s'), 'field_reg_closing_date' => gmdate('Y-m-d\TH:i:s', time() + 86400 * 30), 'field_reg_application_method' => 'external', 'field_reg_application_url' => ['uri' => 'https://example.org/jobs/' . $token], 'field_reg_job_status' => 'active', 'status' => 0, 'moderation_state' => 'draft']);
  $doc_ids = \Drupal::entityQuery('media')->accessCheck(FALSE)->condition('bundle', 'document')->range(0, 1)->execute();
  if ($doc_ids) $job->set('field_reg_job_documents', reset($doc_ids));
  $documents = $job->get('field_reg_job_documents')->getValue();
  $form_object = \Drupal::entityTypeManager()->getFormObject('node', 'default')->setEntity($job);
  $state = (new FormState())->setFormObject($form_object)->setValues(['field_reg_application_method' => [['value' => 'external']], 'field_reg_application_url' => [['uri' => 'https://example.org/jobs/' . $token]]]);
  $form = ['field_reg_entity' => ['#type' => 'select'], 'field_reg_department' => ['#type' => 'textfield']];
  foreach (RecruitmentPlanWorkflow::FIELDS as $field) $form[$field] = ['#type' => 'textfield'];
  reg_core_form_node_form_alter($form, $state);
  reg_core_plan_link_form_validate($form, $state);
  $assert(!$state->hasAnyErrors(), 'Job form accepts no plan or exceptional recruitment');
  foreach (RecruitmentPlanWorkflow::FIELDS as $field) $assert($form[$field]['#access'] === FALSE, $field . ' hidden while planning OFF');
  $assert(!isset($form['field_reg_entity']['#access']), 'Standalone company and job metadata stay editable');
  $violations = $job->validate();
  $assert(count($violations) === 0, 'Job entity validation accepts standalone vacancy: ' . (string) $violations);
  $job->save();
  $assert($job->get('field_reg_recruit_plan_item')->isEmpty(), 'Draft job saved without plan item');
  $job->set('moderation_state', 'published')->setPublished()->save();
  $assert($job->isPublished(), 'Job published without a plan');
  $switcher->switchTo($anonymous);
  try {
    $result = $repo->search(['reg_job'], ['query' => $token]);
    $assert(str_contains(json_encode($result), $token), 'Published vacancy included in public Jobs repository');
    $response = \Drupal::httpClient()->get('http://localhost/jobs?query=' . $token, ['headers' => ['Host' => 'reg-website.ddev.site'], 'timeout' => 240]);
    $assert(str_contains((string) $response->getBody(), $job->label()), 'Published vacancy appears on public /jobs page');
  }
  finally { $switcher->switchBack(); }
  $job->setTitle($job->label() . ' edited')->save();
  $assert($job->get('field_reg_job_documents')->getValue() === $documents && $job->get('field_reg_application_method')->value === 'external' && $job->get('field_reg_application_url')->uri === 'https://example.org/jobs/' . $token, 'Editing preserves application method, link and attachments');
  $job->set('field_reg_job_status', 'closed')->save();
  $closed = $repo->find('reg_job', (int) $job->id());
  $assert(!($closed['action']['enabled'] ?? TRUE), 'Closing vacancy disables application action');
  $job->set('moderation_state', 'unpublished')->setUnpublished()->save();
  $storage->resetCache([$job->id()]);
  $assert(!$storage->load($job->id())->isPublished(), 'Vacancy unpublished successfully');
  $assert(!$storage->load($job->id())->access('view', $anonymous), 'Unpublished vacancy is inaccessible to anonymous visitors');
  $workflow = \Drupal\workflows\Entity\Workflow::load('reg_public_information_editorial');
  $assert($workflow->getTypePlugin()->getState('archived')->isPublishedState(), 'Existing public archive behavior preserved');
  $assert(\Drupal\user\Entity\Role::load('reg_hr_approver')->hasPermission('use reg_public_information_editorial transition unpublish_vacancy'), 'HR Approver can unpublish vacancies');
  $assert(!\Drupal\user\Entity\Role::load('reg_procurement_approver')->hasPermission('use reg_public_information_editorial transition unpublish_vacancy'), 'Procurement permissions unchanged');
  // Verify the original plan requirement can be reactivated without data changes.
  $config->set('public_information.recruitment_plan_integration', TRUE)->save();
  $assert(RecruitmentPlanWorkflow::access()->isAllowed(), 'Plan management can be re-enabled');
  $rejected = FALSE;
  try { reg_core_apply_plan_defaults(Node::create(['type' => 'reg_job', 'title' => 'Unsaved negative check', 'field_reg_application_method' => 'instructions'])); }
  catch (\Drupal\Core\Entity\EntityStorageException) { $rejected = TRUE; }
  $assert($rejected, 'ON restores plan-or-approved-exception validation');
}
finally {
  \Drupal::service('cache_tags.invalidator')->resetChecksums();
  $config->setData($before_config)->save();
  if ($job && !$job->isNew()) $job->delete();
  $switcher->switchBack();
}
$assert($snapshot($existing) === $before, 'Existing jobs, plans, relationships, applications and procurement records unchanged');
$after_ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundles, 'IN')->execute();
$assert(count($after_ids) === count($existing), 'Only disposable test vacancy removed; existing counts preserved');
$assert(\Drupal::config('reg_core.settings')->getRawData() === $before_config, 'Settings restored, including all procurement feature flags');
echo "Recruitment acceptance complete.\n";