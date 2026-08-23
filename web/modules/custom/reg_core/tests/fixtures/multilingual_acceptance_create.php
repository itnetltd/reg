<?php

/**
 * @file
 * Adds clearly tagged, temporary bilingual acceptance translations.
 */

use Drupal\node\NodeInterface;

$state = \Drupal::state();
if ($state->get('reg_core.multilingual_acceptance')) {
  throw new RuntimeException('Multilingual acceptance fixtures already exist.');
}

$branch = $state->get('reg_core.branch_acceptance') ?: [];
$public_information = $state->get('reg_core.public_information_acceptance') ?: [];
$sports = $state->get('reg_core.sports_acceptance') ?: [];
if (empty($branch['kigali']) || empty($public_information['current_tender']) || empty($sports['team']) || empty($sports['news'])) {
  throw new RuntimeException('Create the branch, public-information, and sports acceptance fixtures first.');
}

$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$translated_nodes = [];
$translate = static function (int $nid, array $values) use ($node_storage, &$translated_nodes): NodeInterface {
  $node = $node_storage->load($nid);
  if (!$node instanceof NodeInterface || !$node->isPublished()) {
    throw new RuntimeException(sprintf('Published fixture node %d was not found.', $nid));
  }
  if ($node->hasTranslation('rw')) {
    $node->removeTranslation('rw');
  }
  $translation = $node->addTranslation('rw', $values);
  $translation->setPublished(TRUE);
  if ($translation->hasField('moderation_state')) {
    $translation->set('moderation_state', 'published');
  }
  $translation->save();
  $translated_nodes[] = $nid;
  return $translation;
};

$branch_translation = $translate((int) $branch['kigali'], [
  'field_reg_address' => '[IKIGERAGEZO] Aderesi y’ishami rya Kigali',
  'field_reg_opening_hours' => '[IKIGERAGEZO] Ku wa Mbere kugeza ku wa Gatanu, 08:00-17:00',
  'field_reg_public_notes' => '[IKIGERAGEZO] Amakuru y’ishami agenewe gusa igerageza ry’indimi.',
]);

$tender_translation = $translate((int) $public_information['current_tender'], [
  'title' => '[IKIGERAGEZO] Isoko ry’ibikoresho by’amashanyarazi',
  'field_reg_summary' => '[IKIGERAGEZO] Incamake y’isoko ikoreshwa gusa mu kugenzura indimi.',
  'field_reg_description' => '[IKIGERAGEZO] Ibisobanuro rusange by’isoko nta makuru y’ibanga arimo.',
]);

$team_translation = $translate((int) $sports['team'], [
  'field_reg_description' => '[IKIGERAGEZO] Ibisobanuro by’ikipe byemeza ko inyandiko ihinduka ariko izina ry’ikipe ntirihinduke.',
]);
$news_translation = $translate((int) $sports['news'], [
  'title' => '[IKIGERAGEZO] Ikipe yitegura umukino ukurikiraho',
  'field_reg_summary' => '[IKIGERAGEZO] Incamake y’amakuru ya siporo.',
  'body' => ['value' => '[IKIGERAGEZO] Inkuru ya siporo ikoreshwa gusa mu kugenzura indimi.', 'format' => 'restricted_html'],
]);

// Temporary interface translations prove the route UI switches language. They
// are explicitly acceptance-only and are removed by the cleanup fixture.
$interface = [
  'Language selection' => '[IKIGERAGEZO] Guhitamo ururimi',
  'Customer Services' => '[IKIGERAGEZO] Serivisi z’abakiriya',
  'Branch Locator' => '[IKIGERAGEZO] Shaka ishami',
  'Find a branch' => '[IKIGERAGEZO] Shaka ishami',
  'Frequently Asked Questions' => '[IKIGERAGEZO] Ibibazo bikunze kubazwa',
  'Bill Estimator' => '[IKIGERAGEZO] Kubara fagitire',
  'Tenders' => '[IKIGERAGEZO] Amasoko',
  'REG Sports' => '[IKIGERAGEZO] Siporo ya REG',
  'Search' => '[IKIGERAGEZO] Shakisha',
  'Contact Us' => '[IKIGERAGEZO] Twandikire',
];
$locale_storage = \Drupal::service('locale.storage');
$source_ids = [];
foreach ($interface as $source => $translated) {
  $source_string = $locale_storage->findString(['source' => $source, 'context' => '']);
  if (!$source_string) {
    $locale_storage->createString([
      'source' => $source,
      'context' => '',
      'version' => \Drupal::VERSION,
    ])->save();
    $source_string = $locale_storage->findString(['source' => $source, 'context' => '']);
  }
  if (!$source_string || !$source_string->getId()) {
    throw new RuntimeException(sprintf('Could not register interface source string: %s', $source));
  }
  $source_ids[] = (int) $source_string->getId();
  \Drupal::database()->merge('locales_target')
    ->keys([
      'lid' => $source_string->getId(),
      'language' => 'rw',
    ])
    ->fields([
      'translation' => $translated,
      'customized' => LOCALE_CUSTOMIZED,
    ])
    ->execute();
}

$fixtures = [
  'nodes' => $translated_nodes,
  'sources' => $source_ids,
  'branch' => (int) $branch_translation->id(),
  'tender' => (int) $tender_translation->id(),
  'team' => (int) $team_translation->id(),
  'news' => (int) $news_translation->id(),
];
$state->set('reg_core.multilingual_acceptance', $fixtures);
\Drupal::service('cache_tags.invalidator')->invalidateTags(['rendered', 'locale', 'node_list']);
print json_encode($fixtures, JSON_PRETTY_PRINT) . PHP_EOL;
