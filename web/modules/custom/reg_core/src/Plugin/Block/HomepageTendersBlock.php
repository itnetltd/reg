<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reg_core\PublicInformation\PublicInformationRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders editorially featured current tenders on the homepage.
 */
#[Block(
  id: 'reg_core_homepage_tenders',
  admin_label: new TranslatableMarkup('REG homepage Tenders'),
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
    return [
      '#theme' => 'reg_tender_homepage',
      '#items' => $this->repository->homepageTenders(3),
      '#view_all_url' => Url::fromRoute('reg_core.tenders')->toString(),
      '#attached' => ['library' => ['reg_core/tenders']],
      '#cache' => [
        'contexts' => ['languages:language_content', 'languages:language_interface', 'user.node_grants:view'],
        'tags' => ['node_list:reg_tender', 'taxonomy_term_list:reg_procurement_category'],
        'max-age' => 300,
      ],
    ];
  }

}
