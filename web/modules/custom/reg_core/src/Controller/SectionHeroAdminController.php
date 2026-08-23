<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reg_core\Service\SectionHeroResolver;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Editor-friendly Section Hero content overview.
 */
final class SectionHeroAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
    );
  }

  /**
   * Lists Section Heroes and exposes duplicate active defaults clearly.
   */
  public function overview(Request $request): array {
    $options = SectionHeroResolver::sectionOptions();
    $selected = (string) $request->query->get('section', '');
    if (!isset($options[$selected])) {
      $selected = '';
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_section_hero')
      ->sort('field_reg_section_key', 'ASC')
      ->sort('field_reg_order', 'ASC')
      ->sort('nid', 'ASC');
    if ($selected !== '') {
      $query->condition('field_reg_section_key', $selected);
    }
    $nodes = $storage->loadMultiple($query->execute());
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $rows = [];
    $active_defaults = [];
    foreach ($nodes as $node) {
      $display = $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node;
      $key = (string) $node->get('field_reg_section_key')->value;
      $paths = trim((string) $node->get('field_reg_page_paths')->value);
      if ($node->isPublished() && (bool) $node->get('field_reg_active')->value && $paths === '') {
        $active_defaults[$key][] = (int) $node->id();
      }
      $rows[] = [
        Link::fromTextAndUrl($display->label(), $node->toUrl('edit-form'))->toRenderable(),
        $options[$key] ?? $key,
        $paths !== '' ? ['data' => ['#markup' => nl2br($this->t('@paths', ['@paths' => $paths]))]] : $this->t('Section default'),
        (int) $node->get('field_reg_order')->value,
        strtoupper($display->language()->getId()),
        $node->isPublished() ? $this->t('Published') : $this->t('Unpublished'),
        (bool) $node->get('field_reg_active')->value ? $this->t('Active') : $this->t('Inactive'),
      ];
    }

    $duplicates = array_filter($active_defaults, static fn(array $ids): bool => count($ids) > 1);
    $build = [
      'intro' => [
        '#type' => 'container',
        'copy' => ['#markup' => '<p>' . $this->t('Manage reusable section backgrounds, translated text, optional page overrides, calls to action, and publication state.') . '</p>'],
        'add' => Link::fromTextAndUrl($this->t('Add Section Hero'), Url::fromRoute('node.add', ['node_type' => 'reg_section_hero'], ['attributes' => ['class' => ['button', 'button--primary']]]))->toRenderable(),
      ],
      'filter' => [
        '#type' => 'container',
        'links' => [
          '#theme' => 'links',
          '#links' => ['all' => ['title' => $this->t('All sections'), 'url' => Url::fromRoute('reg_core.section_heroes_admin')]] + array_map(
            static fn(string $label, string $key): array => ['title' => $label, 'url' => Url::fromRoute('reg_core.section_heroes_admin', [], ['query' => ['section' => $key]])],
            $options,
            array_keys($options),
          ),
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Title'), $this->t('Section'), $this->t('Scope'), $this->t('Weight'), $this->t('Language'), $this->t('Publication'), $this->t('Display')],
        '#rows' => $rows,
        '#empty' => $this->t('No Section Heroes have been created yet.'),
      ],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'url.query_args:section', 'user.permissions'],
        'tags' => ['node_list:reg_section_hero'],
        'max-age' => 0,
      ],
    ];
    if ($duplicates) {
      $build['warning'] = [
        '#type' => 'status_messages',
        '#message_list' => ['warning' => [$this->t('More than one active section default exists for: @sections. The lowest weight is used.', ['@sections' => implode(', ', array_map(static fn(string $key): string => $options[$key] ?? $key, array_keys($duplicates)))])]],
        '#weight' => -20,
      ];
    }
    return $build;
  }

}
