<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reg_core\Homepage\HomepageRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the manually managed homepage sector indicators.
 */
#[Block(
  id: 'reg_core_energy_at_glance',
  admin_label: new TranslatableMarkup('Energy at a Glance'),
  category: new TranslatableMarkup('REG'),
)]
final class EnergyAtGlanceBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly HomepageRepositoryInterface $homepageRepository,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('reg_core.homepage_repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $cards = $this->homepageRepository->energyAtGlance();
    if ($cards === []) {
      return [];
    }
    return [
      '#theme' => 'reg_energy_at_glance',
      '#cards' => $cards,
      '#attached' => ['library' => ['reg_core/energy_at_glance']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['node_list', 'node_list:reg_fact'],
        'max-age' => 300,
      ],
    ];
  }

}
