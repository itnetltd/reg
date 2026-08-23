<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reg_core\Homepage\HomepageRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders active CMS-managed homepage partners.
 */
#[Block(
  id: 'reg_core_homepage_partners',
  admin_label: new TranslatableMarkup('REG homepage partners'),
  category: new TranslatableMarkup('REG'),
)]
final class HomepagePartnersBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
    $partners = $this->homepageRepository->partners();
    if ($partners === []) {
      return [];
    }
    return [
      '#theme' => 'reg_homepage_partners',
      '#partners' => $partners,
      '#cache' => [
        'tags' => ['node_list', 'node_list:reg_partner', 'media_list', 'file_list'],
        'max-age' => 300,
      ],
    ];
  }

}
