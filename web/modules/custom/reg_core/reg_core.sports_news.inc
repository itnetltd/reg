<?php

/**
 * @file
 * Single-source sports news classification, editorial UI and legacy migration.
 */

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\node\NodeInterface;

/** Returns IDs of the existing Sports category, never a second vocabulary. */
function reg_core_sports_news_category_ids(): array {
  $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'reg_news_category', 'name' => 'Sports']);
  return $terms ? array_keys($terms) : [0];
}

/** Tests the authoritative category, independent of legacy section metadata. */
function reg_core_is_sports_news(NodeInterface $node): bool {
  if ($node->bundle() !== 'reg_news' || !$node->hasField('field_reg_news_category_term')) return FALSE;
  foreach ($node->get('field_reg_news_category_term') as $item) {
    if (in_array((int) $item->target_id, reg_core_sports_news_category_ids())) return TRUE;
  }
  return FALSE;
}

/** Maintains compatibility metadata without requiring a second editor choice. */
function reg_core_sports_news_presave(NodeInterface $node): void {
  if ($node->hasField('field_reg_news_section')) {
    $node->set('field_reg_news_section', reg_core_is_sports_news($node) ? 'sports' : 'corporate');
  }
}

/** Shows optional existing sport/team references when Sports is selected. */
function reg_core_sports_news_form(array &$form): void {
  if (isset($form['field_reg_news_section'])) $form['field_reg_news_section']['#access'] = FALSE;
  foreach (['field_reg_team', 'field_reg_sports_sport', 'field_reg_related_players'] as $field) {
    if (!isset($form[$field])) continue;
    $conditions = [];
    foreach (reg_core_sports_news_category_ids() as $id) {
      if ($conditions) $conditions[] = 'or';
      $conditions[] = [':input[name="field_reg_news_category_term"]' => ['value' => (string) $id]];
    }
    $form[$field]['#states']['visible'] = $conditions;
  }
}

/** Implements hook_node_create_access(): legacy records remain editable. */
function reg_core_node_create_access(\Drupal\Core\Session\AccountInterface $account, array $context, $entity_bundle): AccessResult {
  return $entity_bundle === 'reg_sports_update' ? AccessResult::forbidden('Create News with Category = Sports instead.') : AccessResult::neutral();
}

/** Implements hook_node_insert(). */
function reg_core_node_insert(NodeInterface $node): void {
  if ($node->bundle() === 'reg_news') reg_core_news_ensure_alias($node);
}

/** Implements hook_node_update(). */
function reg_core_node_update(NodeInterface $node): void {
  if ($node->bundle() === 'reg_news') reg_core_news_ensure_alias($node);
}

/** Adds a stable canonical slug, preserving any editor-managed existing alias. */
function reg_core_news_ensure_alias(NodeInterface $node): void {
  $storage = \Drupal::entityTypeManager()->getStorage('path_alias');
  foreach ($node->getTranslationLanguages() as $langcode => $language) {
    $path = '/node/' . $node->id();
    if ($storage->loadByProperties(['path' => $path, 'langcode' => $langcode])) continue;
    $translation = $node->getTranslation($langcode);
    $slug = \Drupal::transliteration()->transliterate($translation->label(), $langcode);
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($slug)), '-');
    $alias = '/news/' . (substr($slug, 0, 180) ?: 'story');
    if ($storage->loadByProperties(['alias' => $alias, 'langcode' => $langcode])) $alias .= '-' . $node->id();
    $storage->create(['path' => $path, 'alias' => $alias, 'langcode' => $langcode])->save();
  }
}

