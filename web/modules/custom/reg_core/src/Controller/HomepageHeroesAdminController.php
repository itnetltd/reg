<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Editor-friendly overview for scheduled homepage heroes.
 */
final class HomepageHeroesAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Lists all hero slides in their configured order.
   */
  public function overview(): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_homepage_hero')
      ->sort('field_reg_order', 'ASC')
      ->sort('created', 'DESC')
      ->execute();
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $display = $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node;
      $rows[] = [
        Link::fromTextAndUrl($display->label(), $node->toUrl('edit-form'))->toRenderable(),
        $this->thumbnail($node),
        strtoupper($display->language()->getId()),
        $this->date($this->timestamp($node, 'field_reg_hero_start'), $this->t('Immediately')),
        $this->date($this->timestamp($node, 'field_reg_hero_end'), $this->t('No expiry')),
        (int) $node->get('field_reg_order')->value,
        (bool) $node->get('field_reg_active')->value ? $this->t('Active') : $this->t('Inactive'),
        $node->isPublished() ? $this->t('Published') : $this->t('Unpublished'),
        $this->dateFormatter->format((int) $node->getChangedTime(), 'short'),
        Link::fromTextAndUrl($this->t('Edit'), $node->toUrl('edit-form'))->toRenderable(),
      ];
    }

    return [
      'intro' => [
        '#type' => 'container',
        'copy' => ['#markup' => '<p>' . $this->t('Published, active and currently scheduled heroes automatically form the homepage slideshow. Lower priority numbers display first.') . '</p>'],
        'add' => Link::fromTextAndUrl($this->t('Add Hero'), Url::fromRoute('node.add', ['node_type' => 'reg_homepage_hero'], ['attributes' => ['class' => ['button', 'button--primary']]]))->toRenderable(),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Title'), $this->t('Thumbnail'), $this->t('Language'), $this->t('Start'), $this->t('End'), $this->t('Priority'), $this->t('Active'), $this->t('Published'), $this->t('Updated'), $this->t('Edit')],
        '#rows' => $rows,
        '#empty' => $this->t('No homepage heroes have been created yet.'),
      ],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'user.permissions'],
        'tags' => ['node_list:reg_homepage_hero', 'media_list', 'file_list'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Builds a small administrative image preview.
   */
  private function thumbnail(NodeInterface $node): array|string {
    if (!$node->hasField('field_reg_desktop_hero_image') || $node->get('field_reg_desktop_hero_image')->isEmpty()) {
      return (string) $this->t('No image');
    }
    $media = $node->get('field_reg_desktop_hero_image')->entity;
    if (!$media instanceof MediaInterface) {
      return (string) $this->t('No image');
    }
    $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $item = $source !== '' ? $media->get($source)->first() : NULL;
    $file = $item?->entity;
    $alt = trim((string) ($item?->get('alt')->getValue() ?? '')) ?: (string) $media->label();
    return $file ? [
      '#theme' => 'image_style',
      '#style_name' => 'thumbnail',
      '#uri' => $file->getFileUri(),
      '#alt' => $alt,
      '#width' => 100,
    ] : (string) $this->t('No image');
  }

  /**
   * Formats an optional schedule timestamp.
   */
  private function date(int $timestamp, $fallback): string {
    return $timestamp > 0 ? $this->dateFormatter->format($timestamp, 'short') : (string) $fallback;
  }

  /**
   * Converts a Drupal UTC datetime field to a timestamp.
   */
  private function timestamp(NodeInterface $node, string $field_name): int {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return 0;
    }
    $timestamp = strtotime((string) $node->get($field_name)->value . ' UTC');
    return $timestamp === FALSE ? 0 : $timestamp;
  }

}
