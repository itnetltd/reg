<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Path\PathMatcherInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reg_core\Alert\PublicAlertRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the highest-priority eligible Public Alert.
 */
#[Block(
  id: 'reg_core_public_alert',
  admin_label: new TranslatableMarkup('REG public alert'),
  category: new TranslatableMarkup('REG'),
)]
final class PublicAlertBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly PublicAlertRepositoryInterface $repository,
    private readonly PathMatcherInterface $pathMatcher,
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
      $container->get(PublicAlertRepositoryInterface::class),
      $container->get('path.matcher'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $result = $this->repository->current($this->pathMatcher->isFrontPage());
    $cache = [
      'contexts' => ['languages:language_interface', 'url.path'],
      'tags' => ['node_list:reg_public_alert'],
      'max-age' => (int) ($result['max_age'] ?? 0),
    ];
    if (empty($result['alerts'])) {
      // Preserve the next scheduling boundary even while no strip is visible.
      return ['#cache' => $cache];
    }
    return [
      '#theme' => 'reg_public_alert',
      '#alerts' => $result['alerts'],
      '#attached' => ['library' => ['reg_core/public_alert']],
      '#cache' => $cache,
    ];
  }

}