/** Attaches existing reference storages and deprecates duplicate creation. */
function reg_core_configure_sports_news(): void {
  \Drupal::moduleHandler()->loadInclude('reg_core', 'install');
  $definitions = reg_core_field_definitions()['reg_sports_update'];
  foreach (['field_reg_team', 'field_reg_sports_sport', 'field_reg_related_players', 'field_reg_author_source'] as $name) {
    if (!\Drupal\field\Entity\FieldConfig::loadByName('node', 'reg_news', $name)) {
      reg_core_ensure_field('reg_news', $name, array_replace($definitions[$name], ['required' => FALSE]), -23);
    }
  }
  if (!\Drupal\field\Entity\FieldConfig::loadByName('node', 'reg_news', 'field_reg_legacy_sports_update')) {
    reg_core_ensure_field('reg_news', 'field_reg_legacy_sports_update', reg_core_node_reference_field('Archived sports source', 'reg_sports_update', -1, FALSE, FALSE), 99);
  }
  \Drupal::service('entity_field.manager')->clearCachedFieldDefinitions();
  $type = \Drupal\node\Entity\NodeType::load('reg_news');
  $type->set('name', 'News')->save();
  $legacy_type = \Drupal\node\Entity\NodeType::load('reg_sports_update');
  if ($legacy_type) $legacy_type->set('name', 'Sports update (legacy archive)')->set('description', 'Retained source records. Create News and select Category = Sports for all new stories.')->save();
  $form = EntityFormDisplay::load('node.reg_news.default');
  $form->setComponent('field_reg_team', ['type' => 'entity_reference_autocomplete', 'weight' => -23])
    ->setComponent('field_reg_sports_sport', ['type' => 'options_select', 'weight' => -23])
    ->setComponent('field_reg_related_players', ['type' => 'entity_reference_autocomplete', 'weight' => -22])
    ->removeComponent('field_reg_news_section')->removeComponent('field_reg_legacy_sports_update')->save();
  foreach (['default', 'full'] as $mode) {
    if ($view = EntityViewDisplay::load('node.reg_news.' . $mode)) {
      foreach (['field_reg_team', 'field_reg_sports_sport', 'field_reg_related_players', 'field_reg_author_source', 'field_reg_legacy_sports_update'] as $name) $view->removeComponent($name);
      $view->save();
    }
  }
  // The sports workflow intentionally keeps its general Archived state public.
  // Use a separate unpublished state only for consolidated legacy stories.
  if ($workflow = \Drupal\workflows\Entity\Workflow::load('reg_sports_editorial')) {
    $plugin = $workflow->getTypePlugin();
    $configuration = $plugin->getConfiguration();
    $configuration['states']['migrated_news'] = ['label' => 'Archived: migrated to News', 'published' => FALSE, 'default_revision' => TRUE, 'weight' => 30];
    $configuration['transitions']['migrate_news'] = ['label' => 'Archive migrated news', 'from' => array_keys($configuration['states']), 'to' => 'migrated_news', 'weight' => 30];
    $plugin->setConfiguration($configuration);
    $workflow->save();
  }
  foreach (\Drupal\user\Entity\Role::loadMultiple() as $role) {
    $role->revokePermission('create reg_sports_update content');
    if ($role->id() === 'reg_sports_editor') {
      foreach (['create reg_news content', 'edit any reg_news content', 'view reg_news revisions', 'use reg_news_editorial transition create_new_draft', 'use reg_news_editorial transition submit_for_review'] as $permission) $role->grantPermission($permission);
    }
    $role->save();
  }
}

/**
 * Migrates legacy stories once, matching text before reusing existing News.
 *
 * Source nodes and revisions are retained. The mapping is written before
 * archiving so an interrupted run can safely resume without a duplicate.
 */
