<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\reg_core\News\NewsRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the published, filterable REG News & Insights archive.
 */
final class NewsController implements ContainerInjectionInterface {

  private const PAGE_SIZE = 12;

  public function __construct(
    private readonly NewsRepositoryInterface $newsRepository,
    private readonly PagerManagerInterface $pagerManager,
    private readonly PagerParametersInterface $pagerParameters,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(NewsRepositoryInterface::class),
      $container->get('pager.manager'),
      $container->get('pager.parameters'),
    );
  }

  /**
   * Displays the News archive with public filters and pagination.
   */
  public function archive(Request $request): array {
    $filters = [
      'search' => mb_substr(trim(strip_tags((string) $request->query->get('search', ''))), 0, 120),
      'category' => max(0, (int) $request->query->get('category', 0)),
      'year' => preg_match('/^\d{4}$/', (string) $request->query->get('year', ''))
        ? (string) $request->query->get('year')
        : '',
      'department' => mb_substr(trim(strip_tags((string) $request->query->get('department', ''))), 0, 120),
    ];
    $page = max(0, $this->pagerParameters->findPage(0));
    $result = $this->newsRepository->archive($filters, $page, self::PAGE_SIZE);
    $this->pagerManager->createPager($result['total'], self::PAGE_SIZE, 0);
    $options = $this->newsRepository->filterOptions();
    $items = $result['items'];
    $lead = $page === 0 && $items ? array_shift($items) : [];

    return [
      '#theme' => 'reg_news_archive',
      '#archive_heading' => 'News & Insights',
      '#archive_description' => 'Official Rwanda Energy Group news, project updates, announcements and public information.',
      '#lead' => $lead,
      '#items' => $items,
      '#results_count' => $result['total'],
      '#filters' => $filters,
      '#categories' => $options['categories'],
      '#years' => $options['years'],
      '#departments' => $options['departments'],
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['reg_core/newsroom']],
      '#cache' => [
        'contexts' => [
          'languages:language_content',
          'languages:language_interface',
          'url.query_args:search',
          'url.query_args:category',
          'url.query_args:year',
          'url.query_args:department',
          'url.query_args:page',
          'user.node_grants:view',
        ],
        'tags' => $result['cache_tags'],
        'max-age' => 300,
      ],
    ];
  }

}
