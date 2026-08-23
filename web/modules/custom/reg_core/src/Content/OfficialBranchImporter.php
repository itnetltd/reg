<?php

namespace Drupal\reg_core\Content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Idempotently migrates the official directory into editable branch nodes.
 */
final class OfficialBranchImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly ConfigFactoryInterface $regConfigFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Imports new branches and safely tags exact-title records already present.
   */
  public function import(): array {
    $counts = ['created' => 0, 'tagged_existing' => 0, 'unchanged' => 0];
    $storage = $this->regEntityTypeManager->getStorage('node');
    $districts = $this->districtTerms();
    $reviewDays = max(1, (int) ($this->regConfigFactory->get('reg_core.settings')->get('governance.branch_review_days') ?: 180));
    $sourceChecked = gmdate('Y-m-d', $this->time->getRequestTime());
    $nextReview = gmdate('Y-m-d', $this->time->getRequestTime() + ($reviewDays * 86400));

    foreach (OfficialBranchContent::records() as $record) {
      $hash = hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      $stableIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'reg_branch')
        ->condition('field_reg_source_id', $record['source_id'])
        ->range(0, 1)
        ->execute();
      if ($stableIds) {
        $counts['unchanged']++;
        continue;
      }

      $titleIds = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'reg_branch')
        ->condition('title', $record['branch'])
        ->range(0, 1)
        ->execute();
      if ($titleIds) {
        $existing = $storage->load(reset($titleIds));
        if ($existing instanceof NodeInterface && $this->tagExisting($existing, $record, $hash, $sourceChecked, $nextReview, $districts)) {
          $existing->save();
        }
        $counts['tagged_existing']++;
        continue;
      }

      $values = [
        'type' => 'reg_branch',
        'title' => $record['branch'],
        'langcode' => 'en',
        'status' => NodeInterface::PUBLISHED,
        'moderation_state' => 'published',
        'field_reg_active' => 1,
        'field_reg_entity' => 'eucl',
        'field_reg_branch_type' => 'official_branch_service_area',
        'field_reg_manager_name' => $record['manager'],
        'field_reg_phone_ph' => $record['ph'],
        'field_reg_phone_te' => $record['te'],
        'field_reg_email' => $record['email'],
        'field_reg_source_url' => ['uri' => OfficialBranchContent::SOURCE_URL, 'title' => OfficialBranchContent::SOURCE_LABEL],
        'field_reg_source_label' => OfficialBranchContent::SOURCE_LABEL,
        'field_reg_source_id' => $record['source_id'],
        'field_reg_source_hash' => $hash,
        'field_reg_source_excerpt' => $record['source_excerpt'],
        'field_reg_branch_review_status' => OfficialBranchContent::REVIEW_STATUS,
        'field_reg_source_checked_date' => $sourceChecked,
        'field_reg_review_date' => $nextReview,
        'field_reg_content_department' => 'Customer Service',
      ];
      $this->addDistrictValues($values, $record['branch'], $districts);
      $storage->create($values)->save();
      $counts['created']++;
    }

    return $counts;
  }

  /**
   * Adds only missing migration fields to a pre-existing exact-title record.
   */
  private function tagExisting(NodeInterface $node, array $record, string $hash, string $sourceChecked, string $nextReview, array $districts): bool {
    $changed = FALSE;
    $values = [
      'field_reg_source_url' => ['uri' => OfficialBranchContent::SOURCE_URL, 'title' => OfficialBranchContent::SOURCE_LABEL],
      'field_reg_source_label' => OfficialBranchContent::SOURCE_LABEL,
      'field_reg_source_id' => $record['source_id'],
      'field_reg_source_hash' => $hash,
      'field_reg_source_excerpt' => $record['source_excerpt'],
      'field_reg_branch_review_status' => OfficialBranchContent::REVIEW_STATUS,
      'field_reg_source_checked_date' => $sourceChecked,
      'field_reg_review_date' => $nextReview,
      'field_reg_content_department' => 'Customer Service',
      'field_reg_entity' => 'eucl',
      'field_reg_branch_type' => 'official_branch_service_area',
      'field_reg_active' => 1,
      'field_reg_manager_name' => $record['manager'],
      'field_reg_phone_ph' => $record['ph'],
      'field_reg_phone_te' => $record['te'],
      'field_reg_email' => $record['email'],
    ];
    if (isset($districts[$record['branch']])) {
      $values['field_reg_district_ref'] = ['target_id' => $districts[$record['branch']]];
      $values['field_reg_district'] = $record['branch'];
    }
    foreach ($values as $field => $value) {
      if ($node->hasField($field) && $node->get($field)->isEmpty()) {
        $node->set($field, $value);
        $changed = TRUE;
      }
    }
    return $changed;
  }

  /**
   * Creates and indexes only the explicitly approved district mappings.
   */
  private function districtTerms(): array {
    $storage = $this->regEntityTypeManager->getStorage('taxonomy_term');
    $terms = [];
    foreach ($storage->loadTree('reg_district') as $term) {
      $terms[mb_strtolower($term->name)] = (int) $term->tid;
    }
    $mapped = [];
    foreach (OfficialBranchContent::districtMappings() as $branch => $district) {
      $key = mb_strtolower($district);
      if (!isset($terms[$key])) {
        $term = $storage->create(['vid' => 'reg_district', 'name' => $district]);
        $term->save();
        $terms[$key] = (int) $term->id();
      }
      $mapped[$branch] = $terms[$key];
    }
    return $mapped;
  }

  /**
   * Applies a separately approved district mapping to a new record.
   */
  private function addDistrictValues(array &$values, string $branch, array $districts): void {
    if (!isset($districts[$branch])) {
      return;
    }
    $values['field_reg_district_ref'] = ['target_id' => $districts[$branch]];
    $values['field_reg_district'] = $branch;
  }

}
