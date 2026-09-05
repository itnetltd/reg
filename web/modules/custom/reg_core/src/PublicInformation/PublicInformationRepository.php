<?php

namespace Drupal\reg_core\PublicInformation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Cached repository for published tenders, jobs, and publications.
 */
final class PublicInformationRepository implements PublicInformationRepositoryInterface {

  private const BUNDLES = ['reg_tender', 'reg_job', 'reg_publication'];

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly DateFormatterInterface $regDateFormatter,
    private readonly FileUrlGeneratorInterface $regFileUrlGenerator,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function homepageTenders(int $limit = 3): array {
    $limit = min(3, max(1, $limit));
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:homepage:tenders:' . $langcode . ':' . $limit;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $base_query = static fn () => $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_tender')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_tender_status', 'active');
    $featured_ids = $base_query()
      ->condition('field_reg_featured', 1)
      ->sort('nid', 'DESC')
      ->execute();
    $featured_nodes = array_values($storage->loadMultiple($featured_ids));
    usort($featured_nodes, fn (NodeInterface $a, NodeInterface $b): int => $this->compareHomepageTenders($a, $b, TRUE));
    $selected_nodes = array_slice($featured_nodes, 0, $limit);
    $selected_ids = array_map(static fn (NodeInterface $node): int => (int) $node->id(), $selected_nodes);
    if (count($selected_nodes) < $limit) {
      $fallback_ids = $base_query()
        ->condition('nid', $selected_ids ?: [0], 'NOT IN')
        ->sort('nid', 'DESC')
        ->execute();
      $fallback_nodes = array_values($storage->loadMultiple($fallback_ids));
      usort($fallback_nodes, fn (NodeInterface $a, NodeInterface $b): int => $this->compareHomepageTenders($a, $b, FALSE));
      $selected_nodes = array_merge($selected_nodes, array_slice($fallback_nodes, 0, $limit - count($selected_nodes)));
    }

