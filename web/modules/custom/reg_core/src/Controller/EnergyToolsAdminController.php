<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reg_core\Energy\EnergyToolStatusResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Editor-friendly Energy Tools content overview.
 */
final class EnergyToolsAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly EnergyToolStatusResolverInterface $statusResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get(EnergyToolStatusResolverInterface::class),
    );
  }

  /**
   * Lists tools in their homepage order with configured and effective states.
   */
  public function overview(): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_energy_tool')
      ->sort('field_reg_order', 'ASC')
      ->sort('nid', 'ASC')
      ->execute();
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $display = $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node;
      $configured = (string) $node->get('field_reg_tool_status')->value;
      $effective = $this->statusResolver->resolve((string) $node->get('field_reg_energy_tool_key')->value, $configured);
      $rows[] = [
        Link::fromTextAndUrl($display->label(), $node->toUrl('edit-form'))->toRenderable(),
        (int) $node->get('field_reg_order')->value,
        ucfirst(str_replace('_', ' ', $configured)),
        ucfirst(str_replace('_', ' ', $effective)),
        (bool) $node->get('field_reg_featured')->value ? $this->t('Yes') : $this->t('No'),
        (bool) $node->get('field_reg_active')->value ? $this->t('Active') : $this->t('Inactive'),
        $node->isPublished() ? $this->t('Published') : $this->t('Unpublished'),
        strtoupper($display->language()->getId()),
      ];
    }

    return [
      'intro' => [
        '#type' => 'container',
        'copy' => ['#markup' => '<p>' . $this->t('Manage homepage tool copy, Media icons, calls to action, display order, and publication controls. Calculator availability is derived from approved site configuration.') . '</p>'],
        'add' => Link::fromTextAndUrl($this->t('Add Energy Tool'), Url::fromRoute('node.add', ['node_type' => 'reg_energy_tool'], ['attributes' => ['class' => ['button', 'button--primary']]]))->toRenderable(),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Title'), $this->t('Order'), $this->t('Editor status'), $this->t('Effective status'), $this->t('Homepage'), $this->t('Display'), $this->t('Publication'), $this->t('Language')],
        '#rows' => $rows,
        '#empty' => $this->t('No Energy Tools have been created yet.'),
      ],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'user.permissions'],
        'tags' => ['node_list:reg_energy_tool', 'config:reg_core.settings'],
        'max-age' => 0,
      ],
    ];
  }

}
