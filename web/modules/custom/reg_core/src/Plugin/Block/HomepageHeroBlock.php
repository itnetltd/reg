<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reg_core\Homepage\HomepageRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the active CMS-managed homepage hero.
 */
#[Block(
  id: 'reg_core_homepage_hero',
  admin_label: new TranslatableMarkup('REG homepage hero'),
  category: new TranslatableMarkup('REG'),
)]
final class HomepageHeroBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly HomepageRepositoryInterface $homepageRepository,
    private readonly ConfigFactoryInterface $configFactory,
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
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $result = $this->homepageRepository->heroes();
    $heroes = $result['heroes'] ?? [];
    $statistics = $this->homepageRepository->heroStatistics();
    if ($heroes === []) {
      $site = $this->configFactory->get('system.site');
      $heroes[] = [
        'id' => 'fallback',
        'title' => (string) ($site->get('name') ?: $this->t('Rwanda Energy Group')),
        'eyebrow' => '',
        'subtitle' => (string) $site->get('slogan'),
        'desktop_image' => [],
        'mobile_image' => [],
        'primary_cta' => [],
        'secondary_cta' => [],
        'alignment' => 'left',
        'overlay' => 'medium',
        'text_theme' => 'dark_background',
      ];
    }
    return [
      '#theme' => 'reg_homepage_hero',
      '#heroes' => $heroes,
      '#statistics' => $statistics,
      '#attached' => ['library' => ['reg_core/homepage_hero']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['node_list', 'node_list:reg_homepage_hero', 'node_list:reg_fact', 'media_list', 'file_list', 'config:system.site'],
        'max-age' => (int) ($result['max_age'] ?? 300),
      ],
    ];
  }

}
