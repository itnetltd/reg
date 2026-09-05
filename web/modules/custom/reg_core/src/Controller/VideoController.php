<?php

namespace Drupal\reg_core\Controller;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\reg_core\Video\VideoRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the public, published REG video archive.
 */
final class VideoController implements ContainerInjectionInterface {

  private const PAGE_SIZE = 9;

  public function __construct(
    private readonly VideoRepositoryInterface $videoRepository,
    private readonly PagerManagerInterface $pagerManager,
    private readonly PagerParametersInterface $pagerParameters,
    private readonly BlockManagerInterface $blockManager,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(VideoRepositoryInterface::class),
      $container->get('pager.manager'),
      $container->get('pager.parameters'),
      $container->get('plugin.manager.block'),
      $container->get('config.factory'),
    );
  }

  /**
   * Lists published videos with search, category, year, and pagination.
   */
  public function archive(Request $request): array {
    return $this->buildArchive($request, FALSE);
  }

  /**
   * Builds the Media Center landing with the published video collection.
   */
  public function mediaCenter(Request $request): array {
    return $this->buildArchive($request, TRUE);
  }

  /**
   * Builds the shared published video collection.
   */
  private function buildArchive(Request $request, bool $media_center): array {
    $filters = [
      'search' => trim((string) $request->query->get('search', '')),
      'category' => max(0, (int) $request->query->get('category', 0)),
      'year' => preg_match('/^\d{4}$/', (string) $request->query->get('year', ''))
        ? (string) $request->query->get('year')
        : '',
    ];
    $page = max(0, $this->pagerParameters->findPage(0));
    $result = $this->videoRepository->archive($filters, $page, self::PAGE_SIZE);
    $this->pagerManager->createPager($result['total'], self::PAGE_SIZE, 0);
    $options = $this->videoRepository->filterOptions();
    $social_follow = [];
    if ($media_center) {
      $social_follow = $this->blockManager
        ->createInstance('reg_core_social_media_follow')
        ->build();
    }
    $youtube = $this->configFactory->get('reg_core.social_media')->get('platforms.youtube');
    $youtube = is_array($youtube) ? $youtube : [];
    $channel_url = trim((string) ($youtube['url'] ?? ''));
    $parts = parse_url($channel_url);
    if (
      empty($youtube['enabled'])
      || empty($youtube['videos_enabled'])
      || !UrlHelper::isValid($channel_url, TRUE)
      || !is_array($parts)
      || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
      || !in_array(strtolower((string) ($parts['host'] ?? '')), ['youtube.com', 'www.youtube.com'], TRUE)
      || isset($parts['user'])
      || isset($parts['pass'])
    ) {
      $channel_url = '';
    }

    return [
      '#theme' => 'reg_video_archive',
      '#archive_heading' => $media_center ? 'Media Center' : 'Videos',
      '#archive_description' => $media_center
        ? 'Explore published Rwanda Energy Group media and videos from approved sources.'
        : 'Watch published Rwanda Energy Group videos from approved providers.',
      '#items' => $result['items'],
      '#results_count' => $result['total'],
      '#filters' => $filters,
      '#categories' => $options['categories'],
      '#years' => $options['years'],
      '#pager' => ['#type' => 'pager'],
      '#social_follow' => $social_follow,
      '#youtube_channel_url' => $channel_url,
      '#attached' => ['library' => ['reg_core/featured_videos']],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'url.query_args:search', 'url.query_args:category', 'url.query_args:year', 'url.query_args:page'],
        'tags' => ['node_list', 'node_list:reg_video', 'media_list', 'file_list', 'taxonomy_term_list:reg_video_category', 'config:reg_core.social_media'],
        'max-age' => 300,
      ],
    ];
  }

}
