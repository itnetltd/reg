<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reg_core\Energy\EnergyToolRepositoryInterface;
use Drupal\reg_core\Video\VideoRepositoryInterface;
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
    private readonly VideoRepositoryInterface $videos,
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
      $container->get(VideoRepositoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $tools = $this->repository->homepageTools(3);
    $video = $this->videos->educational();
    return [
      '#theme' => 'reg_homepage_energy_tools',
      '#tools' => $tools,
      '#video' => $video,
      '#attached' => ['library' => $video ? ['reg_core/featured_videos'] : []],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'languages:language_content', 'user.permissions', 'user.node_grants:view'],
        'tags' => ['node_list', 'node_list:reg_energy_tool', 'node_list:reg_video', 'taxonomy_term_list:reg_video_category', 'taxonomy_term_list', 'media_list', 'file_list', 'config:reg_core.settings', 'config:image.style.reg_homepage_video_small', 'config:image.style.reg_homepage_video', 'config:image.style.reg_homepage_video_large'],
        'max-age' => 300,
      ],
    ];
  }

}
