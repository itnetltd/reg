<?php

namespace Drupal\reg_core\Homepage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Cached repository for CMS-managed homepage content.
 */
final class HomepageRepository implements HomepageRepositoryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function hero(): ?array {
    return $this->heroes()['heroes'][0] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function heroes(): array {
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:homepage:heroes:v2:' . $langcode;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : ['heroes' => [], 'max_age' => 0];
    }

    $now = $this->time->getRequestTime();
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_homepage_hero')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_active', 1)
      ->sort('field_reg_order', 'ASC')
      ->sort('created', 'DESC')
      ->range(0, 20)
      ->execute();
    $heroes = [];
    $boundaries = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      if ($node->language()->getId() !== $langcode) {
        if (!$node->hasTranslation($langcode)) {
          continue;
        }
        $node = $node->getTranslation($langcode);
        if (!$node->isPublished() || !$node->access('view')) {
          continue;
        }
      }
      $candidate = $this->normalizeHero($node);
      if ($candidate['start_timestamp'] > $now) {
        $boundaries[] = $candidate['start_timestamp'];
      }
      if ($candidate['end_timestamp'] >= $now) {
        $boundaries[] = $candidate['end_timestamp'] + 1;
      }
      if (self::heroIsEligible($candidate, $now)) {
        $heroes[] = $candidate;
      }
    }

    self::sortHeroes($heroes);
    $expires = $now + 300;
    if ($boundaries) {
      $expires = min($expires, min($boundaries));
    }
    $result = [
      'heroes' => $heroes,
      'max_age' => max(0, $expires - $now),
    ];
    $this->cache->set($cid, $result, $expires, [
      'node_list',
      'node_list:reg_homepage_hero',
      'media_list',
      'file_list',
      'config:image.style.reg_homepage_hero_desktop',
      'config:image.style.reg_homepage_hero_mobile',
    ]);
    return $result;
  }

  /**
   * Tests a normalized hero's publication and scheduling state.
   */
  public static function heroIsEligible(array $hero, int $now): bool {
    $start = (int) ($hero['start_timestamp'] ?? 0);
    $end = (int) ($hero['end_timestamp'] ?? 0);
    return !empty($hero['published'])
      && !empty($hero['active'])
      && ($start === 0 || $start <= $now)
      && ($end === 0 || $end >= $now);
  }

  /**
   * Sorts by ascending editor weight, then newest creation time.
   */
  public static function sortHeroes(array &$heroes): void {
    usort($heroes, static function (array $left, array $right): int {
      $weight = ((int) ($left['weight'] ?? 0)) <=> ((int) ($right['weight'] ?? 0));
      return $weight !== 0
        ? $weight
        : ((int) ($right['created'] ?? 0)) <=> ((int) ($left['created'] ?? 0));
    });
  }

  /**
   * Converts a homepage hero node into safe template data.
   */
  private function normalizeHero(NodeInterface $node): array {
    $desktop = $this->image($node, 'field_reg_desktop_hero_image', 'reg_homepage_hero_desktop');
    $mobile = $this->image($node, 'field_reg_mobile_hero_image', 'reg_homepage_hero_mobile');
    if (!$mobile && $desktop) {
      $mobile = $this->image($node, 'field_reg_desktop_hero_image', 'reg_homepage_hero_mobile');
    }
    $alignment = $this->value($node, 'field_reg_text_alignment');
    $overlay = $this->value($node, 'field_reg_overlay_strength');
    $text_theme = $this->value($node, 'field_reg_text_theme');
    return [
      'id' => (int) $node->id(),
      'title' => (string) $node->label(),
      'eyebrow' => $this->value($node, 'field_reg_eyebrow'),
      'subtitle' => $this->value($node, 'field_reg_subtitle'),
      'desktop_image' => $desktop,
      'mobile_image' => $mobile,
      'primary_cta' => $this->cta($node, 'field_reg_primary_cta_label', 'field_reg_primary_cta_url'),
      'secondary_cta' => $this->cta($node, 'field_reg_secondary_cta_label', 'field_reg_secondary_cta_url'),
      'alignment' => in_array($alignment, ['left', 'center', 'right'], TRUE) ? $alignment : 'left',
      'overlay' => in_array($overlay, ['none', 'light', 'medium', 'strong'], TRUE) ? $overlay : 'medium',
      'text_theme' => in_array($text_theme, ['light_background', 'dark_background'], TRUE) ? $text_theme : 'dark_background',
      'start_timestamp' => $this->timestamp($node, 'field_reg_hero_start'),
      'end_timestamp' => $this->timestamp($node, 'field_reg_hero_end'),
      'weight' => (int) $this->value($node, 'field_reg_order'),
      'active' => (bool) $this->value($node, 'field_reg_active'),
      'published' => $node->isPublished(),
      'created' => $node->getCreatedTime(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function statistics(): array {
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:homepage:statistics:' . $langcode;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_fact')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_show_homepage', 1)
      ->condition('field_reg_active', 1)
      ->sort('field_reg_order', 'ASC')
      ->sort('nid', 'ASC')
      ->execute();
    $statistics = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      if ($node->hasTranslation($langcode)) {
        $translation = $node->getTranslation($langcode);
        if ($translation->isPublished() && $translation->access('view')) {
          $node = $translation;
        }
      }
      $raw_value = $this->value($node, 'field_reg_fact_value');
      if ($raw_value === '') {
        continue;
      }
      $configured_decimals = $this->value($node, 'field_reg_decimal_places');
      $decimals = $configured_decimals !== ''
        ? max(0, min(6, (int) $configured_decimals))
        : (str_contains($raw_value, '.')
          ? strlen(rtrim(substr($raw_value, strpos($raw_value, '.') + 1), '0'))
          : 0);
      $statistics[] = [
        'id' => (int) $node->id(),
        'key' => $this->value($node, 'field_reg_fact_key'),
        'title' => $this->value($node, 'field_reg_metric') ?: (string) $node->label(),
        'value' => number_format((float) $raw_value, $decimals, '.', ','),
        'suffix' => $this->value($node, 'field_reg_fact_unit'),
        'supporting_text' => $this->value($node, 'field_reg_supporting_text'),
      ];
    }

    $this->cache->set($cid, $statistics, $this->time->getRequestTime() + 300, [
      'node_list',
      'node_list:reg_fact',
    ]);
    return $statistics;
  }

  /**
   * {@inheritdoc}
   */
  public function heroStatistics(): array {
    $statistics = [];
    foreach ($this->statistics() as $statistic) {
      $key = (string) ($statistic['key'] ?? '');
      if ($key !== '') {
        $statistics[$key] = $statistic;
      }
    }

    $highlights = [];
    foreach ([
      'homepage-installed-generation-capacity',
      'homepage-national-electricity-access',
      'homepage-transmission-network-length',
      'homepage-clean-cooking-stoves',
    ] as $key) {
      if (isset($statistics[$key])) {
        $highlights[] = $statistics[$key];
      }
    }
    return $highlights;
  }

  /**
   * {@inheritdoc}
   */
  public function energyAtGlance(): array {
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:homepage:energy_at_glance:v1:' . $langcode;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_fact')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_show_homepage', 1)
      ->condition('field_reg_active', 1)
      ->condition('field_reg_fact_category', ['generation', 'transmission', 'access'], 'IN')
      ->sort('changed', 'DESC')
      ->sort('nid', 'DESC')
      ->execute();
    $headlines = [];
    $secondary = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      if ($node->hasTranslation($langcode)) {
        $translation = $node->getTranslation($langcode);
        if ($translation->isPublished() && $translation->access('view')) {
          $node = $translation;
        }
      }
      $category = $this->value($node, 'field_reg_fact_category');
      $fact = $this->normalizeEnergyFact($node);
      if ($this->value($node, 'field_reg_fact_role') === 'secondary') {
        $secondary[$category][] = $fact;
      }
      elseif (!isset($headlines[$category])) {
        // When historical records remain featured, the most recently updated
        // approved record is the explicit current homepage selection.
        $headlines[$category] = $fact;
      }
    }

    $labels = [
      'generation' => 'Generation',
      'transmission' => 'Transmission',
      'access' => 'Electricity Access',
    ];
    $urls = [
      'generation' => Url::fromUri('internal:/what-we-do/generation')->toString(),
      'transmission' => Url::fromUri('internal:/what-we-do/transmission')->toString(),
      'access' => Url::fromUri('internal:/what-we-do/access')->toString(),
    ];
    $cards = [];
    foreach (array_keys($labels) as $category) {
      if (!isset($headlines[$category])) {
        continue;
      }
      $card = $headlines[$category];
      $card['category'] = $category;
      $card['category_label'] = $labels[$category];
      $card['url'] = $urls[$category];
      $card['secondary'] = array_values(array_filter(
        $secondary[$category] ?? [],
        static fn(array $item): bool => $item['period'] === $card['period'],
      ));
      if ($category === 'transmission' && $card['secondary']) {
        $total = array_sum(array_column($card['secondary'], 'numeric_value'));
        foreach ($card['secondary'] as &$item) {
          $item['proportion'] = $total > 0 ? round(($item['numeric_value'] / $total) * 100, 2) : 0;
        }
        unset($item);
      }
      $cards[] = $card;
    }

    $this->cache->set($cid, $cards, $this->time->getRequestTime() + 300, [
      'node_list',
      'node_list:reg_fact',
    ]);
    return $cards;
  }

  /**
   * Normalizes a manually managed energy fact for public presentation.
   */
  private function normalizeEnergyFact(NodeInterface $node): array {
    $raw_value = $this->value($node, 'field_reg_fact_value');
    $configured_decimals = $this->value($node, 'field_reg_decimal_places');
    $decimals = $configured_decimals !== ''
      ? max(0, min(6, (int) $configured_decimals))
      : (str_contains($raw_value, '.') ? strlen(rtrim(substr($raw_value, strpos($raw_value, '.') + 1), '0')) : 0);
    return [
      'id' => (int) $node->id(),
      'title' => $this->value($node, 'field_reg_metric') ?: (string) $node->label(),
      'value' => number_format((float) $raw_value, $decimals, '.', ','),
      'numeric_value' => (float) $raw_value,
      'decimals' => $decimals,
      'unit' => $this->value($node, 'field_reg_fact_unit'),
      'period' => $this->value($node, 'field_reg_reporting_period'),
      'description' => $this->value($node, 'field_reg_supporting_text'),
      'updated' => $node->getChangedTime(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function partners(): array {
    $cid = 'reg_core:homepage:partners';
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_partner')
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_active', 1)
      ->sort('field_reg_order', 'ASC')
      ->sort('title', 'ASC')
      ->execute();
    $partners = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('view')) {
        continue;
      }
      $link = $this->link($node, 'field_reg_partner_url');
      $partners[] = [
        'id' => (int) $node->id(),
        'name' => (string) $node->label(),
        'description' => $this->value($node, 'field_reg_description'),
        'logo' => $this->image($node, 'field_reg_partner_logo', 'reg_partner_logo'),
        'url' => $link['url'],
        'external' => $link['external'],
      ];
    }

    $this->cache->set($cid, $partners, $this->time->getRequestTime() + 300, [
      'node_list',
      'node_list:reg_partner',
      'media_list',
      'file_list',
      'config:image.style.reg_partner_logo',
    ]);
    return $partners;
  }

  /**
   * Normalizes a CTA only when its required label and URL both exist.
   */
  private function cta(NodeInterface $node, string $label_field, string $url_field): array {
    $label = $this->value($node, $label_field);
    $link = $this->link($node, $url_field);
    return $label !== '' && $link['url'] !== '' ? [
      'label' => $label,
      'url' => $link['url'],
      'external' => $link['external'],
    ] : [];
  }

  /**
   * Returns a generated URL and whether Drupal identifies it as external.
   */
  private function link(NodeInterface $node, string $field_name): array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return ['url' => '', 'external' => FALSE];
    }
    try {
      $url = $node->get($field_name)->first()->getUrl();
      return ['url' => $url->toString(), 'external' => $url->isExternal()];
    }
    catch (\Exception) {
      return ['url' => '', 'external' => FALSE];
    }
  }

  /**
   * Returns an image-style derivative and accessible alternative text.
   */
  private function image(NodeInterface $node, string $field_name, string $style_name): array {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return [];
    }
    $media = $node->get($field_name)->entity;
    if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
      return [];
    }
    $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $item = $source !== '' ? $media->get($source)->first() : NULL;
    $file = $item?->entity;
    $style = $this->regEntityTypeManager->getStorage('image_style')->load($style_name);
    if (!$file || !$style) {
      return [];
    }
    $alt = trim((string) ($item->get('alt')->getValue() ?? '')) ?: (string) $media->label();
    return [
      'url' => $style->buildUrl($file->getFileUri()),
      'alt' => $alt,
    ];
  }

  /**
   * Returns a field's plain stored value.
   */
  private function value(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    return trim((string) ($node->get($field_name)->value ?? ''));
  }

  /**
   * Returns a stored Drupal UTC datetime as a timestamp.
   */
  private function timestamp(NodeInterface $node, string $field_name): int {
    $value = $this->value($node, $field_name);
    if ($value === '') {
      return 0;
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === FALSE ? 0 : $timestamp;
  }

}
