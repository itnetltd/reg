<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reg_core\Video\VideoRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders published, featured videos on the homepage.
 */
#[Block(
  id: 'reg_core_homepage_videos',
  admin_label: new TranslatableMarkup('REG homepage featured videos'),
  category: new TranslatableMarkup('REG'),
)]
final class HomepageVideosBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly VideoRepositoryInterface $videoRepository,
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
      $container->get(VideoRepositoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $videos = $this->videoRepository->featured(5);
    if ($videos === []) {
      return [];
    }
    return [
      '#theme' => 'reg_homepage_videos',
      '#videos' => $videos,
      '#view_all_url' => Url::fromRoute('reg_core.videos')->toString(),
      '#attached' => ['library' => ['reg_core/featured_videos']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['node_list', 'node_list:reg_video', 'media_list', 'file_list', 'taxonomy_term_list:reg_video_category'],
        'max-age' => 300,
      ],
    ];
  }

}
