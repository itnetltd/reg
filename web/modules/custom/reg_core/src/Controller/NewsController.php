<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\reg_core\News\NewsRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Drupal\node\NodeInterface;

/**
 * Builds the published, filterable REG News & Insights archive.
 */
final class NewsController implements ContainerInjectionInterface {

  private const PAGE_SIZE = 12;

  public function __construct(
    private readonly NewsRepositoryInterface $newsRepository,
    private readonly PagerManagerInterface $pagerManager,
    private readonly PagerParametersInterface $pagerParameters,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(NewsRepositoryInterface::class),
      $container->get('pager.manager'),
      $container->get('pager.parameters'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Displays the News archive with public filters and pagination.
   */
  public function archive(Request $request): array {
    $section = (string) $request->attributes->get('_news_section', 'corporate');
    $route_language = (string) $request->attributes->get('_news_language', '');
    $filters = [
      'search' => mb_substr(trim(strip_tags((string) $request->query->get('search', ''))), 0, 120),
      'category' => max(0, (int) $request->query->get('category', 0)),
      'year' => preg_match('/^\d{4}$/', (string) $request->query->get('year', ''))
        ? (string) $request->query->get('year')
        : '',
      'department' => mb_substr(trim(strip_tags((string) $request->query->get('department', ''))), 0, 120),
      'section' => $section === 'sports' ? 'sports' : 'corporate',
      'language' => in_array($route_language, ['en', 'rw'], TRUE) ? $route_language : (in_array($request->query->get('language'), ['en', 'rw'], TRUE) ? (string) $request->query->get('language') : ''),
      'sport' => max(0, (int) $request->query->get('sport', 0)),
    ];
    $page = max(0, $this->pagerParameters->findPage(0));
    $result = $this->newsRepository->archive($filters, $page, self::PAGE_SIZE);
    $this->pagerManager->createPager($result['total'], self::PAGE_SIZE, 0);
    $options = $this->newsRepository->filterOptions();
    return [
      '#theme' => 'reg_news_archive',
      '#archive_heading' => $section === 'sports' ? 'Sports News' : 'Corporate News',
      '#archive_description' => $section === 'sports'
        ? 'Latest news, results, achievements and updates from REG sports teams.'
        : 'Approved updates, developments and corporate announcements from Rwanda Energy Group.',
      '#lead' => [],
      '#items' => $result['items'],
      '#results_count' => $result['total'],
      '#filters' => $filters,
      '#categories' => $options['categories'],
      '#years' => $options['years'],
      '#departments' => $options['departments'],
      '#sports' => $options['sports'],
      '#section' => $section,
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
          'url.query_args:language',
          'url.query_args:sport',
          'url.query_args:page',
          'user.node_grants:view',
        ],
        'tags' => $result['cache_tags'],
        'max-age' => 300,
      ],
    ];
  }

  /** Renders a section-validated reg_news article. */
  public function detail(int $node, Request $request): array {
    $entity = $this->entityTypeManager->getStorage('node')->load($node);
    $expected = (string) $request->attributes->get('_news_section', 'corporate');
    $actual = $entity instanceof NodeInterface && $entity->hasField('field_reg_news_section')
      ? (string) $entity->get('field_reg_news_section')->value : 'corporate';
    if (!$entity instanceof NodeInterface || $entity->bundle() !== 'reg_news' || !$entity->isPublished() || !$entity->access('view') || $actual !== $expected) {
      throw new NotFoundHttpException();
    }
    return $this->entityTypeManager->getViewBuilder('node')->view($entity, 'full');
  }

  public function detailTitle(int $node): string {
    $entity = $this->entityTypeManager->getStorage('node')->load($node);
    return $entity instanceof NodeInterface && $entity->bundle() === 'reg_news' ? $entity->label() : 'News';
  }

  /** Redirects an imported legacy TYPO3 news slug to its managed route. */
  public function legacy(string $slug): RedirectResponse {
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE)
      ->condition('type', 'reg_news')->condition('field_reg_source_id', $slug)
      ->condition('status', NodeInterface::PUBLISHED)->range(0, 1)->execute();
    if (!$ids) throw new NotFoundHttpException();
    $entity = $this->entityTypeManager->getStorage('node')->load(reset($ids));
    $section = $entity->hasField('field_reg_news_section') ? (string) $entity->get('field_reg_news_section')->value : 'corporate';
    $url = Url::fromRoute($section === 'sports' ? 'reg_core.sports_news_detail' : 'reg_core.news_detail', ['node' => $entity->id()])->toString();
    return new RedirectResponse($url, 301);
  }

}
