<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Branch\BranchMapNormalizer;
use Drupal\reg_core\Branch\BranchRepositoryInterface;
use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public Branch Locator routes.
 */
final class BranchController extends ControllerBase {

  public function __construct(
    private readonly BranchRepositoryInterface $repository,
    private readonly BranchMapNormalizer $mapNormalizer,
    private readonly PagerManagerInterface $regPagerManager,
    private readonly ConfigFactoryInterface $regConfigFactory,
    private readonly AnalyticsEventTrackerInterface $analytics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(BranchRepositoryInterface::class),
      $container->get('reg_core.branch_map_normalizer'),
      $container->get('pager.manager'),
      $container->get('config.factory'),
      $container->get(AnalyticsEventTrackerInterface::class),
    );
  }

  /**
   * Builds the filtered map-and-list Branch Locator.
   */
  public function listing(Request $request): array {
    $filters = $this->filters($request);
    if ($filters['query'] !== '') {
      $this->analytics->record('branch_search', ['dimension_type' => 'section', 'dimension_value' => 'branches']);
    }
    if (array_filter(array_diff_key($filters, ['query' => TRUE]))) {
      $this->analytics->record('branch_filter', ['dimension_type' => 'section', 'dimension_value' => 'branches']);
    }
    $all = $this->repository->search($filters);
    $limit = min(48, max(6, (int) ($this->regConfigFactory->get('reg_core.settings')->get('branches.results_per_page') ?: 12)));
    $pager = $this->regPagerManager->createPager(count($all), $limit);
    $items = array_slice($all, $pager->getCurrentPage() * $limit, $limit);
    $markers = $this->mapNormalizer->normalize($all);
    return [
      '#theme' => 'reg_branch_locator',
      '#items' => $items,
      '#markers' => $markers,
      '#filters' => $filters,
      '#provinces' => $this->repository->taxonomyOptions('reg_province'),
      '#districts' => $this->repository->taxonomyOptions('reg_district'),
      '#services' => $this->repository->taxonomyOptions('reg_branch_service'),
      '#entities' => ['reg' => 'REG', 'eucl' => 'EUCL', 'edcl' => 'EDCL'],
      '#count' => count($all),
      '#mapped_count' => count($markers),
      '#pending_count' => count($all) - count($markers),
      '#pager' => ['#type' => 'pager'],
      '#map_data_url' => Url::fromRoute('reg_core.branches_map_data', [], ['query' => array_filter($filters)])->toString(),
      '#call_center' => (string) ($this->regConfigFactory->get('reg_core.settings')->get('support.call_center') ?: '2727'),
      '#attached' => [
        'library' => ['reg_core/customer_support'],
        'drupalSettings' => [
          'regBranches' => [
            'markers' => $markers,
            'provider' => (string) ($this->regConfigFactory->get('reg_core.settings')->get('branches.map_provider') ?: 'none'),
          ],
        ],
      ],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'url.query_args'],
        'tags' => ['node_list:reg_branch', 'config:reg_core.settings'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Returns provider-neutral public marker data.
   */
  public function mapData(Request $request): JsonResponse {
    $markers = $this->mapNormalizer->normalize($this->repository->search($this->filters($request)));
    $response = new JsonResponse(['markers' => $markers]);
    $response->setPublic()->setMaxAge(300);
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  /**
   * Builds a public branch detail page.
   */
  public function detail(int $branch): array {
    $item = $this->repository->find($branch);
    if ($item === NULL) {
      throw new NotFoundHttpException();
    }
    $this->analytics->record('branch_view', ['entity_id' => $branch]);
    return [
      '#theme' => 'reg_branch_detail',
      '#branch' => $item,
      '#marker' => $this->mapNormalizer->normalize([$item])[0] ?? [],
      '#back_url' => Url::fromRoute('reg_core.branches')->toString(),
      '#attached' => [
        'library' => ['reg_core/customer_support'],
        'drupalSettings' => ['regBranches' => ['markers' => $this->mapNormalizer->normalize([$item])]],
      ],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'user.permissions'],
        'tags' => ['node:' . $branch, 'node_list:reg_branch'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Returns a safe branch title for the route.
   */
  public function detailTitle(int $branch): string {
    return (string) ($this->repository->find($branch)['title'] ?? $this->t('REG Branch'));
  }

  /**
   * Whitelists Branch Locator query arguments.
   */
  private function filters(Request $request): array {
    return [
      'query' => mb_substr(trim(strip_tags((string) $request->query->get('q', ''))), 0, 120),
      'province' => max(0, (int) $request->query->get('province', 0)),
      'district' => max(0, (int) $request->query->get('district', 0)),
      'entity' => in_array($request->query->get('entity', ''), ['reg', 'eucl', 'edcl'], TRUE) ? (string) $request->query->get('entity') : '',
      'service' => max(0, (int) $request->query->get('service', 0)),
    ];
  }

}