    $items = [];
    $tags = ['node_list:reg_tender', 'taxonomy_term_list:reg_procurement_category'];
    foreach ($selected_nodes as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      if ($node->hasTranslation($langcode) && $node->getTranslation($langcode)->isPublished()) {
        $node = $node->getTranslation($langcode);
      }
      $items[] = $this->normalizeNode($node);
      $tags = Cache::mergeTags($tags, $node->getCacheTags());
    }
    $this->cache->set($cid, $items, $this->time->getRequestTime() + 300, $tags);
    return $items;
  }

  /**
   * Orders featured notices by priority then real publication date.
   */
  private function compareHomepageTenders(NodeInterface $a, NodeInterface $b, bool $include_priority): int {
    if ($include_priority) {
      $priority = (int) $this->value($b, 'field_reg_homepage_priority') <=> (int) $this->value($a, 'field_reg_homepage_priority');
      if ($priority !== 0) {
        return $priority;
      }
    }
    $a_date = $this->value($a, 'field_reg_issue_date');
    $b_date = $this->value($b, 'field_reg_issue_date');
    $a_timestamp = $a_date !== '' ? (strtotime($a_date . ' UTC') ?: $a->getCreatedTime()) : $a->getCreatedTime();
    $b_timestamp = $b_date !== '' ? (strtotime($b_date . ' UTC') ?: $b->getCreatedTime()) : $b->getCreatedTime();
    return ($b_timestamp <=> $a_timestamp) ?: ((int) $b->id() <=> (int) $a->id());
  }

  /**
   * {@inheritdoc}
   */
  public function search(array $bundles, array $filters = []): array {
    $bundles = array_values(array_intersect(self::BUNDLES, $bundles));
    if (!$bundles) {
      return [];
    }

    $filters = $this->normalizeFilters($filters);
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:public_information:' . hash('sha256', serialize([$bundles, $filters, $langcode]));
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundles, 'IN')
      ->condition('status', NodeInterface::PUBLISHED);

    if ($filters['query'] !== '') {
      $group = $query->orConditionGroup()
        ->condition('title', $filters['query'], 'CONTAINS')
        ->condition('field_reg_summary', $filters['query'], 'CONTAINS')
        ->condition('field_reg_reference', $filters['query'], 'CONTAINS')
        ->condition('field_reg_description', $filters['query'], 'CONTAINS');
      $query->condition($group);
    }
    if ($filters['entity'] !== '') {
      $query->condition('field_reg_entity', $filters['entity']);
    }
    if ($filters['language'] !== '') {
      $query->condition('field_reg_document_language', $filters['language']);
    }
    if ($filters['category'] > 0 && count($bundles) === 1) {
      $field = $bundles[0] === 'reg_tender' ? 'field_reg_tender_category' : 'field_reg_publication_type';
      if ($bundles[0] !== 'reg_job') {
        $query->condition($field . '.target_id', $filters['category']);
      }
    }
    if ($bundles === ['reg_publication'] && $filters['publication_scope'] !== '') {
      $field = 'field_reg_publication_category';
      if ($filters['publication_scope'] === 'publications') {
        $query->condition($field, [
          'press_release',
          'archived_announcement',
          'newsletter',
          'corporate_legal',
        ], 'NOT IN');
      }
      else {
        $query->condition($field, $filters['publication_scope']);
      }
    }

    $this->applyStatusFilter($query, $bundles, $filters);
    $date_field = $this->dateField($bundles);
    if ($date_field !== NULL) {
      if ($filters['from'] !== '') {
        $query->condition($date_field, $filters['from'] . 'T00:00:00', '>=');
      }
      if ($filters['to'] !== '') {
        $query->condition($date_field, $filters['to'] . 'T23:59:59', '<=');
      }
      if ($filters['year'] > 0 && in_array($bundles, [['reg_tender'], ['reg_publication']], TRUE)) {
        $query
          ->condition($date_field, $filters['year'] . '-01-01T00:00:00', '>=')
          ->condition($date_field, $filters['year'] . '-12-31T23:59:59', '<=');
      }
    }
    if ($bundles === ['reg_tender'] && $filters['closing'] !== '') {
      $query->condition('field_reg_closing_date', $filters['closing'] . 'T00:00:00', '>=');
    }

    [$sort_field, $direction] = $this->sortDefinition($bundles, $filters['sort']);
    $query->sort($sort_field, $direction)->sort('nid', 'DESC');
    $nodes = $storage->loadMultiple($query->execute());
    $items = [];
    foreach ($nodes as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      if ($node->hasTranslation($langcode) && $node->getTranslation($langcode)->isPublished()) {
        $node = $node->getTranslation($langcode);
      }
      $items[] = $this->normalizeNode($node);
    }

    $tags = array_map(static fn(string $bundle): string => 'node_list:' . $bundle, $bundles);
    $tags[] = 'taxonomy_term_list:reg_procurement_category';
    $tags[] = 'taxonomy_term_list:reg_publication_type';
    $this->cache->set($cid, $items, $this->time->getRequestTime() + 300, Cache::mergeTags($tags, ['config:reg_core.settings']));
    return $items;
  }

  /**
   * Applies route-level lifecycle rules.
   */
  private function applyStatusFilter(object $query, array $bundles, array $filters): void {
    if (count($bundles) !== 1) {
      return;
    }

    $bundle = $bundles[0];
    $status_field = $bundle === 'reg_tender' ? 'field_reg_tender_status' : ($bundle === 'reg_job' ? 'field_reg_job_status' : NULL);
    if ($status_field === NULL) {
      return;
    }
    if ($filters['status'] !== '') {
      $query->condition($status_field, $filters['status']);
      return;
    }

    if ($filters['mode'] === 'current') {
      // Procurement status is authoritative. A missing deadline must not hide
      // a tender that Procurement has explicitly marked Current / Open.
      $query->condition($status_field, 'active');
    }
    elseif ($filters['mode'] === 'awarded' && $bundle === 'reg_tender') {
      $query->condition($status_field, 'awarded');
    }
    elseif ($filters['mode'] === 'results' && $bundle === 'reg_job') {
      $query->condition($status_field, 'results');
    }
    elseif ($filters['mode'] === 'archive') {
      $statuses = $bundle === 'reg_tender'
        ? ['closed', 'cancelled', 'archived']
        : ['closed', 'cancelled', 'archived'];
      $query->condition($status_field, $statuses, 'IN');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function find(string $bundle, int $id): ?array {
    if (!in_array($bundle, self::BUNDLES, TRUE) || $id < 1) {
      return NULL;
    }
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:public_information:detail:' . $bundle . ':' . $id . ':' . $langcode;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : NULL;
    }

    $node = $this->regEntityTypeManager->getStorage('node')->load($id);
    if (!$node instanceof NodeInterface || $node->bundle() !== $bundle || !$node->isPublished() || !$node->access('view')) {
      return NULL;
    }
    if ($node->hasTranslation($langcode) && $node->getTranslation($langcode)->isPublished()) {
      $node = $node->getTranslation($langcode);
    }
    $item = $this->normalizeNode($node);
    $this->cache->set($cid, $item, $this->time->getRequestTime() + 300, $node->getCacheTags());
    return $item;
  }

  /**
   * {@inheritdoc}
   */
  public function taxonomyOptions(string $vocabulary): array {
    if (!in_array($vocabulary, ['reg_procurement_category', 'reg_publication_type'], TRUE)) {
      return [];
    }
    $terms = $this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary);
    $options = [];
    foreach ($terms as $term) {
      $options[(int) $term->tid] = $term->name;
    }
    return $options;
  }

  /**
   * Normalizes one public node without exposing internal revision metadata.
   */
  private function normalizeNode(NodeInterface $node): array {
    $bundle = $node->bundle();
    $route = match ($bundle) {
      'reg_tender' => 'reg_core.tender_detail',
      'reg_job' => 'reg_core.job_detail',
      default => 'reg_core.publication_detail',
    };
    $parameter = match ($bundle) {
      'reg_tender' => 'tender',
      'reg_job' => 'job',
      default => 'publication',
    };
    $date_field = match ($bundle) {
      'reg_tender' => 'field_reg_issue_date',
      'reg_job' => 'field_reg_posting_date',
      default => 'field_reg_publication_date',
    };
    $status_field = match ($bundle) {
      'reg_tender' => 'field_reg_tender_status',
      'reg_job' => 'field_reg_job_status',
      default => '',
    };
    $category_field = match ($bundle) {
      'reg_tender' => 'field_reg_tender_category',
      'reg_publication' => 'field_reg_publication_type',
      default => '',
    };
    $closing = $this->value($node, 'field_reg_closing_date');
    $documents = $this->documents($node);
    $status_key = $status_field !== '' ? $this->value($node, $status_field) : '';
    $entity_key = $this->value($node, 'field_reg_entity');
    $publication_channel = $this->value($node, 'field_reg_publication_channel');
    $submission_method = $this->value($node, 'field_reg_submission_method');
    $application_method = $this->value($node, 'field_reg_application_method');
    $deadline_timestamp = $closing !== '' ? (strtotime($closing . ' UTC') ?: 0) : 0;
    $is_open = $status_key === 'active' && $deadline_timestamp > $this->time->getRequestTime();
    $action = ['label' => '', 'url' => '', 'external' => FALSE, 'enabled' => FALSE];
    if ($bundle === 'reg_tender') {
      if ($submission_method === 'umucyo' || $publication_channel === 'umucyo' || ($publication_channel === 'both' && $submission_method === 'umucyo')) {
        $action = ['label' => 'BID ON UMUCYO', 'url' => $this->link($node, 'field_reg_umucyo_url'), 'external' => TRUE, 'enabled' => $is_open];
      }
      elseif ($submission_method === 'reg_online') {
        $action = ['label' => 'BID ONLINE', 'url' => $is_open ? Url::fromRoute('reg_core.bid_start', ['tender' => $node->id()])->toString() : '', 'external' => FALSE, 'enabled' => $is_open];
      }
      elseif ($submission_method === 'external') {
        $action = ['label' => 'OPEN APPROVED PLATFORM', 'url' => $this->link($node, 'field_reg_submission_url'), 'external' => TRUE, 'enabled' => $is_open];
      }
    }
    elseif ($bundle === 'reg_job') {
      if ($application_method === 'reg_online') $action = ['label' => 'APPLY ONLINE', 'url' => $is_open ? Url::fromRoute('reg_core.application_start', ['job' => $node->id()])->toString() : '', 'external' => FALSE, 'enabled' => $is_open];
      elseif ($application_method === 'mifotra') $action = ['label' => 'APPLY ON E-RECRUITMENT', 'url' => $this->link($node, 'field_reg_application_url'), 'external' => TRUE, 'enabled' => $is_open];
      elseif ($application_method === 'external') $action = ['label' => 'APPLY ON EXTERNAL PLATFORM', 'url' => $this->link($node, 'field_reg_application_url'), 'external' => TRUE, 'enabled' => $is_open];
    }

    $primary_document = [];
    foreach ($documents as $document) {
      if (($document['type_key'] ?? '') === 'terms_of_reference') {
        $primary_document = $document;
        break;
      }
    }
    $primary_document = $primary_document ?: ($documents[0] ?? []);

    return [
      'id' => (int) $node->id(),
      'bundle' => $bundle,
      'kind' => match ($bundle) {
        'reg_tender' => 'Tender',
        'reg_job' => 'Job',
        default => 'Publication',
      },
      'title' => $node->label(),
      'url' => Url::fromRoute($route, [$parameter => $node->id()])->toString(),
      'summary' => $this->value($node, 'field_reg_summary'),
      'description' => $this->value($node, 'field_reg_description') ?: $this->value($node, 'body'),
      'reference' => $this->value($node, 'field_reg_reference'),
      'entity' => $this->listLabel($node, 'field_reg_entity'),
      'entity_key' => $entity_key,
      'entity_short' => match ($entity_key) {
        'reg' => 'REG',
        'eucl' => 'EUCL',
        'edcl' => 'EDCL',
        default => '',
      },
      'category' => $this->referenceLabel($node, $category_field),
      'category_id' => $this->referenceId($node, $category_field),
      'status' => $status_field !== '' ? $this->listLabel($node, $status_field) : '',
      'status_key' => $status_key,
      'status_short' => match ($status_key) {
        'active' => 'OPEN',
        'closed' => 'CLOSED',
        'awarded' => 'AWARDED',
        'cancelled' => 'CANCELLED',
        'archived' => 'ARCHIVED',
        default => '',
      },
      'date' => $this->date($node, $date_field),
      'date_raw' => $this->value($node, $date_field),
      'closing_date' => $this->date($node, 'field_reg_closing_date'),
      'closing_date_raw' => $closing,
      'opening_date' => $this->date($node, 'field_reg_opening_date'),
      'award_date' => $this->date($node, 'field_reg_award_date'),
      'closes_in_days' => self::daysUntil($closing, $this->time->getRequestTime()),
      'featured' => (bool) $this->value($node, 'field_reg_featured'),
      'featured_priority' => (int) $this->value($node, 'field_reg_homepage_priority'),
      'language' => $this->listLabel($node, 'field_reg_document_language'),
      'tender_type' => $this->listLabel($node, 'field_reg_tender_type'),
      're_advertised' => (bool) $this->value($node, 'field_reg_re_advertised'),
      'project' => $this->value($node, 'field_reg_project'),
      'procurement_method' => $this->listLabel($node, 'field_reg_procurement_method'),
      'contract_duration' => $this->value($node, 'field_reg_contract_duration'),
      'renewal_terms' => $this->value($node, 'field_reg_renewal_terms'),
      'eligibility' => $this->value($node, 'field_reg_eligibility'),
      'contact' => $this->value($node, 'field_reg_contact'),
      'procurement_plan' => $this->value($node, 'field_reg_procurement_plan'),
      'plan_item' => $this->referenceLabel($node, $bundle === 'reg_tender' ? 'field_reg_proc_plan_item' : ($bundle === 'reg_job' ? 'field_reg_recruit_plan_item' : '')),
      'publication_channel' => $this->listLabel($node, 'field_reg_publication_channel'),
      'publication_channel_key' => $publication_channel,
      'submission_method' => $this->listLabel($node, 'field_reg_submission_method'),
      'submission_method_key' => $submission_method,
      'application_method' => $this->listLabel($node, 'field_reg_application_method'),
      'application_method_key' => $application_method,
      'umucyo_number' => $this->value($node, 'field_reg_umucyo_number'),
      'action' => $action,
      'supplier_ctas' => $bundle === 'reg_tender' && $submission_method === 'reg_online' ? [
        'register_url' => Url::fromRoute('reg_core.supplier_register')->toString(),
        'login_url' => Url::fromRoute('reg_core.supplier_login')->toString(),
      ] : [],
      'is_open' => $is_open,
      'awarded_supplier' => $this->value($node, 'field_reg_awarded_supplier'),
      'department' => $this->value($node, 'field_reg_department'),
      'job_type' => $this->listLabel($node, 'field_reg_job_type'),
      'location' => $this->value($node, 'field_reg_location'),
      'vacancies' => (int) $this->value($node, 'field_reg_vacancies'),
      'requirements' => $this->value($node, 'field_reg_requirements'),
      'instructions' => $this->value($node, 'field_reg_instructions'),
      'result_summary' => $this->value($node, 'field_reg_result_summary'),
      'application_url' => $this->link($node, 'field_reg_application_url'),
      'external_url' => $this->link($node, 'field_reg_external_url') ?: $this->link($node, 'field_reg_document_url'),
      'documents' => $documents,
      'primary_document' => $primary_document,
      'cover_url' => $this->coverUrl($node),
      'related' => $bundle === 'reg_tender' ? $this->relatedTenders($node) : $this->relatedPublications($node),
      'last_updated' => $this->regDateFormatter->format($node->getChangedTime(), 'medium'),
      'langcode' => $node->language()->getId(),
    ];
  }

  /**
   * Builds controlled-download metadata from document Media references.
   */
  private function documents(NodeInterface $node): array {
    $fields = match ($node->bundle()) {
      'reg_tender' => [
        'field_reg_tender_documents' => 'Tender document',
        'field_reg_tender_addenda' => 'Addendum or clarification',
        'field_reg_award_documents' => 'Award notice',
      ],
      'reg_job' => [
        'field_reg_job_documents' => 'Vacancy notice',
        'field_reg_result_documents' => 'Recruitment result',
      ],
      default => ['field_reg_publication_document' => 'Publication'],
    };
    $documents = [];
    foreach ($fields as $field_name => $group) {
      if (!$node->hasField($field_name)) {
        continue;
      }
      foreach ($node->get($field_name)->referencedEntities() as $delta => $media) {
        if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
          continue;
        }
        $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
        $file = $source_field !== '' ? $media->get($source_field)->entity : NULL;
        if (!$file) {
          continue;
        }
        $documents[] = [
          'label' => $media->label(),
          'group' => $this->listLabel($media, 'field_reg_document_type') ?: $group,
          'type' => $this->listLabel($media, 'field_reg_document_type') ?: $group,
          'type_key' => $this->value($media, 'field_reg_document_type'),
          'date' => $this->date($media, 'field_reg_document_date'),
          'version' => $this->value($media, 'field_reg_document_version'),
          'filename' => $file->getFilename(),
          'extension' => strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION)),
          'size' => (string) ByteSizeMarkup::create((int) $file->getSize()),
          'url' => Url::fromRoute('reg_core.public_information_download', [
            'node' => $node->id(),
            'field_name' => $field_name,
            'delta' => $delta,
          ])->toString(),
        ];
      }
    }
    return $documents;
  }

  /**
   * Returns a safely generated cover-image URL.
   */
  private function coverUrl(NodeInterface $node): string {
    if (!$node->hasField('field_reg_cover_image') || $node->get('field_reg_cover_image')->isEmpty()) {
      return '';
    }
    $media = $node->get('field_reg_cover_image')->entity;
    if (!$media instanceof MediaInterface || !$media->access('view')) {
      return '';
    }
    $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $file = $source_field !== '' ? $media->get($source_field)->entity : NULL;
    return $file ? $this->regFileUrlGenerator->generateString($file->getFileUri()) : '';
  }

  /**
   * Returns published related publications only.
   */
  private function relatedPublications(NodeInterface $node): array {
    if (!$node->hasField('field_reg_related_publications')) {
      return [];
    }
    $items = [];
    foreach ($node->get('field_reg_related_publications')->referencedEntities() as $related) {
      if ($related instanceof NodeInterface && $related->isPublished() && $related->access('view')) {
        $items[] = [
          'title' => $related->label(),
          'url' => Url::fromRoute('reg_core.publication_detail', ['publication' => $related->id()])->toString(),
        ];
      }
    }
    return $items;
  }

  /**
   * Returns explicit or category-matched published related tenders.
   */
  private function relatedTenders(NodeInterface $node): array {
    $candidates = [];
    if ($node->hasField('field_reg_related_tenders')) {
      foreach ($node->get('field_reg_related_tenders')->referencedEntities() as $related) {
        if ($related instanceof NodeInterface && $related->id() !== $node->id() && $related->isPublished() && $related->access('view')) {
          $candidates[(int) $related->id()] = $related;
        }
      }
    }

    $query = $this->regEntityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_tender')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('nid', $node->id(), '<>')
      ->sort('field_reg_issue_date', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 3);
    $category_id = $this->referenceId($node, 'field_reg_tender_category');
    if ($category_id) {
      $query->condition('field_reg_tender_category.target_id', $category_id);
    }
    $storage = $this->regEntityTypeManager->getStorage('node');
    foreach ($storage->loadMultiple($query->execute()) as $related) {
      if ($related instanceof NodeInterface && $related->access('view')) {
        $candidates[(int) $related->id()] = $related;
      }
    }

    $items = [];
    foreach (array_slice($candidates, 0, 3, TRUE) as $related) {
      $items[] = [
        'title' => $related->label(),
        'url' => Url::fromRoute('reg_core.tender_detail', ['tender' => $related->id()])->toString(),
        'reference' => $this->value($related, 'field_reg_reference'),
        'status' => $this->listLabel($related, 'field_reg_tender_status'),
      ];
    }
    return $items;
  }

  /**
   * Calculates a non-negative public deadline countdown.
   */
  public static function daysUntil(?string $value, int $now): ?int {
    if (!$value) {
      return NULL;
    }
    $timestamp = strtotime($value . (str_contains($value, 'T') ? ' UTC' : ''));
    if ($timestamp === FALSE || $timestamp < $now) {
      return NULL;
    }
    return max(0, (int) ceil(($timestamp - $now) / 86400));
  }

  /**
   * Returns a field's first plain value.
   */
  private function value(object $entity, string $field_name): string {
    if ($field_name === '' || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }
    $value = $entity->get($field_name)->first()?->get('value')->getValue();
    return trim(strip_tags((string) $value));
  }

  /**
   * Returns the configured label for a list field value.
   */
  private function listLabel(object $entity, string $field_name): string {
    $value = $this->value($entity, $field_name);
    if ($value === '') {
      return '';
    }
    $allowed = $entity->getFieldDefinition($field_name)->getFieldStorageDefinition()->getSetting('allowed_values') ?: [];
    return (string) ($allowed[$value] ?? $value);
  }

  /**
   * Returns a referenced entity label.
   */
  private function referenceLabel(object $entity, string $field_name): string {
    if ($field_name === '' || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }
    return (string) ($entity->get($field_name)->entity?->label() ?? '');
  }

  /**
   * Returns a referenced entity ID.
   */
  private function referenceId(object $entity, string $field_name): int {
    if ($field_name === '' || !$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return 0;
    }
    return (int) ($entity->get($field_name)->target_id ?? 0);
  }

  /**
   * Returns a public link field string.
   */
  private function link(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    $uri = (string) ($node->get($field_name)->uri ?? '');
    if ($uri === '') {
      return '';
    }
    try {
      return Url::fromUri($uri)->toString();
    }
    catch (\Exception) {
      return '';
    }
  }

  /**
   * Formats a Drupal date field.
   */
  private function date(object $entity, string $field_name): string {
    $value = $this->value($entity, $field_name);
    if ($value === '') {
      return '';
    }
    $timestamp = strtotime($value . (str_contains($value, 'T') ? ' UTC' : ''));
    return $timestamp === FALSE ? '' : $this->regDateFormatter->format($timestamp, 'medium');
  }

  /**
   * Returns the primary date field when one bundle is being queried.
   */
  private function dateField(array $bundles): ?string {
    if (count($bundles) !== 1) {
      return NULL;
    }
    return match ($bundles[0]) {
      'reg_tender' => 'field_reg_issue_date',
      'reg_job' => 'field_reg_posting_date',
      'reg_publication' => 'field_reg_publication_date',
      default => NULL,
    };
  }

  /**
   * Chooses a safe sort field and direction.
   */
  private function sortDefinition(array $bundles, string $sort): array {
    if ($sort === 'title') {
      return ['title', 'ASC'];
    }
    if ($sort === 'oldest') {
      if ($bundles === ['reg_tender']) {
        return ['created', 'ASC'];
      }
      return [$this->dateField($bundles) ?: 'created', 'ASC'];
    }
    if ($sort === 'deadline' && count($bundles) === 1 && in_array($bundles[0], ['reg_tender', 'reg_job'], TRUE)) {
      return ['field_reg_closing_date', 'ASC'];
    }
    if ($bundles === ['reg_tender']) {
      // Keep notices without a Procurement-supplied publication date visible.
      return ['created', 'DESC'];
    }
    return [$this->dateField($bundles) ?: 'created', 'DESC'];
  }

  /**
   * Whitelists and normalizes public query parameters.
   */
  private function normalizeFilters(array $filters): array {
    $date = static fn(mixed $value): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? (string) $value : '';
    return [
      'query' => mb_substr(trim(strip_tags((string) ($filters['query'] ?? ''))), 0, 120),
      'entity' => in_array(($filters['entity'] ?? ''), ['reg', 'eucl', 'edcl'], TRUE) ? (string) $filters['entity'] : '',
      'language' => in_array(($filters['language'] ?? ''), ['en', 'rw', 'fr', 'multi'], TRUE) ? (string) $filters['language'] : '',
      'category' => max(0, (int) ($filters['category'] ?? 0)),
      'publication_scope' => in_array(($filters['publication_scope'] ?? ''), [
        'publications',
        'press_release',
        'archived_announcement',
        'newsletter',
        'corporate_legal',
      ], TRUE) ? (string) $filters['publication_scope'] : '',
      'status' => preg_match('/^[a-z_]+$/', (string) ($filters['status'] ?? '')) ? (string) $filters['status'] : '',
      'mode' => in_array(($filters['mode'] ?? ''), ['current', 'awarded', 'archive', 'results'], TRUE) ? (string) $filters['mode'] : '',
      'from' => $date($filters['from'] ?? ''),
      'to' => $date($filters['to'] ?? ''),
      'closing' => $date($filters['closing'] ?? ''),
      'year' => min(2100, max(0, (int) ($filters['year'] ?? 0))),
      'sort' => in_array(($filters['sort'] ?? ''), ['newest', 'oldest', 'deadline', 'title'], TRUE) ? (string) $filters['sort'] : 'newest',
    ];
  }

}
