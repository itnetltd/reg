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
   * Public editorial node bundles for the translation workspace.
   *
   * Private application, supplier, bid and submission records are excluded.
   * Only bundles which exist and have Drupal content translation enabled
   * appear in the workspace. The old report API remains available to dashboards.
   */
  public function workspaceBundleOptions(): array {
    $manager = \Drupal::service('content_translation.manager');
    $storage = $this->regEntityTypeManager->getStorage('node_type');
    $public = array_unique(array_merge(self::BUNDLES, [
      'reg_about_page', 'reg_what_we_do_page', 'reg_value', 'reg_leader',
      'reg_employee_recognition', 'reg_homepage_hero', 'reg_section_hero',
      'reg_public_alert', 'reg_energy_tool', 'reg_video', 'reg_fact',
      'reg_social_post', 'reg_partner', 'reg_sports_membership',
    ]));
    $options = [];
    foreach ($storage->loadMultiple($public) as $id => $type) {
      if ($manager->isEnabled('node', $id)) {
        $options[$id] = $type->label();
      }
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Search and report both language directions using accessible content.
   *
   * Counts respect search, bundle, publication, owner and target-language
   * filters, but not the selected status tab. No silent 2,000-node cutoff.
   */
  public function workspace(array $filters): array {
    $bundles = $this->workspaceBundleOptions();
    $summary = ['all' => 0, 'missing' => 0, 'pending' => 0, 'outdated' => 0, 'complete' => 0];
    $rows = [];
    if (!$bundles) {
      return ['rows' => [], 'summary' => $summary, 'bundles' => $bundles];
    }
    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)
      ->condition('type', array_keys($bundles), 'IN')
      ->sort('nid', 'DESC');
    if (isset($bundles[$filters['content_type']])) {
      $query->condition('type', $filters['content_type']);
    }
    $q = $filters['q'];
    $owner = mb_strtolower($filters['owner']);
    $manager = \Drupal::service('content_translation.manager');
    $ids = $query->execute();
    foreach (array_chunk(array_values($ids), 100) as $chunk) {
      $loaded = $storage->loadMultiple($chunk);
      foreach ($chunk as $nid) {
        $node = $loaded[$nid] ?? NULL;
        if (!$node instanceof NodeInterface || !$node->access('view')) {
          continue;
        }
        $source = $node->getUntranslated();
        if ($filters['published'] !== '' && $source->isPublished() !== ($filters['published'] === 'published')) {
          continue;
        }
        $ownerName = (string) ($source->getOwner()?->getDisplayName() ?? '');
        $department = $this->value($source, 'field_reg_content_department');
        if ($owner !== '' && !str_contains(mb_strtolower($ownerName . ' ' . $department), $owner)) {
          continue;
        }
        // Search the original title, accessible translations and the node ID.
        $titles = [(string) $source->label()];
        foreach (['en', 'rw'] as $lang) {
          if ($node->hasTranslation($lang) && $node->getTranslation($lang)->access('view')) {
            $titles[] = (string) $node->getTranslation($lang)->label();
          }
        }
        if ($q !== '' && (string) $nid !== $q && !str_contains(mb_strtolower(implode(' ', $titles)), mb_strtolower($q))) {
          continue;
        }
        $sourceLang = $source->language()->getId();
        $states = [];
        foreach (['en', 'rw'] as $lang) {
          $translation = $node->hasTranslation($lang) ? $node->getTranslation($lang) : NULL;
          if ($lang !== $sourceLang && !$translation) {
            // Drupal's translation overview also considers pending revisions.
            // Do not label a saved, accessible draft translation as missing.
            $revisionId = $storage->getLatestTranslationAffectedRevisionId($nid, $lang);
            if ($revisionId && (string) $revisionId !== (string) $node->getRevisionId()) {
              $revision = $storage->loadRevision($revisionId);
              if ($revision instanceof NodeInterface && !$revision->wasDefaultRevision()
                && $revision->hasTranslation($lang) && $revision->getTranslation($lang)->access('view')) {
                $translation = $revision->getTranslation($lang);
              }
            }
          }
          if (!$translation) {
            $states[$lang] = 'missing';
          }
          elseif (!$translation->access('view')) {
            $states[$lang] = 'restricted';
          }
          elseif (!$translation->isPublished() || !$translation->isDefaultRevision()) {
            $states[$lang] = 'pending';
          }
          elseif ($lang !== $sourceLang && $manager->getTranslationMetadata($translation)->isOutdated()) {
            $states[$lang] = 'outdated';
          }
          else {
            $states[$lang] = 'complete';
          }
        }
        $targetStates = $filters['target'] === '' ? array_values($states) : [$states[$filters['target']]];
        $matching = self::workspaceStatus($targetStates);
        $summary['all']++;
        if (isset($summary[$matching])) {
          $summary[$matching]++;
        }
        if ($filters['status'] !== 'all' && $filters['status'] !== $matching) {
          continue;
        }
        $rows[] = [
          'id' => (int) $nid,
          'title' => (string) $source->label(),
          'bundle' => $source->bundle(),
          'bundle_label' => $bundles[$source->bundle()],
          'source_language' => $sourceLang,
          'states' => $states,
          'status_key' => $matching,
          'published' => $source->isPublished(),
          'last_updated' => $this->regDateFormatter->format($source->getChangedTime(), 'short'),
        ];
      }
    }
    return ['rows' => $rows, 'summary' => $summary, 'bundles' => $bundles];
  }

  /**
   * Classifies a target language or the overall bilingual editorial status.
   */
  public static function workspaceStatus(array $states): string {
    foreach (['missing', 'pending', 'outdated', 'restricted'] as $status) {
      if (in_array($status, $states, TRUE)) {
        return $status;
      }
    }
    return 'complete';
  }

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
