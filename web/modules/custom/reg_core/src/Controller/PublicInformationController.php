<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\reg_core\PublicInformation\PublicInformationRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public Information Hub, listings, search, and detail pages.
 */
final class PublicInformationController extends ControllerBase {

  public function __construct(
    private readonly PublicInformationRepositoryInterface $repository,
    private readonly PagerManagerInterface $regPagerManager,
    private readonly ConfigFactoryInterface $regConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reg_core.public_information_repository'),
      $container->get('pager.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Builds the Public Information Hub landing page.
   */
  public function hub(): array {
    $latest = [
      'tenders' => array_slice($this->repository->search(['reg_tender'], ['mode' => 'current', 'sort' => 'deadline']), 0, 3),
      'jobs' => array_slice($this->repository->search(['reg_job'], ['mode' => 'current', 'sort' => 'deadline']), 0, 3),
      'publications' => array_slice($this->repository->search(['reg_publication'], ['sort' => 'newest']), 0, 3),
    ];

    return [
      '#theme' => 'reg_public_information_hub',
      '#cards' => [
        [
          'eyebrow' => $this->t('Procurement'),
          'title' => $this->t('Tenders'),
          'description' => $this->t('Find current opportunities, addenda, clarifications, awards, and archived notices.'),
          'url' => Url::fromRoute('reg_core.tenders')->toString(),
        ],
        [
          'eyebrow' => $this->t('Careers'),
          'title' => $this->t('Jobs'),
          'description' => $this->t('Browse vacancies, application guidance, recruitment notices, and published results.'),
          'url' => Url::fromRoute('reg_core.jobs')->toString(),
        ],
        [
          'eyebrow' => $this->t('Corporate library'),
          'title' => $this->t('Publications'),
          'description' => $this->t('Download approved reports, policies, plans, safeguards, forms, and guidelines.'),
          'url' => Url::fromRoute('reg_core.publications')->toString(),
        ],
      ],
      '#latest' => $latest,
      '#search_url' => Url::fromRoute('reg_core.public_information_search')->toString(),
      '#attached' => $this->metadata(
        Url::fromRoute('reg_core.public_information', [], ['absolute' => TRUE])->toString(),
        (string) $this->t('Official REG tenders, careers, recruitment notices, reports, policies, and publications.'),
      ),
      '#cache' => [
        'contexts' => ['languages:language_interface'],
        'tags' => ['node_list:reg_tender', 'node_list:reg_job', 'node_list:reg_publication'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Shared search across all published hub content.
   */
  public function search(Request $request): array {
    $type = (string) $request->query->get('type', '');
    $map = [
      'tender' => ['reg_tender'],
      'job' => ['reg_job'],
      'publication' => ['reg_publication'],
    ];
    $bundles = $map[$type] ?? ['reg_tender', 'reg_job', 'reg_publication'];
    $filters = $this->filters($request);
    $items = $this->paginate($this->repository->search($bundles, $filters));
    $category_vocabulary = $type === 'tender' ? 'reg_procurement_category' : ($type === 'publication' ? 'reg_publication_type' : '');

    return [
      '#theme' => 'reg_public_information_search',
      '#items' => $items,
      '#filters' => $filters + ['type' => $type],
      '#category_options' => $category_vocabulary ? $this->repository->taxonomyOptions($category_vocabulary) : [],
      '#type_options' => [
        'tender' => $this->t('Tenders'),
        'job' => $this->t('Jobs'),
        'publication' => $this->t('Publications'),
      ],
      '#entity_options' => $this->entityOptions(),
      '#language_options' => $this->languageOptions(),
      '#pager' => ['#type' => 'pager'],
      '#attached' => $this->metadata(
        Url::fromRoute('reg_core.public_information_search', [], ['absolute' => TRUE])->toString(),
        (string) $this->t('Search published REG tenders, jobs, and official publications.'),
      ),
      '#cache' => $this->listingCache(['reg_tender', 'reg_job', 'reg_publication']),
    ];
  }

  /**
   * Builds tender routes.
   */
  public function tenders(Request $request, string $mode = 'all'): array {
    return $this->listing('reg_tender', $request, $mode);
  }

  /**
   * Builds job routes.
   */
  public function jobs(Request $request, string $mode = 'all'): array {
    return $this->listing('reg_job', $request, $mode);
  }

  /**
   * Builds the publication library.
   */
  public function publications(Request $request): array {
    return $this->listing('reg_publication', $request, 'all');
  }

  /** Builds Media Center document archives on the shared publication model. */
  public function mediaPublications(Request $request): array {
    $scope = (string) $request->attributes->get('_publication_scope', '');
    $build = $this->listing('reg_publication', $request, 'all', [
      'publication_scope' => $scope,
    ]);
    $labels = [
      'publications' => ['Publications', 'Reports, policies, plans, safeguards, forms and approved REG publications.'],
      'press_release' => ['Press Releases', 'Official REG media statements in English and Kinyarwanda.'],
      'archived_announcement' => ['Announcements', 'Archived public announcements. Historical outage notices are not live outage data.'],
      'newsletter' => ['Newsletters', 'REG newsletter issues and downloadable editions.'],
      'corporate_legal' => ['Corporate / Legal Documents', 'Company laws and historical corporate legal documents.'],
    ];
    if (isset($labels[$scope])) {
      $build['#heading'] = $this->t($labels[$scope][0]);
      $build['#intro'] = $this->t($labels[$scope][1]);
    }
    return $build;
  }

  /** Redirects a published imported legacy document detail slug. */
  public function legacyPublication(string $slug): RedirectResponse {
    $ids = $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'reg_publication')
      ->condition('field_reg_source_id', $slug)
      ->condition('status', 1)
      ->range(0, 1)
      ->execute();
    if (!$ids) {
      throw new NotFoundHttpException();
    }
    return new RedirectResponse(Url::fromRoute('reg_core.publication_detail', [
      'publication' => (int) reset($ids),
    ])->toString(), 301);
  }

  /**
   * Builds one bundle listing with shared filters and presentation.
   */
  private function listing(string $bundle, Request $request, string $mode, array $additional_filters = []): array {
    $filters = $this->filters($request) + ['mode' => $mode] + $additional_filters;
    $all_items = $this->repository->search([$bundle], $filters);
    $items = $this->paginate($all_items);
    $settings = $this->regConfigFactory->get('reg_core.settings');

    $definition = match ($bundle) {
      'reg_tender' => [
        'title' => $this->t('Tenders'),
        'intro' => $this->t('Official procurement opportunities, addenda, clarifications, awards, and archived notices from REG, EUCL, and EDCL.'),
        'empty' => $this->t('No published tender notices matched your filters.'),
        'vocabulary' => 'reg_procurement_category',
        'tabs' => [
          ['label' => $this->t('All tenders'), 'route' => 'reg_core.tenders', 'mode' => 'all'],
          ['label' => $this->t('Current'), 'route' => 'reg_core.tenders_current', 'mode' => 'current'],
          ['label' => $this->t('Awarded'), 'route' => 'reg_core.tenders_awarded', 'mode' => 'awarded'],
          ['label' => $this->t('Archive'), 'route' => 'reg_core.tenders_archive', 'mode' => 'archive'],
        ],
      ],
      'reg_job' => [
        'title' => $this->t('Jobs'),
        'intro' => $this->t('Current REG Group vacancies, application guidance, recruitment notices, results, and archived opportunities.'),
        'empty' => $this->t('No published job notices matched your filters.'),
        'vocabulary' => '',
        'tabs' => [
          ['label' => $this->t('All jobs'), 'route' => 'reg_core.jobs', 'mode' => 'all'],
          ['label' => $this->t('Current'), 'route' => 'reg_core.jobs_current', 'mode' => 'current'],
          ['label' => $this->t('Results'), 'route' => 'reg_core.jobs_results', 'mode' => 'results'],
          ['label' => $this->t('Archive'), 'route' => 'reg_core.jobs_archive', 'mode' => 'archive'],
        ],
      ],
      default => [
        'title' => $this->t('Publications'),
        'intro' => $this->t('The official REG Group library of reports, policies, plans, safeguards, forms, and guidelines.'),
        'empty' => $this->t('No published documents matched your filters.'),
        'vocabulary' => 'reg_publication_type',
        'tabs' => [],
      ],
    };
    foreach ($definition['tabs'] as &$tab) {
      $tab['url'] = Url::fromRoute($tab['route'])->toString();
      $tab['active'] = $tab['mode'] === $mode;
    }

    return [
      '#theme' => 'reg_public_information_listing',
      '#bundle' => $bundle,
      '#heading' => $definition['title'],
      '#intro' => $definition['intro'],
      '#empty_message' => $definition['empty'],
      '#items' => $items,
      '#results_count' => count($all_items),
      '#filters' => $filters,
      '#tabs' => $definition['tabs'],
      '#category_options' => $definition['vocabulary'] ? $this->repository->taxonomyOptions($definition['vocabulary']) : [],
      '#entity_options' => $this->entityOptions(),
      '#status_options' => $this->statusOptions($bundle),
      '#language_options' => $this->languageOptions(),
      '#years' => range((int) date('Y'), 2000),
      '#pager' => ['#type' => 'pager'],
      '#subscribe_url' => $bundle === 'reg_tender' ? Url::fromRoute('reg_core.tender_subscribe')->toString() : '',
      '#handoff' => [
        'procurement_enabled' => (bool) $settings->get('public_information.enable_tender_submission'),
        'procurement_url' => (string) $settings->get('links.procurement'),
        'job_mode' => (string) ($settings->get('public_information.job_application_mode') ?: 'external'),
        'recruitment_url' => (string) $settings->get('links.recruitment'),
      ],
      '#attached' => $this->metadata(
        Url::fromRoute(match ($bundle) {
          'reg_tender' => 'reg_core.tenders',
          'reg_job' => 'reg_core.jobs',
          default => 'reg_core.publications',
        }, [], ['absolute' => TRUE])->toString(),
        (string) $definition['intro'],
      ),
      '#cache' => $this->listingCache([$bundle]),
    ];
  }

  /**
   * Builds a tender detail page.
   */
  public function tenderDetail(int $tender): array {
    return $this->detail('reg_tender', $tender);
  }

  /**
   * Builds a job detail page.
   */
  public function jobDetail(int $job): array {
    return $this->detail('reg_job', $job);
  }

  /**
   * Builds a publication detail page.
   */
  public function publicationDetail(int $publication): array {
    return $this->detail('reg_publication', $publication);
  }

  /**
   * Returns a title for a tender route.
   */
  public function tenderTitle(int $tender): string {
    return $this->detailTitle('reg_tender', $tender);
  }

  /**
   * Returns a title for a job route.
   */
  public function jobTitle(int $job): string {
    return $this->detailTitle('reg_job', $job);
  }

  /**
   * Returns a title for a publication route.
   */
  public function publicationTitle(int $publication): string {
    return $this->detailTitle('reg_publication', $publication);
  }

  /**
   * Builds the shared detail template.
   */
  private function detail(string $bundle, int $id): array {
    $item = $this->repository->find($bundle, $id);
    if ($item === NULL) {
      throw new NotFoundHttpException();
    }
    $settings = $this->regConfigFactory->get('reg_core.settings');
    $back_route = match ($bundle) {
      'reg_tender' => 'reg_core.tenders',
      'reg_job' => 'reg_core.jobs',
      default => 'reg_core.publications',
    };

    return [
      '#theme' => $bundle === 'reg_tender' ? 'reg_tender_detail' : 'reg_public_information_detail',
      '#item' => $item,
      '#back_url' => Url::fromRoute($back_route)->toString(),
      '#back_label' => match ($bundle) {
        'reg_tender' => $this->t('Back to tenders'),
        'reg_job' => $this->t('Back to jobs'),
        default => $this->t('Back to publications'),
      },
      '#handoff' => [
        'procurement_enabled' => (bool) $settings->get('public_information.enable_tender_submission'),
        'procurement_url' => $item['external_url'] ?: (string) $settings->get('links.procurement'),
        'job_mode' => (string) ($settings->get('public_information.job_application_mode') ?: 'external'),
        'recruitment_url' => $item['application_url'] ?: (string) $settings->get('links.recruitment'),
      ],
      '#attached' => $this->metadata(
        Url::fromRoute(match ($bundle) {
          'reg_tender' => 'reg_core.tender_detail',
          'reg_job' => 'reg_core.job_detail',
          default => 'reg_core.publication_detail',
        }, [match ($bundle) {
          'reg_tender' => 'tender',
          'reg_job' => 'job',
          default => 'publication',
        } => $id], ['absolute' => TRUE])->toString(),
        $item['summary'] ?: $item['description'],
      ),
      '#cache' => [
        'contexts' => ['languages:language_interface', 'user.permissions'],
        'tags' => ['node:' . $id, 'config:reg_core.settings'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Loads a detail title or returns a generic fallback.
   */
  private function detailTitle(string $bundle, int $id): string {
    return (string) ($this->repository->find($bundle, $id)['title'] ?? $this->t('Public information'));
  }

  /**
   * Extracts supported public filters.
   */
  private function filters(Request $request): array {
    return [
      'query' => (string) $request->query->get('q', ''),
      'entity' => (string) $request->query->get('entity', ''),
      'category' => (int) $request->query->get('category', 0),
      'status' => (string) $request->query->get('status', ''),
      'language' => (string) $request->query->get('language', ''),
      'from' => (string) $request->query->get('from', ''),
      'to' => (string) $request->query->get('to', ''),
      'closing' => (string) $request->query->get('closing', ''),
      'year' => (int) $request->query->get('year', 0),
      'sort' => (string) $request->query->get('sort', 'newest'),
    ];
  }

  /**
   * Creates Drupal's pager and returns the current page slice.
   */
  private function paginate(array $items): array {
    $limit = min(48, max(6, (int) ($this->regConfigFactory->get('reg_core.settings')->get('public_information.results_per_page') ?: 12)));
    $pager = $this->regPagerManager->createPager(count($items), $limit);
    return array_slice($items, $pager->getCurrentPage() * $limit, $limit);
  }

  /**
   * Returns company filter labels.
   */
  private function entityOptions(): array {
    return [
      'reg' => $this->t('Rwanda Energy Group (REG)'),
      'eucl' => $this->t('Energy Utility Corporation Limited (EUCL)'),
      'edcl' => $this->t('Energy Development Corporation Limited (EDCL)'),
    ];
  }

  /**
   * Returns official-file language labels.
   */
  private function languageOptions(): array {
    return [
      'en' => $this->t('English'),
      'rw' => $this->t('Kinyarwanda'),
      'fr' => $this->t('French'),
      'multi' => $this->t('Multilingual'),
    ];
  }

  /**
   * Returns lifecycle options for bundle filters.
   */
  private function statusOptions(string $bundle): array {
    return $bundle === 'reg_tender' ? [
      'active' => $this->t('Current / Open'),
      'closed' => $this->t('Closed'),
      'awarded' => $this->t('Awarded'),
      'cancelled' => $this->t('Cancelled'),
      'archived' => $this->t('Archived'),
    ] : ($bundle === 'reg_job' ? [
      'active' => $this->t('Current vacancy'),
      'closed' => $this->t('Closed'),
      'results' => $this->t('Results published'),
      'cancelled' => $this->t('Cancelled'),
      'archived' => $this->t('Archived'),
    ] : []);
  }

  /**
   * Adds canonical and basic social metadata without requiring Metatag.
   */
  private function metadata(string $canonical, string $description): array {
    $description = mb_substr(trim(strip_tags($description)), 0, 240);
    return [
      'library' => ['reg_core/public_information'],
      'html_head_link' => [[['rel' => 'canonical', 'href' => $canonical], TRUE]],
      'html_head' => [
        [['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => $description]], 'reg_public_information_description'],
        [['#tag' => 'meta', '#attributes' => ['property' => 'og:url', 'content' => $canonical]], 'reg_public_information_og_url'],
      ],
    ];
  }

  /**
   * Returns cacheability for filtered listings.
   */
  private function listingCache(array $bundles): array {
    return [
      'contexts' => ['url.query_args', 'languages:language_interface', 'user.permissions'],
      'tags' => array_merge(array_map(static fn(string $bundle): string => 'node_list:' . $bundle, $bundles), [
        'taxonomy_term_list:reg_procurement_category',
        'taxonomy_term_list:reg_publication_type',
        'config:reg_core.settings',
      ]),
      'max-age' => 300,
    ];
  }

}