function reg_core_migrate_sports_news(): array {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $map = \Drupal::state()->get('reg_core.sports_news_migration', []);
  $report = ['created' => [], 'reused' => [], 'archived' => []];
  $category = reg_core_sports_news_category_ids()[0];
  if (!$category) throw new \RuntimeException('The Sports news category is required.');
  $text = static fn(string $html): string => preg_replace('/[\s\x{00a0}]+/u', '', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_sports_update')->execute();
  foreach ($storage->loadMultiple($ids) as $source) {
    $target = isset($map[$source->id()]) ? $storage->load($map[$source->id()]) : NULL;
    if (!$target) {
      // Do not equate unrelated stories solely on their headline.
      $matches = $storage->loadByProperties(['type' => 'reg_news', 'title' => $source->label(), 'langcode' => $source->language()->getId()]);
      foreach ($matches as $candidate) {
        if ($text((string) $source->get('body')->value) !== '' && $text((string) $source->get('body')->value) === $text((string) $candidate->get('body')->value)) {
          $target = $candidate;
          $report['reused'][] = (int) $target->id();
          break;
        }
      }
      $new = !$target;
      if ($new) {
        $target = $storage->create(['type' => 'reg_news', 'title' => $source->label(), 'langcode' => $source->language()->getId(), 'uid' => $source->getOwnerId(), 'created' => $source->getCreatedTime(), 'status' => $source->isPublished()]);
        $target->set('moderation_state', $source->isPublished() ? 'published' : 'draft');
      }
      foreach ($source->getTranslationLanguages() as $langcode => $language) {
        $old = $source->getTranslation($langcode);
        $article = $target->hasTranslation($langcode) ? $target->getTranslation($langcode) : $target->addTranslation($langcode, ['title' => $old->label(), 'status' => $old->isPublished()]);
        foreach (['body' => 'body', 'field_reg_summary' => 'field_reg_summary', 'field_reg_publication_date' => 'field_reg_publication_date', 'field_reg_team' => 'field_reg_team', 'field_reg_sports_sport' => 'field_reg_sports_sport', 'field_reg_author_source' => 'field_reg_author_source', 'field_reg_related_players' => 'field_reg_related_players', 'field_reg_hero_image' => 'field_reg_featured_image', 'field_reg_external_url' => 'field_reg_external_url', 'field_reg_source_id' => 'field_reg_source_id'] as $from => $to) {
          if ($old->hasField($from) && $article->hasField($to) && $article->get($to)->isEmpty() && !$old->get($from)->isEmpty()) $article->set($to, $old->get($from)->getValue());
        }
        $article->set('field_reg_news_category_term', $category);
      }
      // Resolve only an exact existing team key; never fabricate a team.
      if ($target->get('field_reg_team')->isEmpty() && $source->hasField('field_reg_sport')) {
        $key = $source->get('field_reg_sport')->value;
        $teams = $storage->loadByProperties(['type' => 'reg_sports_team', 'field_reg_team_key' => $key === 'volleyball' ? 'volleyball_men' : $key]);
        if (count($teams) === 1) $target->set('field_reg_team', reset($teams)->id());
      }
      // Preserve an alternate legacy image alongside the canonical featured image.
      $image = (int) $source->get('field_reg_hero_image')->target_id;
      if ($image && $image !== (int) $target->get('field_reg_featured_image')->target_id) {
        $gallery_ids = array_column($target->get('field_reg_news_gallery')->getValue(), 'target_id');
        if (!in_array($image, $gallery_ids)) $target->get('field_reg_news_gallery')->appendItem(['target_id' => $image]);
      }
      if (!in_array($source->id(), array_column($target->get('field_reg_legacy_sports_update')->getValue(), 'target_id'))) {
        $target->get('field_reg_legacy_sports_update')->appendItem(['target_id' => $source->id()]);
      }
      $target->setNewRevision(TRUE);
      $target->setRevisionLogMessage('Consolidate legacy sports story; original source record and metadata retained.');
      $target->save();
      if ($new) $report['created'][] = (int) $target->id();
      $map[$source->id()] = (int) $target->id();
      \Drupal::state()->set('reg_core.sports_news_migration', $map);
    }
    if ($source->isPublished()) {
      $source->setNewRevision(TRUE);
      $source->setUnpublished();
      if ($source->hasField('moderation_state')) $source->set('moderation_state', 'migrated_news');
      $source->setRevisionLogMessage('Archived after consolidation into News node ' . $target->id() . '; original content retained.');
      $source->save();
      $report['archived'][] = (int) $source->id();
    }
  }
  foreach ($storage->loadByProperties(['type' => 'reg_news']) as $node) reg_core_news_ensure_alias($node);
  return $report;
}
