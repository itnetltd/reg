<?php

namespace Drupal\reg_core\Energy;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;

/**
 * Cached, language-aware repository for homepage energy tools.
 */
final class EnergyToolRepository implements EnergyToolRepositoryInterface {

  private const PRESENTATION = [
    'bill_estimator' => ['bill', 'calculator', 'bill_estimator_click'],
    'carbon_footprint' => ['carbon', 'leaf', 'carbon_calculator_click'],
    'safety_efficiency' => ['safety', 'shield', 'safety_guidance_click'],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly EnergyToolStatusResolverInterface $statusResolver,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function homepageTools(int $limit = 3): array {
    $limit = min(3, max(1, $limit));
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $cid = 'reg_core:homepage:energy_tools:' . $langcode . ':' . $limit;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }

    $tools = [];
    try {
      $storage = $this->regEntityTypeManager->getStorage('node');
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'reg_energy_tool')
        ->condition('status', NodeInterface::PUBLISHED)
        ->condition('field_reg_featured', 1)
        ->condition('field_reg_active', 1)
        ->sort('field_reg_order', 'ASC')
        ->sort('nid', 'ASC')
        ->range(0, $limit)
        ->execute();

      foreach ($storage->loadMultiple($ids) as $node) {
        if (!$node instanceof NodeInterface || !$node->access('view')) {
          continue;
        }
        if ($node->hasTranslation($langcode)) {
          $translated = $node->getTranslation($langcode);
          if ($translated->isPublished() && $translated->access('view')) {
            $node = $translated;
          }
        }
        if (!$node->isPublished()) {
          continue;
        }

        $key = $this->value($node, 'field_reg_energy_tool_key');
        $status = $this->statusResolver->resolve($key, $this->value($node, 'field_reg_tool_status'));
        [$accent, $iconName, $analyticsEvent] = self::PRESENTATION[$key] ?? ['neutral', 'bolt', 'service_click'];
        $tools[] = [
          'id' => (int) $node->id(),
          'key' => $key,
          'title' => (string) $node->label(),
          'description' => $this->value($node, 'field_reg_description'),
          'icon' => $this->icon($node),
          'icon_name' => $iconName,
          'cta_label' => $this->value($node, 'field_reg_cta_label'),
          'cta_url' => $this->link($node, 'field_reg_cta_url'),
          'status' => $status,
          'status_label' => $this->statusLabel($key, $status),
          'accent' => $accent,
          'analytics_event' => $analyticsEvent,
        ];
      }
    }
    catch (\Throwable) {
      // Keep the homepage renderable while an update is being applied.
      $tools = [];
    }

    $this->cache->set($cid, $tools, $this->time->getRequestTime() + 300, [
      'node_list',
      'node_list:reg_energy_tool',
      'media_list',
      'file_list',
      'config:reg_core.settings',
      'config:image.style.reg_energy_tool_icon',
    ]);
    return $tools;
  }

  /**
   * Returns a safe generated URL from a Link field.
   */
  private function link(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    try {
      return $node->get($fieldName)->first()->getUrl()->toString();
    }
    catch (\Exception) {
      return '';
    }
  }

  /**
   * Builds the bounded Media icon derivative when an editor supplied one.
   */
  private function icon(NodeInterface $node): array {
    if (!$node->hasField('field_reg_energy_tool_icon') || $node->get('field_reg_energy_tool_icon')->isEmpty()) {
      return [];
    }
    $media = $node->get('field_reg_energy_tool_icon')->entity;
    if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
      return [];
    }
    $sourceField = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $item = $sourceField !== '' ? $media->get($sourceField)->first() : NULL;
    $file = $item?->entity;
    $style = $this->regEntityTypeManager->getStorage('image_style')->load('reg_energy_tool_icon');
    if (!$file || !$style) {
      return [];
    }
    return ['url' => $style->buildUrl($file->getFileUri())];
  }

  /**
   * Provides accessible, translated public status text.
   */
  private function statusLabel(string $key, string $status): string {
    if ($key === 'carbon_footprint' && $status === 'awaiting_validation') {
      return (string) $this->translation->translate('Awaiting approved factor');
    }
    return (string) match ($status) {
      'available' => $this->translation->translate('Available'),
      'coming_soon' => $this->translation->translate('Coming soon'),
      'awaiting_validation' => $this->translation->translate('Awaiting validation'),
      'guidance' => $this->translation->translate('Guidance'),
      default => $this->translation->translate('Unavailable'),
    };
  }

  /**
   * Returns a field's plain stored value.
   */
  private function value(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) ($node->get($fieldName)->value ?? '')));
  }

}
