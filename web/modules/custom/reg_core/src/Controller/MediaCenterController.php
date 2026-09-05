<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Media\FlickrGallery;
use Drupal\reg_core\News\NewsRepositoryInterface;
use Drupal\reg_core\PublicInformation\PublicInformationRepositoryInterface;
use Drupal\reg_core\Video\VideoRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** Composes the Media Center landing from shared structured content. */
final class MediaCenterController implements ContainerInjectionInterface {

  public function __construct(
    private readonly NewsRepositoryInterface $news,
    private readonly PublicInformationRepositoryInterface $publications,
    private readonly BlockManagerInterface $blockManager,
    private readonly VideoRepositoryInterface $videos,
    private readonly FlickrGallery $flickr,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reg_core.news_repository'),
      $container->get('reg_core.public_information_repository'),
      $container->get('plugin.manager.block'),
      $container->get(VideoRepositoryInterface::class),
      $container->get('reg_core.flickr_gallery'),
    );
  }

  public function landing(): array {
    $corporate = $this->news->archive(['section' => 'corporate'], 0, 4);
    $sports = $this->news->archive(['section' => 'sports'], 0, 3);
    $corporate_items = $corporate['items'];
    $featured = array_shift($corporate_items);
    $documents = [];
    foreach ([
      'press' => 'press_release',
      'publications' => 'publications',
      'newsletters' => 'newsletter',
    ] as $key => $scope) {
      $documents[$key] = array_slice($this->publications->search(['reg_publication'], [
        'sort' => 'newest',
        'publication_scope' => $scope,
      ]), 0, 3);
    }
    $social = $this->blockManager->createInstance('reg_core_social_media_follow', [])->build();
    $social_available = isset($social['#theme']);
    $gallery = $this->flickr->gallery(6);
    $videos = $this->videos->featured(1);
    if ($videos === []) {
      $videos = $this->videos->archive([], 0, 3)['items'];
    }
    return [
      '#theme' => 'reg_media_center',
      '#featured' => $featured ?: [],
      '#corporate' => $corporate_items,
      '#sports' => $sports['items'],
      '#documents' => $documents,
      '#gallery' => $gallery,
      '#videos' => $videos,
      '#social' => $social,
      '#social_available' => $social_available,
      '#attached' => ['library' => ['reg_core/newsroom']],
      '#cache' => [
        'contexts' => ['languages:language_content', 'languages:language_interface', 'user.node_grants:view'],
        'tags' => array_values(array_unique(array_merge($corporate['cache_tags'], $sports['cache_tags'], ['node_list:reg_publication', 'node_list:reg_video', 'config:reg_core.social_media']))),
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Displays the configured official Flickr feed or a clear fallback.
   */
  public function gallery(): array {
    return [
      '#theme' => 'reg_media_gallery',
      '#gallery' => $this->flickr->gallery(16),
      '#attached' => ['library' => ['reg_core/newsroom']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['config:reg_core.social_media'],
        'max-age' => 900,
      ],
    ];
  }

  /**
   * Displays every enabled official REG social channel.
   */
  public function social(): array {
    $social = $this->blockManager->createInstance('reg_core_social_media_follow', [])->build();
    return [
      '#theme' => 'reg_media_social',
      '#social' => $social,
      '#social_available' => isset($social['#theme']),
      '#attached' => ['library' => ['reg_core/newsroom']],
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['config:reg_core.social_media'],
      ],
    ];
  }

  /** Permanently redirects an approved legacy Media Center index. */
  public function legacy(string $destination_route): RedirectResponse {
    return new RedirectResponse(Url::fromRoute($destination_route)->toString(), 301);
  }

}
