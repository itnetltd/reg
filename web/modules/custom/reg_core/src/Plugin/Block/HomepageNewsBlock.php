<?php

namespace Drupal\reg_core\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\reg_core\News\NewsRepositoryInterface;
use Drupal\reg_core\Social\SocialMediaManager;
use Drupal\reg_core\Social\XFeedServiceInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders the CMS-driven homepage News & Insights stories.
 */
#[Block(
  id: 'reg_core_homepage_news',
  admin_label: new TranslatableMarkup('REG homepage News & Insights'),
  category: new TranslatableMarkup('REG'),
)]
final class HomepageNewsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly NewsRepositoryInterface $newsRepository,
    private readonly SocialMediaManager $socialMediaManager,
    private readonly XFeedServiceInterface $xFeed,
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
      $container->get(NewsRepositoryInterface::class),
      $container->get('reg_core.social_media_manager'),
      $container->get(XFeedServiceInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $content = $this->newsRepository->homepage();
    $content['x_feed'] = $this->socialMediaManager->homepageXFeed();
    if ($content['x_feed'] !== []) {
      $x_feed = $this->xFeed->homepageFeed();
      $content['x_feed']['posts'] = $x_feed['items'];
      $content['cache_tags'] = array_merge($content['cache_tags'], $x_feed['cache_tags']);
    }
    return [
      '#theme' => 'reg_news_homepage',
      '#content' => $content,
      '#view_all_url' => Url::fromRoute('reg_core.news')->toString(),
      '#attached' => ['library' => ['reg_core/newsroom']],
      '#cache' => [
        'contexts' => ['languages:language_content', 'languages:language_interface', 'user.node_grants:view'],
        'tags' => array_values(array_unique(array_merge($content['cache_tags'], ['config:reg_core.social_media', 'reg_core:x_feed']))),
        'max-age' => 300,
      ],
    ];
  }

}
