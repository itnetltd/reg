<?php

namespace Drupal\reg_core\Social;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Queries native social-post records without contacting social platforms.
 */
final class SocialPostRepository implements SocialPostRepositoryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function homepageXPosts(int $limit = 2): array {
    $limit = max(0, min(2, $limit));
    $tags = ['node_list', 'node_list:reg_social_post', 'media_list', 'file_list'];
    if ($limit === 0) {
      return ['items' => [], 'cache_tags' => $tags];
    }

    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_social_post')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('langcode', $langcode)
      ->condition('field_reg_social_platform', 'x')
      ->condition('field_reg_social_homepage', 1)
      ->sort('field_reg_social_order', 'ASC')
      ->sort('field_reg_social_published_at', 'DESC')
      ->sort('nid', 'DESC')
      ->range(0, $limit);

    $ids = $query->execute();
    $storage = $this->entityTypeManager->getStorage('node');
    $loaded = $storage->loadMultiple($ids);
    $items = [];
    foreach ($ids as $id) {
      $node = $loaded[$id] ?? NULL;
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if ($node->hasTranslation($langcode)) {
        $node = $node->getTranslation($langcode);
      }
      $item = $this->item($node);
      if ($item === []) {
        continue;
      }
      $items[] = $item;
      $tags = Cache::mergeTags($tags, $item['cache_tags']);
    }

    return ['items' => $items, 'cache_tags' => $tags];
  }

  /**
   * Normalizes one approved social-post record for Twig.
   */
  private function item(NodeInterface $node): array {
    $url = $this->postUrl($node);
    $text = $this->value($node, 'field_reg_social_post_text');
    if ($url === '' || $text === '') {
      return [];
    }

    $raw_date = $this->value($node, 'field_reg_social_published_at');
    $timestamp = $raw_date !== '' ? strtotime($raw_date . ' UTC') : FALSE;
    $image = $this->image($node);

    return [
      'id' => (int) $node->id(),
      'text' => preg_replace('/\s+/u', ' ', $text),
      'url' => $url,
      'date' => $timestamp !== FALSE ? $this->dateFormatter->format($timestamp, 'custom', 'd M Y') : '',
      'date_iso' => $timestamp !== FALSE ? gmdate(DATE_ATOM, $timestamp) : '',
      'image' => $image,
      'author_name' => $this->value($node, 'field_reg_social_author_name'),
      'author_handle' => $this->value($node, 'field_reg_social_author_handle'),
      'source_type' => $this->value($node, 'field_reg_social_source'),
      'cache_tags' => Cache::mergeTags($node->getCacheTags(), $image['cache_tags'] ?? []),
    ];
  }

  /**
   * Allows only posts belonging to the centrally configured official account.
   */
  private function postUrl(NodeInterface $node): string {
    if (!$node->hasField('field_reg_social_post_url') || $node->get('field_reg_social_post_url')->isEmpty()) {
      return '';
    }
    $url = trim((string) $node->get('field_reg_social_post_url')->uri);
    $parts = parse_url($url);
    $profile = parse_url((string) $this->configFactory->get('reg_core.social_media')->get('platforms.x.url'));
    if (!is_array($parts) || !is_array($profile)) {
      return '';
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    $profile_handle = trim((string) ($profile['path'] ?? ''), '/');
    $path = trim((string) ($parts['path'] ?? ''), '/');
    if (
      strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || !in_array($host, ['x.com', 'www.x.com', 'twitter.com', 'www.twitter.com'], TRUE)
      || $profile_handle === ''
      || !preg_match('#^' . preg_quote($profile_handle, '#') . '/status/[0-9]+$#i', $path)
      || isset($parts['user'])
      || isset($parts['pass'])
    ) {
      return '';
    }
    return 'https://x.com/' . $path;
  }

  /**
   * Builds an optional responsive thumbnail from Image media.
   */
  private function image(NodeInterface $node): array {
    $field_name = NULL;
    foreach (['field_reg_social_image', 'field_reg_social_video_thumb'] as $candidate) {
      if ($node->hasField($candidate) && !$node->get($candidate)->isEmpty()) {
        $field_name = $candidate;
        break;
      }
    }
    if ($field_name === NULL) {
      return [];
    }
    $media = $node->get($field_name)->entity;
    if (!$media instanceof MediaInterface || !$media->access('view')) {
      return [];
    }
    $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    if ($source === '' || !$media->hasField($source) || $media->get($source)->isEmpty()) {
      return [];
    }
    $image_item = $media->get($source)->first();
    $file = $image_item?->entity;
    if (!$file) {
      return [];
    }
    return [
      'render' => [
        '#theme' => 'responsive_image',
        '#responsive_image_style_id' => 'reg_news_thumbnail',
        '#uri' => $file->getFileUri(),
        '#width' => (int) ($image_item->width ?? 0),
        '#height' => (int) ($image_item->height ?? 0),
        '#attributes' => [
          'alt' => trim((string) ($image_item->alt ?? '')),
          'loading' => 'lazy',
          'decoding' => 'async',
        ],
      ],
      'cache_tags' => Cache::mergeTags($media->getCacheTags(), $file->getCacheTags()),
    ];
  }

  /**
   * Returns a field's plain value.
   */
  private function value(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) $node->get($field_name)->value));
  }

}
