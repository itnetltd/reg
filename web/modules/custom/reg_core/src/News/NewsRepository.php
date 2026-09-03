<?php

namespace Drupal\reg_core\News;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Queries and normalizes CMS-managed REG news without exposing edit metadata.
 */
final class NewsRepository implements NewsRepositoryInterface {

  private const CACHE_SECONDS = 300;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function homepage(): array {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:news:homepage:' . $langcode;
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }

    $ids = $this->publishedQuery()
      ->condition('field_reg_news_section', 'corporate')
      ->sort('field_reg_publication_date', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, 3)
      ->execute();

    $primary = [];
    $secondary = [];
    $tags = $this->listCacheTags();
    foreach ($this->loadTranslated($ids) as $index => $node) {
      if ($index === 0) {
        $primary = $this->item($node, 'reg_news_featured', 'eager');
        $tags = Cache::mergeTags($tags, $primary['cache_tags']);
        continue;
      }
      $story = $this->item($node, 'reg_news_thumbnail');
      $secondary[] = $story;
      $tags = Cache::mergeTags($tags, $story['cache_tags']);
    }

    $data = [
      'primary' => $primary,
      'secondary' => $secondary,
      'cache_tags' => $tags,
    ];
    $this->cache->set($cid, $data, $this->time->getRequestTime() + self::CACHE_SECONDS, $tags);
    return $data;
  }

  /**
   * {@inheritdoc}
   */
  public function archive(array $filters, int $page, int $limit): array {
    $requested_language = $filters['language'] ?? NULL;
    $language = in_array($requested_language, ['en', 'rw'], TRUE) ? $requested_language : ($requested_language === '' ? '' : NULL);
    $query = $this->publishedQuery($language);
    $section = in_array(($filters['section'] ?? 'corporate'), ['corporate', 'sports'], TRUE) ? $filters['section'] : 'corporate';
    $query->condition('field_reg_news_section', $section);
    $sport = max(0, (int) ($filters['sport'] ?? 0));
    if ($section === 'sports' && $sport) {
      $query->condition('field_reg_sports_sport.target_id', $sport);
    }
    $search = trim((string) ($filters['search'] ?? ''));
    if ($search !== '') {
      $search_group = $query->orConditionGroup()
        ->condition('title', $search, 'CONTAINS')
        ->condition('field_reg_summary', $search, 'CONTAINS')
        ->condition('body', $search, 'CONTAINS');
      $query->condition($search_group);
    }
    $category = max(0, (int) ($filters['category'] ?? 0));
    if ($category) {
      $query->condition('field_reg_news_category_term.target_id', $category);
    }
    $year = (string) ($filters['year'] ?? '');
    if (preg_match('/^\d{4}$/', $year)) {
      $query
        ->condition('field_reg_publication_date', $year . '-01-01T00:00:00', '>=')
        ->condition('field_reg_publication_date', $year . '-12-31T23:59:59', '<=');
    }
    $department = trim((string) ($filters['department'] ?? ''));
    if ($department !== '') {
      $query->condition('field_reg_content_department', $department);
    }

    $total = (int) (clone $query)->count()->execute();
    $ids = $query
      ->sort('field_reg_publication_date', 'DESC')
      ->sort('nid', 'DESC')
      ->range(max(0, $page) * $limit, $limit)
      ->execute();

    $items = [];
    $tags = $this->listCacheTags();
    foreach ($this->loadTranslated($ids) as $node) {
      $item = $this->item($node, 'reg_news_card');
      $items[] = $item;
      $tags = Cache::mergeTags($tags, $item['cache_tags']);
    }

    return [
      'items' => $items,
      'total' => $total,
      'cache_tags' => $tags,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function filterOptions(): array {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadTree('reg_news_category');
    $categories = [];
    foreach ($terms as $term_record) {
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_record->tid);
      if (!$term) {
        continue;
      }
      if ($term->hasTranslation($langcode)) {
        $term = $term->getTranslation($langcode);
      }
      $categories[] = ['id' => (int) $term->id(), 'label' => $term->label()];
    }

    $years = [];
    $departments = [];
    foreach ($this->loadTranslated($this->publishedQuery()->execute()) as $node) {
      $raw_date = $this->value($node, 'field_reg_publication_date');
      if (preg_match('/^(\d{4})-/', $raw_date, $matches)) {
        $years[(int) $matches[1]] = (int) $matches[1];
      }
      $department = $this->value($node, 'field_reg_content_department');
      if ($department !== '') {
        $departments[$department] = $department;
      }
    }
    rsort($years, SORT_NUMERIC);
    natcasesort($departments);

    $sports = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree('reg_sports_sport') as $term_record) {
      if (!in_array(mb_strtolower($term_record->name), ['basketball', 'volleyball'], TRUE)) {
        continue;
      }
      $term = $this->entityTypeManager->getStorage('taxonomy_term')->load($term_record->tid);
      if (!$term) {
        continue;
      }
      if ($term->hasTranslation($langcode)) {
        $term = $term->getTranslation($langcode);
      }
      $sports[] = ['id' => (int) $term->id(), 'label' => $term->label()];
    }

    return [
      'categories' => $categories,
      'years' => array_values($years),
      'departments' => array_values($departments),
      'sports' => $sports,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function item(NodeInterface $node, string $responsiveStyle = 'reg_news_card', string $loading = 'lazy'): array {
    $node = $this->translated($node);
    $category = $this->category($node);
    $date = $this->publicationDate($node);
    $summary = $this->value($node, 'field_reg_summary');
    if ($summary === '' && $node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body = (string) $node->get('body')->value;
      $summary = Unicode::truncate(trim(preg_replace('/\s+/u', ' ', strip_tags($body))), 220, TRUE, TRUE);
    }
    $image = $this->featuredImage($node, $responsiveStyle, $loading);

    return [
      'id' => (int) $node->id(),
      'title' => $node->label(),
      'summary' => $summary,
      'category' => $category,
      'date' => $date['formatted'],
      'date_iso' => $date['iso'],
      'department' => $this->value($node, 'field_reg_content_department'),
      'featured' => (bool) $this->value($node, 'field_reg_featured'),
      'section' => $this->value($node, 'field_reg_news_section') ?: 'corporate',
      'language' => $node->language()->getId(),
      'sport' => $this->termLabel($node, 'field_reg_sports_sport'),
      'url' => Url::fromRoute(
        $this->value($node, 'field_reg_news_section') === 'sports' ? 'reg_core.sports_news_detail' : 'reg_core.news_detail',
        ['node' => $node->id()],
        ['language' => $node->language()],
      )->toString(),
      'image' => $image,
      'cache_tags' => Cache::mergeTags($node->getCacheTags(), $image['cache_tags'] ?? []),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function article(NodeInterface $node): array {
    $node = $this->translated($node);
    $item = $this->item($node, 'reg_news_article', 'eager');
    $body = [];
    if ($node->hasField('body') && !$node->get('body')->isEmpty()) {
      $body_item = $node->get('body')->first();
      $body = [
        '#type' => 'processed_text',
        '#text' => (string) $body_item->value,
        '#format' => (string) $body_item->format,
        '#langcode' => $node->language()->getId(),
      ];
    }

    $gallery = [];
    $cache_tags = $item['cache_tags'];
    if ($node->hasField('field_reg_news_gallery')) {
      foreach ($node->get('field_reg_news_gallery')->referencedEntities() as $media) {
        if (!$media instanceof MediaInterface || !$media->access('view')) {
          continue;
        }
        $image = $this->imageFromMedia($media, 'reg_news_card', 'lazy');
        if ($image) {
          $gallery[] = $image;
          $cache_tags = Cache::mergeTags($cache_tags, $image['cache_tags']);
        }
      }
    }

    $video = [];
    if ($node->hasField('field_reg_video_media') && !$node->get('field_reg_video_media')->isEmpty()) {
      $media = $node->get('field_reg_video_media')->entity;
      if ($media instanceof MediaInterface && $media->access('view')) {
        $video = $this->entityTypeManager->getViewBuilder('media')->view($media, 'default');
        $cache_tags = Cache::mergeTags($cache_tags, $media->getCacheTags());
      }
    }

    $tags = [];
    if ($node->hasField('field_tags')) {
      foreach ($node->get('field_tags')->referencedEntities() as $term) {
        if ($term->hasTranslation($node->language()->getId())) {
          $term = $term->getTranslation($node->language()->getId());
        }
        $tags[] = $term->label();
        $cache_tags = Cache::mergeTags($cache_tags, $term->getCacheTags());
      }
    }

    $related = $this->related($node);
    foreach ($related as $related_item) {
      $cache_tags = Cache::mergeTags($cache_tags, $related_item['cache_tags']);
    }

    $canonical = $this->canonicalUrl($node);
    $share_query = rawurlencode($canonical);
    $share_title = rawurlencode($node->label());
    $meta_title = $this->value($node, 'field_reg_meta_title') ?: $node->label();
    $meta_description = $this->value($node, 'field_reg_meta_description') ?: $item['summary'];

    return array_replace($item, [
      'body' => $body,
      'image_caption' => $this->value($node, 'field_reg_image_caption'),
      'photo_credit' => $this->value($node, 'field_reg_photo_credit'),
      'gallery' => $gallery,
      'video' => $video,
      'tags' => $tags,
      'related' => $related,
      'translation' => $this->relatedTranslation($node),
      'back_url' => Url::fromRoute($item['section'] === 'sports' ? 'reg_core.sports_news' : 'reg_core.news')->toString(),
      'share' => [
        'x' => 'https://twitter.com/intent/tweet?url=' . $share_query . '&text=' . $share_title,
        'facebook' => 'https://www.facebook.com/sharer/sharer.php?u=' . $share_query,
        'linkedin' => 'https://www.linkedin.com/sharing/share-offsite/?url=' . $share_query,
        'copy' => $canonical,
      ],
      'seo' => [
        'title' => $meta_title,
        'description' => $meta_description,
        'canonical_url' => $canonical,
        'image_url' => $item['image']['absolute_url'] ?? '',
        'date_published' => $item['date_iso'],
        'date_modified' => gmdate(DATE_ATOM, $node->getChangedTime()),
      ],
      'cache_tags' => $cache_tags,
    ]);
  }

  /**
   * Returns the entity query shared by all public news collections.
   */
  private function publishedQuery(?string $langcode = NULL) {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_news')
      ->condition('status', NodeInterface::PUBLISHED);
    if ($langcode !== NULL && $langcode !== '') {
      $query->condition('langcode', $langcode);
    }
    elseif ($langcode === NULL) {
      $query->condition('langcode', $this->languageManager->getCurrentLanguage()->getId());
    }
    return $query;
  }

  /**
   * Loads ordered node IDs and resolves their current-language translation.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Translated nodes, retaining query order.
   */
  private function loadTranslated(array $ids): array {
    if ($ids === []) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->loadMultiple($ids);
    $nodes = [];
    foreach ($ids as $id) {
      if (isset($loaded[$id]) && $loaded[$id] instanceof NodeInterface) {
        $nodes[] = $this->translated($loaded[$id]);
      }
    }
    return $nodes;
  }

  /**
   * Resolves a node to the requested public language when available.
   */
  private function translated(NodeInterface $node): NodeInterface {
    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    return $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node;
  }

  /**
   * Returns one naturally integrated category value.
   */
  private function category(NodeInterface $node): array {
    if ($node->hasField('field_reg_news_category_term') && !$node->get('field_reg_news_category_term')->isEmpty()) {
      $term = $node->get('field_reg_news_category_term')->entity;
      if ($term) {
        if ($term->hasTranslation($node->language()->getId())) {
          $term = $term->getTranslation($node->language()->getId());
        }
        return ['id' => (int) $term->id(), 'label' => $term->label()];
      }
    }
    $legacy = $this->value($node, 'field_reg_news_category');
    $labels = [
      'news' => 'Corporate News',
      'press_release' => 'Corporate News',
      'announcement' => 'Announcements',
      'campaign' => 'Community',
    ];
    return ['id' => 0, 'label' => $labels[$legacy] ?? 'News'];
  }

  private function termLabel(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $term = $node->get($field)->entity;
    if (!$term) return '';
    if ($term->hasTranslation($node->language()->getId())) $term = $term->getTranslation($node->language()->getId());
    return $term->label();
  }

  /**
   * Returns an editorial publication date without presenting field labels.
   */
  private function publicationDate(NodeInterface $node): array {
    $raw = $this->value($node, 'field_reg_publication_date');
    if ($raw === '') {
      return ['formatted' => '', 'iso' => ''];
    }
    $timestamp = strtotime($raw . ' UTC');
    if ($timestamp === FALSE) {
      return ['formatted' => '', 'iso' => ''];
    }
    return [
      'formatted' => $this->dateFormatter->format($timestamp, 'custom', 'j F Y'),
      'iso' => gmdate(DATE_ATOM, $timestamp),
    ];
  }

  /**
   * Loads the Featured Image Media reference.
   */
  private function featuredImage(NodeInterface $node, string $responsiveStyle, string $loading): array {
    if (!$node->hasField('field_reg_featured_image') || $node->get('field_reg_featured_image')->isEmpty()) {
      return [];
    }
    $media = $node->get('field_reg_featured_image')->entity;
    return $media instanceof MediaInterface && $media->access('view')
      ? $this->imageFromMedia($media, $responsiveStyle, $loading, $node->label())
      : [];
  }

  /**
   * Builds a Drupal responsive-image render array from Image Media.
   */
  private function imageFromMedia(MediaInterface $media, string $responsiveStyle, string $loading, string $fallbackAlt = ''): array {
    $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    if ($source_field === '' || !$media->hasField($source_field) || $media->get($source_field)->isEmpty()) {
      return [];
    }
    $image_item = $media->get($source_field)->first();
    $file = $image_item?->entity;
    if (!$file) {
      return [];
    }
    $alt = trim((string) ($image_item->alt ?? '')) ?: $fallbackAlt;
    $uri = $file->getFileUri();

    return [
      'render' => [
        '#theme' => 'responsive_image',
        '#responsive_image_style_id' => $responsiveStyle,
        '#uri' => $uri,
        '#width' => (int) ($image_item->width ?? 0),
        '#height' => (int) ($image_item->height ?? 0),
        '#attributes' => [
          'alt' => $alt,
          'loading' => $loading,
          'decoding' => 'async',
        ],
      ],
      'alt' => $alt,
      'absolute_url' => $this->fileUrlGenerator->generateAbsoluteString($uri),
      'cache_tags' => Cache::mergeTags($media->getCacheTags(), $file->getCacheTags()),
    ];
  }

  /**
   * Returns explicit, category-matched, then latest related stories.
   */
  private function related(NodeInterface $node): array {
    $candidates = [];
    $section = $this->value($node, 'field_reg_news_section') ?: 'corporate';
    if ($node->hasField('field_reg_related_news')) {
      foreach ($node->get('field_reg_related_news')->referencedEntities() as $related) {
        if ($related instanceof NodeInterface && $related->id() !== $node->id()) {
          $related = $this->translated($related);
          if ($related->isPublished()) {
            $candidates[(int) $related->id()] = $related;
          }
        }
      }
    }

    $category_id = (int) ($this->category($node)['id'] ?? 0);
    if (count($candidates) < 3 && $category_id) {
      $query = $this->publishedQuery()
        ->condition('nid', $node->id(), '<>')
        ->condition('field_reg_news_section', $section)
        ->condition('field_reg_news_category_term.target_id', $category_id)
        ->sort('field_reg_publication_date', 'DESC')
        ->range(0, 3);
      foreach ($this->loadTranslated($query->execute()) as $candidate) {
        $candidates[(int) $candidate->id()] = $candidate;
      }
    }
    if (count($candidates) < 3) {
      $query = $this->publishedQuery()
        ->condition('nid', $node->id(), '<>')
        ->condition('field_reg_news_section', $section)
        ->sort('field_reg_publication_date', 'DESC')
        ->range(0, 6);
      foreach ($this->loadTranslated($query->execute()) as $candidate) {
        $candidates[(int) $candidate->id()] = $candidate;
        if (count($candidates) >= 3) {
          break;
        }
      }
    }

    $items = [];
    foreach (array_slice($candidates, 0, 3, TRUE) as $candidate) {
      $items[] = $this->item($candidate, 'reg_news_card');
    }
    return $items;
  }

  private function relatedTranslation(NodeInterface $node): array {
    if (!$node->hasField('field_reg_related_translation') || $node->get('field_reg_related_translation')->isEmpty()) return [];
    $related = $node->get('field_reg_related_translation')->entity;
    if (!$related instanceof NodeInterface || !$related->isPublished()) return [];
    return [
      'language' => $related->language()->getId(),
      'label' => $related->language()->getId() === 'rw' ? 'Read this story in Kinyarwanda' : 'Read this story in English',
      'url' => Url::fromRoute(
        $section = $this->value($related, 'field_reg_news_section') === 'sports' ? 'reg_core.sports_news_detail' : 'reg_core.news_detail',
        ['node' => $related->id()], ['language' => $related->language()],
      )->toString(),
    ];
  }

  /**
   * Returns a canonical override or the node's canonical route.
   */
  private function canonicalUrl(NodeInterface $node): string {
    if ($node->hasField('field_reg_canonical_url') && !$node->get('field_reg_canonical_url')->isEmpty()) {
      try {
        return $node->get('field_reg_canonical_url')->first()->getUrl()->setAbsolute()->toString();
      }
      catch (\Exception) {
        // Fall through to the managed Drupal URL.
      }
    }
    return $node->toUrl('canonical', ['absolute' => TRUE, 'language' => $node->language()])->toString();
  }

  /**
   * Returns a sanitized plain field value.
   */
  private function value(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) $node->get($fieldName)->first()?->get('value')->getValue()));
  }

  /**
   * Returns broad invalidation tags for public newsroom collections.
   */
  private function listCacheTags(): array {
    return [
      'node_list',
      'node_list:reg_news',
      'media_list',
      'file_list',
      'taxonomy_term_list:reg_news_category',
    ];
  }

}
