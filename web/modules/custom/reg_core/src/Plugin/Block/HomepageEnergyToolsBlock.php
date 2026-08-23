<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reg_core\Energy\EnergyToolRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the CMS-managed Homepage Energy Awareness section.
 */
#[Block(
  id: 'reg_core_homepage_energy_tools',
  admin_label: new TranslatableMarkup('REG homepage Energy Awareness'),
  category: new TranslatableMarkup('REG'),
)]
final class HomepageEnergyToolsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly EnergyToolRepositoryInterface $repository,
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
      $container->get(EnergyToolRepositoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $tools = $this->repository->homepageTools(3);
    if ($tools === []) {
      return [];
    }
    return [
      '#theme' => 'reg_homepage_energy_tools',
      '#tools' => $tools,
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['node_list:reg_energy_tool', 'media_list', 'file_list', 'config:reg_core.settings'],
        'max-age' => 300,
      ],
    ];
  }

}
