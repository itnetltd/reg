<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

use Drupal\reg_core\PublicInformation\PublicInformationRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders current tenders and a vacancy in the existing homepage placement.
 */
#[Block(
  id: 'reg_core_homepage_tenders',
  admin_label: new TranslatableMarkup('REG homepage Tenders & Jobs'),
  category: new TranslatableMarkup('REG'),
)]
final class HomepageTendersBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly PublicInformationRepositoryInterface $repository,
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
      $container->get(PublicInformationRepositoryInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    return $this->repository->homepageOpportunities();
  }

}
