<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\reg_core\Translation\TranslationStatusRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Administrative translation completeness report.
 */
final class TranslationReportController extends ControllerBase {

  public function __construct(
    private readonly TranslationStatusRepository $repository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('reg_core.translation_status_repository'));
  }

  /**
   * Builds the filtered bilingual governance report.
   */
  public function report(Request $request): array {
    $filters = [
      'status' => (string) $request->query->get('status', ''),
      'content_type' => (string) $request->query->get('content_type', ''),
      'owner' => (string) $request->query->get('owner', ''),
      'published' => (string) $request->query->get('published', ''),
    ];
    $rows = $this->repository->report($filters);
    return [
      '#theme' => 'reg_translation_report',
      '#rows' => $rows,
      '#summary' => $this->repository->summary($rows),
      '#filters' => $filters,
      '#bundles' => $this->repository->bundleOptions(),
      '#attached' => ['library' => ['reg_core/admin_dashboard']],
      '#cache' => ['contexts' => ['url.query_args', 'user.permissions'], 'max-age' => 0],
    ];
  }

}
