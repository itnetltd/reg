<?php

namespace Drupal\reg_core\Translation;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Reports translation completeness without machine-translating content.
 */
final class TranslationStatusRepository {

  private const BUNDLES = [
    'page',
    'article',
    'reg_service',
    'reg_outage',
    'reg_news',
    'reg_tender',
    'reg_job',
    'reg_publication',
    'reg_faq',
    'reg_branch',
    'reg_sports_update',
    'reg_sports_team',
    'reg_sports_player',
    'reg_sports_staff',
    'reg_sports_fixture',
    'reg_sports_standing',
    'reg_sports_gallery',
    'reg_sports_video',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly DateFormatterInterface $regDateFormatter,
  ) {}

  /**
   * Returns report rows after applying governance filters.
   */
  public function report(array $filters = []): array {
    $filters = $this->filters($filters);
    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', self::BUNDLES, 'IN')
      ->sort('changed', 'DESC')
      ->range(0, 2000);
    if ($filters['content_type'] !== '' && in_array($filters['content_type'], self::BUNDLES, TRUE)) {
      $query->condition('type', $filters['content_type']);
    }
    if ($filters['published'] !== '') {
      $query->condition('status', $filters['published'] === 'published' ? 1 : 0);
    }
    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $row = $this->normalize($node);
      if ($filters['status'] !== '' && $row['status_key'] !== $filters['status']) {
        continue;
      }
      if ($filters['owner'] !== '' && !str_contains(mb_strtolower($row['owner'] . ' ' . $row['department']), $filters['owner'])) {
        continue;
      }
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Returns translation governance KPI totals.
   */
  public function summary(array $rows): array {
    $summary = [
      'total_priority' => 0,
      'fully_translated' => 0,
      'missing_translation' => 0,
      'outdated_translation' => 0,
      'pending_review' => 0,
    ];
    foreach ($rows as $row) {
      if (in_array($row['priority_key'], ['priority_1', 'priority_2'], TRUE)) {
        $summary['total_priority']++;
      }
      if ($row['status_key'] === 'complete') {
        $summary['fully_translated']++;
      }
      elseif ($row['status_key'] === 'missing_rw') {
        $summary['missing_translation']++;
      }
      elseif ($row['status_key'] === 'outdated') {
        $summary['outdated_translation']++;
      }
      elseif ($row['status_key'] === 'pending_review') {
        $summary['pending_review']++;
      }
    }
    return $summary;
  }

  /**
   * Returns available public bundles.
   */
  public function bundleOptions(): array {
    $options = [];
    $storage = $this->regEntityTypeManager->getStorage('node_type');
    foreach (self::BUNDLES as $bundle) {
      if ($type = $storage->load($bundle)) {
        $options[$bundle] = $type->label();
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Normalizes translation state for one node.
   */
  private function normalize(NodeInterface $node): array {
    $english = $node->hasTranslation('en') ? $node->getTranslation('en') : NULL;
    $kinyarwanda = $node->hasTranslation('rw') ? $node->getTranslation('rw') : NULL;
    $source_changed = $english?->getChangedTime() ?? $node->getChangedTime();
    $rw_status = $this->translationState($kinyarwanda, $source_changed);
    $status_key = match ($rw_status) {
      'Missing' => 'missing_rw',
      'Outdated' => 'outdated',
      'Published' => 'complete',
      default => 'pending_review',
    };
    $priority_key = $this->value($node, 'field_reg_translation_priority') ?: $this->defaultPriority($node->bundle());
    return [
      'id' => (int) $node->id(),
      'title' => (string) $node->label(),
      'bundle' => $node->bundle(),
      'source_language' => $node->getUntranslated()->language()->getId(),
      'english_status' => $this->translationState($english, 0),
      'kinyarwanda_status' => $rw_status,
      'status_key' => $status_key,
      'last_updated' => $this->regDateFormatter->format($node->getChangedTime(), 'short'),
      'last_updated_raw' => $node->getChangedTime(),
      'owner' => (string) ($node->getOwner()?->getDisplayName() ?? ''),
      'department' => $this->value($node, 'field_reg_content_department'),
      'review_date' => $this->value($node, 'field_reg_translation_review') ?: $this->value($node, 'field_reg_review_date'),
      'priority_key' => $priority_key,
      'priority' => match ($priority_key) {
        'priority_1' => 'Priority 1',
        'priority_2' => 'Priority 2',
        default => 'Standard',
      },
      'published' => $node->isPublished(),
    ];
  }

  /**
   * Returns a public translation state.
   */
  private function translationState(?NodeInterface $translation, int $source_changed): string {
    if (!$translation) {
      return 'Missing';
    }
    $moderation = $this->value($translation, 'moderation_state');
    if (!$translation->isPublished()) {
      return $moderation === 'needs_review' ? 'Needs Review' : 'Draft';
    }
    if ($source_changed > 0 && $translation->getChangedTime() < $source_changed) {
      return 'Outdated';
    }
    return 'Published';
  }

  /**
   * Returns a first plain field value.
   */
  private function value(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) ($node->get($field)->first()?->get('value')->getValue() ?? '')));
  }

  /**
   * Applies the approved default priority groups.
   */
  private function defaultPriority(string $bundle): string {
    if (in_array($bundle, ['page', 'reg_service', 'reg_outage', 'reg_faq', 'reg_branch'], TRUE)) {
      return 'priority_1';
    }
    if (in_array($bundle, ['reg_tender', 'reg_job', 'reg_publication', 'reg_news'], TRUE) || str_starts_with($bundle, 'reg_sports_')) {
      return 'priority_2';
    }
    return 'standard';
  }

  /**
   * Whitelists report filters.
   */
  private function filters(array $filters): array {
    return [
      'status' => in_array(($filters['status'] ?? ''), ['missing_rw', 'outdated', 'pending_review', 'complete'], TRUE) ? (string) $filters['status'] : '',
      'content_type' => preg_match('/^[a-z0-9_]+$/', (string) ($filters['content_type'] ?? '')) ? (string) $filters['content_type'] : '',
      'owner' => mb_strtolower(mb_substr(trim(strip_tags((string) ($filters['owner'] ?? ''))), 0, 80)),
      'published' => in_array(($filters['published'] ?? ''), ['published', 'unpublished'], TRUE) ? (string) $filters['published'] : '',
    ];
  }

}
