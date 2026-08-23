<?php

namespace Drupal\reg_core\Branch;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Cached repository for public REG branch content.
 */
final class BranchRepository implements BranchRepositoryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly DateFormatterInterface $regDateFormatter,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function search(array $filters = []): array {
    $filters = $this->normalizeFilters($filters);
    $items = array_values(array_filter($this->all(), function (array $branch) use ($filters): bool {
      if ($filters['province'] && $branch['province_id'] !== $filters['province']) {
        return FALSE;
      }
      if ($filters['district'] && $branch['district_id'] !== $filters['district']) {
        return FALSE;
      }
      if ($filters['entity'] !== '' && $branch['entity_key'] !== $filters['entity']) {
        return FALSE;
      }
      if ($filters['service'] && !in_array($filters['service'], $branch['service_ids'], TRUE)) {
        return FALSE;
      }
      if ($filters['query'] === '') {
        return TRUE;
      }
      $haystack = mb_strtolower(implode(' ', [
        $branch['title'],
        $branch['manager'],
        $branch['ph'],
        $branch['te'],
        $branch['email'],
        $branch['province'],
        $branch['district'],
        $branch['sector'],
        $branch['address'],
        $branch['entity'],
        implode(' ', $branch['services']),
      ]));
      return str_contains($haystack, $filters['query']);
    }));

