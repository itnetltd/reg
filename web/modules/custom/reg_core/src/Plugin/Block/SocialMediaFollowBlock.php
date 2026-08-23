<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reg_core\Social\SocialMediaManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the reusable Follow REG social-links block.
 */
#[Block(
  id: 'reg_core_social_media_follow',
  admin_label: new TranslatableMarkup('Follow REG'),
  category: new TranslatableMarkup('REG'),
)]
final class SocialMediaFollowBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly SocialMediaManager $socialMediaManager,
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
      $container->get('reg_core.social_media_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $items = $this->socialMediaManager->linksForPlacement('media_center');
    if ($items === []) {
      return [
        '#cache' => [
          'tags' => ['config:reg_core.social_media'],
        ],
      ];
    }

    return [
      '#theme' => 'reg_social_links',
      '#items' => $items,
      '#variant' => 'media-center',
      '#heading' => $this->t('Follow REG'),
      '#description' => $this->t('Connect with Rwanda Energy Group through our official social media channels.'),
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['config:reg_core.social_media'],
      ],
    ];
  }

}
