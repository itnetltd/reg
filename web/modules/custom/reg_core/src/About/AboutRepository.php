<?php

namespace Drupal\reg_core\About;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Cached repository for the structured About REG section.
 */
final class AboutRepository implements AboutRepositoryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly FileUrlGeneratorInterface $regFileUrlGenerator,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function page(string $key): ?array {
    $key = preg_replace('/[^a-z0-9_]/', '', $key) ?: '';
    $cid = $this->cacheId('page:' . $key);
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : NULL;
    }
    $nodes = $this->loadNodes('reg_about_page', [
      'field_reg_about_key' => $key,
      'field_reg_active' => 1,
    ], 1);
    $page = isset($nodes[0]) ? $this->normalizePage($nodes[0]) : NULL;
    $this->cache->set($cid, $page, $this->time->getRequestTime() + 300, [
      'node_list:reg_about_page',
      'media_list',
      'file_list',
    ]);
    return $page;
  }

  /**
   * {@inheritdoc}
   */
  public function values(): array {
    $cid = $this->cacheId('values');
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }
    $items = array_map(fn(NodeInterface $node): array => [
      'id' => (int) $node->id(),
      'title' => (string) $node->label(),
      'description' => $this->plain($node, 'field_reg_description'),
      'icon' => $this->value($node, 'field_reg_value_icon'),
    ], $this->loadNodes('reg_value', ['field_reg_active' => 1]));
    return $this->remember($cid, $items, ['node_list:reg_value']);
  }

  /**
   * {@inheritdoc}
   */
  public function leaders(string $group): array {
    $group = in_array($group, ['board', 'executive'], TRUE) ? $group : '';
    if ($group === '') {
      return [];
    }
    $cid = $this->cacheId('leaders:' . $group);
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }
    $items = array_map(fn(NodeInterface $node): array => [
      'id' => (int) $node->id(),
      'name' => (string) $node->label(),
      'role' => $this->value($node, 'field_reg_role_title'),
      'entity' => $this->listLabel($node, 'field_reg_entity'),
      'bio' => $this->processed($node, 'body'),
      'portrait' => $this->image($node, 'field_reg_portrait', 'reg_about_portrait'),
      'initials' => $this->initials((string) $node->label()),
    ], $this->loadNodes('reg_leader', [
      'field_reg_active' => 1,
      'field_reg_leadership_group' => $group,
    ]));
    return $this->remember($cid, $items, ['node_list:reg_leader', 'media_list', 'file_list', 'config:image.style.reg_about_portrait']);
  }

  /**
   * {@inheritdoc}
   */
  public function partners(string $type = ''): array {
    $type = in_array($type, ['stakeholder', 'development_partner'], TRUE) ? $type : '';
    $cid = $this->cacheId('partners:' . ($type ?: 'all'));
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }
    $conditions = ['field_reg_active' => 1];
    if ($type !== '') {
      $conditions['field_reg_partner_type'] = $type;
    }
    $items = [];
    foreach ($this->loadNodes('reg_partner', $conditions) as $node) {
      $items[] = [
        'id' => (int) $node->id(),
        'name' => (string) $node->label(),
        'type' => $this->value($node, 'field_reg_partner_type'),
        'description' => $this->plain($node, 'field_reg_description'),
        'logo' => $this->image($node, 'field_reg_partner_logo', 'reg_partner_logo'),
        'url' => $this->link($node, 'field_reg_partner_url'),
      ];
    }
    return $this->remember($cid, $items, ['node_list:reg_partner', 'media_list', 'file_list', 'config:image.style.reg_partner_logo']);
  }

  /**
   * {@inheritdoc}
   */
  public function employees(): array {
    $cid = $this->cacheId('employees');
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }
    $items = array_map(fn(NodeInterface $node): array => [
      'id' => (int) $node->id(),
      'name' => (string) $node->label(),
      'role' => $this->value($node, 'field_reg_employee_role'),
      'entity' => $this->listLabel($node, 'field_reg_entity'),
      'recognition' => $this->value($node, 'field_reg_recognition_label'),
      'period' => $this->value($node, 'field_reg_recognition_period'),
      'portrait' => $this->image($node, 'field_reg_portrait', 'reg_about_portrait'),
      'initials' => $this->initials((string) $node->label()),
    ], $this->loadNodes('reg_employee_recognition', ['field_reg_active' => 1]));
    return $this->remember($cid, $items, ['node_list:reg_employee_recognition', 'media_list', 'file_list', 'config:image.style.reg_about_portrait']);
  }

  /**
   * {@inheritdoc}
   */
  public function branchCount(): int {
    $cid = $this->cacheId('branch_count');
    if ($cached = $this->cache->get($cid)) {
      return (int) $cached->data;
    }
    $query = $this->regEntityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_branch')
      ->condition('status', NodeInterface::PUBLISHED);
    $field = $this->regEntityTypeManager->getStorage('field_config')->load('node.reg_branch.field_reg_active');
    if ($field) {
      $query->condition('field_reg_active', 1);
    }
    return $this->remember($cid, (int) $query->count()->execute(), ['node_list:reg_branch']);
  }

  /**
   * Loads published nodes and applies an available interface translation.
   */
  private function loadNodes(string $bundle, array $conditions = [], int $limit = 0): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', NodeInterface::PUBLISHED);
    foreach ($conditions as $field => $value) {
      $query->condition($field, $value);
    }
    if ($this->regEntityTypeManager->getStorage('field_config')->load("node.$bundle.field_reg_order")) {
      $query->sort('field_reg_order', 'ASC');
    }
    $query->sort('nid', 'ASC');
    if ($limit > 0) {
      $query->range(0, $limit);
    }
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $nodes = [];
    foreach ($storage->loadMultiple($query->execute()) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      if ($node->hasTranslation($langcode)) {
        $translation = $node->getTranslation($langcode);
        if ($translation->isPublished() && $translation->access('view')) {
          $node = $translation;
        }
      }
      $nodes[] = $node;
    }
    return $nodes;
  }

  /**
   * Normalizes one About page without exposing internal review metadata.
   */
  private function normalizePage(NodeInterface $node): array {
    return [
      'id' => (int) $node->id(),
      'key' => $this->value($node, 'field_reg_about_key'),
      'title' => (string) $node->label(),
      'summary' => $this->value($node, 'field_reg_summary'),
      'body' => $this->processed($node, 'body'),
      'vision' => $this->value($node, 'field_reg_vision'),
      'mission' => $this->value($node, 'field_reg_mission'),
      'image' => $this->image($node, 'field_reg_featured_image', 'reg_about_feature'),
      'meta_title' => $this->value($node, 'field_reg_meta_title') ?: (string) $node->label(),
      'meta_description' => $this->value($node, 'field_reg_meta_description') ?: $this->value($node, 'field_reg_summary'),
      'historical_review' => $this->value($node, 'field_reg_review_status') === 'needs_review',
    ];
  }

  /**
   * Returns a processed-text render array.
   */
  private function processed(NodeInterface $node, string $field): array {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return [];
    }
    $item = $node->get($field)->first();
    return [
      '#type' => 'processed_text',
      '#text' => (string) ($item?->value ?? ''),
      '#format' => (string) ($item?->format ?: 'basic_html'),
    ];
  }

  /**
   * Returns one styled Media image.
   */
  private function image(NodeInterface $node, string $field, string $style): array {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return [];
    }
    $media = $node->get($field)->entity;
    if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
      return [];
    }
    $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $item = $source !== '' ? $media->get($source)->first() : NULL;
    $file = $item?->entity;
    $image_style = $this->regEntityTypeManager->getStorage('image_style')->load($style);
    if (!$file || !$image_style) {
      return [];
    }
    $alt = trim((string) ($item?->get('alt')->getValue() ?? '')) ?: (string) $media->label();
    return [
      'url' => $this->regFileUrlGenerator->transformRelative($image_style->buildUrl($file->getFileUri())),
      'alt' => $alt,
    ];
  }

  /**
   * Returns a safe link model.
   */
  private function link(NodeInterface $node, string $field): array {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return [];
    }
    try {
      $url = $node->get($field)->first()->getUrl();
      return ['url' => $url->toString(), 'external' => $url->isExternal()];
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Returns a field's plain stored value.
   */
  private function value(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) ($node->get($field)->first()?->getValue()['value'] ?? '')));
  }

  /**
   * Returns plain text from a formatted field.
   */
  private function plain(NodeInterface $node, string $field): string {
    return preg_replace('/\s+/', ' ', $this->value($node, $field)) ?: '';
  }

  /**
   * Returns the configured label for one list item.
   */
  private function listLabel(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    return (string) ($node->get($field)->first()?->getPossibleOptions()[$node->get($field)->value] ?? $node->get($field)->value ?? '');
  }

  /**
   * Creates stable initials for portrait fallbacks.
   */
  private function initials(string $name): string {
    $parts = array_values(array_filter(preg_split('/\s+/', trim($name)) ?: []));
    if (!$parts) {
      return 'REG';
    }
    $letters = mb_substr($parts[0], 0, 1);
    if (count($parts) > 1) {
      $letters .= mb_substr($parts[count($parts) - 1], 0, 1);
    }
    return mb_strtoupper($letters);
  }

  /**
   * Builds a language-specific cache ID.
   */
  private function cacheId(string $suffix): string {
    return 'reg_core:about:' . $this->regLanguageManager->getCurrentLanguage()->getId() . ':' . $suffix;
  }

  /**
   * Stores one repository result and returns it.
   */
  private function remember(string $cid, mixed $value, array $tags): mixed {
    $this->cache->set($cid, $value, $this->time->getRequestTime() + 300, $tags);
    return $value;
  }

}