    usort($items, static function (array $a, array $b) use ($filters): int {
      $score = static function (array $branch) use ($filters): int {
        $value = 0;
        if ($filters['district'] && $branch['district_id'] === $filters['district']) {
          $value += 200;
        }
        if ($filters['province'] && $branch['province_id'] === $filters['province']) {
          $value += 100;
        }
        return $value;
      };
      $score_comparison = $score($b) <=> $score($a);
      return $score_comparison !== 0
        ? $score_comparison
        : strnatcasecmp($a['title'], $b['title']);
    });
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function find(int $id): ?array {
    foreach ($this->all() as $branch) {
      if ($branch['id'] === $id) {
        return $branch;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function taxonomyOptions(string $vocabulary): array {
    if (!in_array($vocabulary, ['reg_province', 'reg_district', 'reg_branch_service'], TRUE)) {
      return [];
    }
    $options = [];
    foreach ($this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary) as $term) {
      $options[(int) $term->tid] = $term->name;
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

  /**
   * Loads and normalizes all published active branches in the current language.
   */
  private function all(): array {
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:branches:' . $langcode;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_branch')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_active', 1)
      ->sort('title', 'ASC')
      ->range(0, 500)
      ->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      if ($node->hasTranslation($langcode) && $node->getTranslation($langcode)->isPublished()) {
        $node = $node->getTranslation($langcode);
      }
      $items[] = $this->normalize($node);
    }
    $this->cache->set($cid, $items, $this->time->getRequestTime() + 300, [
      'node_list:reg_branch',
      'taxonomy_term_list:reg_province',
      'taxonomy_term_list:reg_district',
      'taxonomy_term_list:reg_branch_service',
    ]);
    return $items;
  }

  /**
   * Normalizes a branch without exposing editorial or operational metadata.
   */
  private function normalize(NodeInterface $node): array {
    $province = $this->reference($node, 'field_reg_province');
    $district = $this->reference($node, 'field_reg_district_ref');
    $services = $this->references($node, 'field_reg_branch_services');
    $latitude = $this->number($node, 'field_reg_latitude');
    $longitude = $this->number($node, 'field_reg_longitude');
    $ph = $this->value($node, 'field_reg_phone_ph') ?: $this->value($node, 'field_reg_phone');
    $te = $this->value($node, 'field_reg_phone_te') ?: $this->value($node, 'field_reg_phone_secondary');
    $email = $this->value($node, 'field_reg_email');
    $directions = $this->link($node, 'field_reg_map_url');
    return [
      'id' => (int) $node->id(),
      'title' => (string) $node->label(),
      'url' => Url::fromRoute('reg_core.branch_detail', ['branch' => $node->id()])->toString(),
      'entity' => $this->listLabel($node, 'field_reg_entity'),
      'entity_key' => $this->value($node, 'field_reg_entity'),
      'branch_type' => $this->listLabel($node, 'field_reg_branch_type'),
      'manager' => $this->value($node, 'field_reg_manager_name'),
      'province' => $province['label'],
      'province_id' => $province['id'],
      'district' => $district['label'] ?: $this->value($node, 'field_reg_district'),
      'district_id' => $district['id'],
      'sector' => $this->value($node, 'field_reg_sector'),
      'cell' => $this->value($node, 'field_reg_cell'),
      'village' => $this->value($node, 'field_reg_village'),
      'address' => $this->value($node, 'field_reg_address'),
      'latitude' => $latitude,
      'longitude' => $longitude,
      'ph' => $ph,
      'ph_uri' => $this->telephoneUri($ph),
      'te' => $te,
      'te_uri' => $this->telephoneUri($te),
      // Backward-compatible aliases for map and analytics consumers.
      'telephone' => $ph,
      'telephone_uri' => $this->telephoneUri($ph),
      'secondary_telephone' => $te,
      'secondary_telephone_uri' => $this->telephoneUri($te),
      'email' => $email,
      'email_uri' => $email !== '' ? 'mailto:' . $email : '',
      'opening_hours' => $this->value($node, 'field_reg_opening_hours'),
      'services' => array_column($services, 'label'),
      'service_ids' => array_column($services, 'id'),
      'accessibility' => $this->value($node, 'field_reg_accessibility'),
      'notes' => $this->value($node, 'field_reg_public_notes'),
      'directions_url' => $directions,
      'featured' => (bool) $this->value($node, 'field_reg_featured'),
      'review_date' => $this->date($node, 'field_reg_review_date'),
      'last_verified' => $this->date($node, 'field_reg_last_verified'),
      'has_coordinates' => $latitude !== NULL && $longitude !== NULL,
      'location_status' => $latitude !== NULL && $longitude !== NULL
        ? 'Location coordinates available'
        : 'Location coordinates pending',
      'last_updated' => $this->regDateFormatter->format($node->getChangedTime(), 'medium'),
      'langcode' => $node->language()->getId(),
    ];
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
   * Returns one reference identifier and label.
   */
  private function reference(NodeInterface $node, string $field): array {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return ['id' => 0, 'label' => ''];
    }
    $entity = $node->get($field)->entity;
    return $entity ? ['id' => (int) $entity->id(), 'label' => (string) $entity->label()] : ['id' => 0, 'label' => ''];
  }

  /**
   * Returns all public reference identifiers and labels.
   */
  private function references(NodeInterface $node, string $field): array {
    if (!$node->hasField($field)) {
      return [];
    }
    return array_map(static fn(object $entity): array => [
      'id' => (int) $entity->id(),
      'label' => (string) $entity->label(),
    ], $node->get($field)->referencedEntities());
  }

  /**
   * Returns the public label for a list value.
   */
  private function listLabel(NodeInterface $node, string $field): string {
    $value = $this->value($node, $field);
    if ($value === '') {
      return '';
    }
    $allowed = $node->getFieldDefinition($field)->getFieldStorageDefinition()->getSetting('allowed_values') ?: [];
    return (string) ($allowed[$value] ?? $value);
  }

  /**
   * Returns a validated decimal value.
   */
  private function number(NodeInterface $node, string $field): ?float {
    $value = $this->value($node, $field);
    return $value === '' || !is_numeric($value) ? NULL : (float) $value;
  }

  /**
   * Returns a safe configured link.
   */
  private function link(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    try {
      return Url::fromUri((string) $node->get($field)->uri)->toString();
    }
    catch (\Exception) {
      return '';
    }
  }

  /**
   * Returns a safe telephone URI.
   */
  private function telephoneUri(string $value): string {
    $number = preg_replace('/[^0-9+]/', '', $value) ?: '';
    return $number !== '' ? 'tel:' . $number : '';
  }

  /**
   * Returns a language-aware review date.
   */
  private function date(NodeInterface $node, string $field): string {
    $value = $this->value($node, $field);
    $timestamp = $value !== '' ? strtotime($value . ' UTC') : FALSE;
    return $timestamp === FALSE ? '' : $this->regDateFormatter->format($timestamp, 'custom', 'j F Y');
  }

  /**
   * Whitelists public branch filters.
   */
  private function normalizeFilters(array $filters): array {
    return [
      'query' => mb_strtolower(mb_substr(trim(strip_tags((string) ($filters['query'] ?? ''))), 0, 120)),
      'province' => max(0, (int) ($filters['province'] ?? 0)),
      'district' => max(0, (int) ($filters['district'] ?? 0)),
      'entity' => in_array(($filters['entity'] ?? ''), ['reg', 'eucl', 'edcl'], TRUE) ? (string) $filters['entity'] : '',
      'service' => max(0, (int) ($filters['service'] ?? 0)),
    ];
  }

}
