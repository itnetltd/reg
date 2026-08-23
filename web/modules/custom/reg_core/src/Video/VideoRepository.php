<?php

namespace Drupal\reg_core\Video;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Cached repository for CMS-managed homepage and archive videos.
 */
final class VideoRepository implements VideoRepositoryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly DateFormatterInterface $regDateFormatter,
    private readonly ModuleExtensionList $moduleExtensionList,
    private readonly TranslationInterface $stringTranslation,
    private readonly VideoProviderManagerInterface $providerManager,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function featured(int $limit = 5): array {
    $limit = max(1, min(5, $limit));
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:videos:featured:' . $langcode . ':' . $limit;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_video')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('langcode', $langcode)
      ->condition('field_reg_featured', 1)
      ->sort('field_reg_order', 'ASC')
      ->sort('field_reg_publication_date', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, $limit * 3)
      ->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $item = $node instanceof NodeInterface ? $this->normalize($node, $langcode) : [];
      if ($item !== []) {
        $items[] = $item;
      }
      if (count($items) >= $limit) {
        break;
      }
    }

    $this->cache->set($cid, $items, $this->time->getRequestTime() + 300, $this->cacheTags());
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function archive(array $filters, int $page, int $limit = 9): array {
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_video')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('langcode', $langcode);

    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
      $or = $query->orConditionGroup()
        ->condition('title', $search, 'CONTAINS')
        ->condition('field_reg_description.value', $search, 'CONTAINS');
      $query->condition($or);
    }
    $category = max(0, (int) ($filters['category'] ?? 0));
    if ($category > 0) {
      $query->condition('field_reg_video_category.target_id', $category);
    }
    $year = (string) ($filters['year'] ?? '');
    if (preg_match('/^\d{4}$/', $year)) {
      $query
        ->condition('field_reg_publication_date', $year . '-01-01T00:00:00', '>=')
        ->condition('field_reg_publication_date', $year . '-12-31T23:59:59', '<=');
    }

    $query
      ->sort('field_reg_publication_date', 'DESC')
      ->sort('nid', 'DESC');
    $count_query = clone $query;
    $total = (int) $count_query->count()->execute();
    $page = max(0, $page);
    $limit = max(1, min(24, $limit));
    $ids = $query->range($page * $limit, $limit)->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $item = $node instanceof NodeInterface ? $this->normalize($node, $langcode) : [];
      if ($item !== []) {
        $items[] = $item;
      }
    }
    return ['items' => $items, 'total' => $total];
  }

  /**
   * {@inheritdoc}
   */
  public function filterOptions(): array {
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $terms = [];
    $term_storage = $this->regEntityTypeManager->getStorage('taxonomy_term');
    $term_ids = $term_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('vid', 'reg_video_category')
      ->sort('name', 'ASC')
      ->execute();
    foreach ($term_storage->loadMultiple($term_ids) as $term) {
      if (!$term instanceof TermInterface || !$term->access('view')) {
        continue;
      }
      if ($term->hasTranslation($langcode)) {
        $term = $term->getTranslation($langcode);
      }
      $terms[] = ['id' => (int) $term->id(), 'label' => (string) $term->label()];
    }

    $years = [];
    $node_storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $node_storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_video')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('langcode', $langcode)
      ->exists('field_reg_publication_date')
      ->execute();
    foreach ($node_storage->loadMultiple($ids) as $node) {
      $date = $node instanceof NodeInterface ? $this->value($node, 'field_reg_publication_date') : '';
      if (preg_match('/^(\d{4})-/', $date, $matches)) {
        $years[$matches[1]] = $matches[1];
      }
    }
    rsort($years, SORT_STRING);
    return ['categories' => $terms, 'years' => array_values($years)];
  }

  /**
   * Normalizes one publicly playable video translation.
   */
  private function normalize(NodeInterface $node, string $langcode): array {
    if ($node->hasTranslation($langcode)) {
      $translation = $node->getTranslation($langcode);
      if ($translation->isPublished()) {
        $node = $translation;
      }
    }
    if (!$node->isPublished() || !$node->access('view')) {
      return [];
    }

    $provider_key = $this->value($node, 'field_reg_video_provider');
    $playback = $this->providerManager->resolve($provider_key, $this->link($node, 'field_reg_video_url'));
    if ($playback === []) {
      return [];
    }
    $title = (string) $node->label();
    $thumbnail = $this->mediaThumbnail($node, $title);
    if ($thumbnail === [] && $playback['thumbnail_url'] !== '') {
      $thumbnail = [
        'url' => $playback['thumbnail_url'],
        'alt' => (string) $this->stringTranslation->translate('@title video thumbnail', ['@title' => $title]),
        'srcset' => '',
        'sizes' => '',
        'source' => 'provider',
      ];
    }
    if ($thumbnail === []) {
      $thumbnail = [
        'url' => base_path() . $this->moduleExtensionList->getPath('reg_core') . '/assets/images/reg-video-fallback.svg',
        'alt' => (string) $this->stringTranslation->translate('@title video thumbnail', ['@title' => $title]),
        'srcset' => '',
        'sizes' => '',
        'source' => 'fallback',
      ];
    }

    $category = '';
    if ($node->hasField('field_reg_video_category') && !$node->get('field_reg_video_category')->isEmpty()) {
      $term = $node->get('field_reg_video_category')->entity;
      if ($term instanceof TermInterface) {
        if ($term->hasTranslation($langcode)) {
          $term = $term->getTranslation($langcode);
        }
        $category = (string) $term->label();
      }
    }
    $raw_date = $this->value($node, 'field_reg_publication_date');
    $timestamp = $raw_date !== '' ? strtotime($raw_date . ' UTC') : FALSE;
    $provider_labels = $this->providerManager->providerLabels();
    return [
      'id' => (int) $node->id(),
      'title' => $title,
      'description' => trim(strip_tags($this->value($node, 'field_reg_description'))),
      'provider' => $provider_key,
      'provider_label' => $provider_labels[$provider_key] ?? $provider_key,
      'public_url' => $playback['public_url'],
      'embed_url' => $playback['embed_url'],
      'direct_video_url' => $playback['direct_video_url'],
      'thumbnail' => $thumbnail,
      'category' => $category,
      'date' => $timestamp ? $this->regDateFormatter->format($timestamp, 'custom', 'd M Y') : '',
      'date_iso' => $timestamp ? gmdate('Y-m-d', $timestamp) : '',
      'entity' => $this->listLabel($node, 'field_reg_related_entity'),
    ];
  }

  /**
   * Returns responsive Drupal Media derivatives for an editor thumbnail.
   */
  private function mediaThumbnail(NodeInterface $node, string $title): array {
    if (!$node->hasField('field_reg_thumbnail') || $node->get('field_reg_thumbnail')->isEmpty()) {
      return [];
    }
    $media = $node->get('field_reg_thumbnail')->entity;
    if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
      return [];
    }
    $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $item = $source !== '' ? $media->get($source)->first() : NULL;
    $file = $item?->entity;
    if (!$file) {
      return [];
    }
    $sources = [];
    $source_width = max(0, (int) ($item?->get('width')->getValue() ?? 0));
    foreach ([480 => 'reg_homepage_video_small', 960 => 'reg_homepage_video', 1440 => 'reg_homepage_video_large'] as $width => $style_name) {
      // Do not advertise a derivative wider than a small source image can
      // produce when the style deliberately has upscaling disabled.
      if ($source_width > 0 && $width > max(480, $source_width)) {
        continue;
      }
      $style = $this->regEntityTypeManager->getStorage('image_style')->load($style_name);
      if ($style) {
        $sources[$source_width > 0 ? min($width, $source_width) : $width] = $style->buildUrl($file->getFileUri());
      }
    }
    if ($sources === []) {
      return [];
    }
    $alt = trim((string) ($item?->get('alt')->getValue() ?? ''))
      ?: (string) $this->stringTranslation->translate('@title video thumbnail', ['@title' => $title]);
    return [
      'url' => end($sources),
      'alt' => $alt,
      'srcset' => implode(', ', array_map(static fn(string $url, int $width): string => $url . ' ' . $width . 'w', $sources, array_keys($sources))),
      'sizes' => '(max-width: 48rem) calc(100vw - 40px), (max-width: 75rem) 70vw, 680px',
      'source' => 'media',
    ];
  }

  /**
   * Returns a plain field value.
   */
  private function value(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    return trim((string) ($node->get($field_name)->first()?->get('value')->getValue() ?? ''));
  }

  /**
   * Returns the public URL from a Link field.
   */
  private function link(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    try {
      return $node->get($field_name)->first()->getUrl()->toString();
    }
    catch (\Exception) {
      return '';
    }
  }

  /**
   * Returns an allowed list value's editor-facing label.
   */
  private function listLabel(NodeInterface $node, string $field_name): string {
    $value = $this->value($node, $field_name);
    if ($value === '' || !$node->hasField($field_name)) {
      return '';
    }
    $allowed = $node->getFieldDefinition($field_name)->getFieldStorageDefinition()->getSetting('allowed_values') ?: [];
    return (string) ($allowed[$value] ?? $value);
  }

  /**
   * Cache dependencies shared by homepage video results.
   */
  private function cacheTags(): array {
    return [
      'node_list',
      'node_list:reg_video',
      'media_list',
      'file_list',
      'taxonomy_term_list:reg_video_category',
      'config:image.style.reg_homepage_video_small',
      'config:image.style.reg_homepage_video',
      'config:image.style.reg_homepage_video_large',
    ];
  }

}
