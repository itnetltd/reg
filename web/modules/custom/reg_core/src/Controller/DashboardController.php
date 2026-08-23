<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Dashboard\DashboardRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Permission-scoped aggregate REG dashboards and CSV exports.
 */
final class DashboardController extends ControllerBase {

  private const EXPORTS = [
    'branch-usage',
    'customer-services',
    'faq-unanswered',
    'publication-downloads',
    'sports',
    'tender-downloads',
    'translations',
  ];

  public function __construct(
    private readonly DashboardRepository $repository,
    private readonly AccountProxyInterface $regCurrentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reg_core.dashboard_repository'),
      $container->get('current_user'),
    );
  }

  public function overview(Request $request): array {
    return $this->dashboard('overview', $request);
  }

  public function customerServices(Request $request): array {
    return $this->dashboard('customer-services', $request);
  }

  public function content(Request $request): array {
    return $this->dashboard('content', $request);
  }

  public function procurement(Request $request): array {
    return $this->dashboard('procurement', $request);
  }

  public function recruitment(Request $request): array {
    return $this->dashboard('recruitment', $request);
  }

  public function publications(Request $request): array {
    return $this->dashboard('publications', $request);
  }

  public function sports(Request $request): array {
    return $this->dashboard('sports', $request);
  }

  public function operations(Request $request): array {
    return $this->dashboard('operations', $request);
  }

  public function export(string $report, Request $request): Response {
    if (!in_array($report, self::EXPORTS, TRUE)) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    [$header, $rows] = $this->repository->export($report, $request->query->all());
    $stream = fopen('php://temp', 'w+');
    if ($stream === FALSE) {
      return new Response('', 500);
    }
    fputcsv($stream, array_map([self::class, 'safeCsvCell'], $header));
    foreach ($rows as $row) {
      fputcsv($stream, array_map([self::class, 'safeCsvCell'], $row));
    }
    rewind($stream);
    $csv = stream_get_contents($stream) ?: '';
    fclose($stream);
    $response = new Response("\xEF\xBB\xBF" . $csv);
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="reg-' . $report . '-' . gmdate('Y-m-d') . '.csv"');
    $response->headers->set('Cache-Control', 'private, no-store');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

  /**
   * Prevents spreadsheet formula execution in exported editorial labels.
   */
  public static function safeCsvCell(mixed $value): string {
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value) ?? '';
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
  }

  private function dashboard(string $section, Request $request): array {
    $filters = DashboardRepository::normalizeFilters($request->query->all());
    $data = $this->repository->section($section, $filters);
    return [
      '#theme' => 'reg_dashboard',
      '#section' => $section,
      '#title_text' => $data['title'] ?? 'REG dashboard',
      '#notice' => $data['notice'] ?? '',
      '#cards' => $data['cards'] ?? [],
      '#tables' => $data['tables'] ?? [],
      '#filters' => $filters,
      '#navigation' => $this->navigation($section),
      '#exports' => $this->exports($section, $filters),
      '#content_types' => [
        'reg_service' => 'Service',
        'reg_outage' => 'Outage',
        'reg_faq' => 'FAQ',
        'reg_branch' => 'Branch',
        'reg_public_alert' => 'Public Alert',
        'reg_tender' => 'Tender',
        'reg_job' => 'Job',
        'reg_publication' => 'Publication',
        'reg_news' => 'News',
        'reg_energy_tool' => 'Energy Tool',
        'reg_sports_update' => 'Sports news',
      ],
      '#attached' => ['library' => ['reg_core/admin_dashboard']],
      '#cache' => [
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['reg_core:analytics', 'node_list', 'config:reg_core.settings'],
        'max-age' => 300,
      ],
    ];
  }

  private function navigation(string $section): array {
    $definitions = [
      'overview' => ['Overview', 'reg_core.dashboard', 'view reg analytics dashboard'],
      'customer-services' => ['Customer services', 'reg_core.dashboard_customer_services', 'view reg customer service dashboard'],
      'content' => ['Content', 'reg_core.dashboard_content', 'view reg content dashboard'],
      'procurement' => ['Procurement', 'reg_core.dashboard_procurement', 'view reg procurement dashboard'],
      'recruitment' => ['Recruitment', 'reg_core.dashboard_recruitment', 'view reg recruitment dashboard'],
      'publications' => ['Publications', 'reg_core.dashboard_publications', 'view reg publications dashboard'],
      'sports' => ['Sports', 'reg_core.dashboard_sports', 'view reg sports dashboard'],
      'translations' => ['Translations', 'reg_core.dashboard_translations', 'view reg translation report'],
      'operations' => ['Operations', 'reg_core.dashboard_operations', 'view reg operations dashboard'],
    ];
    $items = [];
    foreach ($definitions as $key => [$label, $route, $permission]) {
      if ($this->regCurrentUser->hasPermission($permission)) {
        $items[] = ['label' => $label, 'url' => Url::fromRoute($route)->toString(), 'active' => $key === $section];
      }
    }
    return $items;
  }

  private function exports(string $section, array $filters): array {
    if (!$this->regCurrentUser->hasPermission('export reg analytics reports')) {
      return [];
    }
    $reports = match ($section) {
      'customer-services' => ['customer-services' => 'Customer-service aggregates', 'branch-usage' => 'Branch usage', 'faq-unanswered' => 'FAQ unanswered searches'],
      'procurement' => ['tender-downloads' => 'Tender downloads'],
      'publications' => ['publication-downloads' => 'Publication downloads'],
      'sports' => ['sports' => 'Sports aggregates'],
      'content' => ['translations' => 'Translation status'],
      default => [],
    };
    $items = [];
    foreach ($reports as $report => $label) {
      $items[] = [
        'label' => $label,
        'url' => Url::fromRoute('reg_core.dashboard_export', ['report' => $report], ['query' => array_filter($filters)])->toString(),
      ];
    }
    return $items;
  }

}
