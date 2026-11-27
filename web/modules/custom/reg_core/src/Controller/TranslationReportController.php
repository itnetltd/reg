<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\reg_core\Translation\TranslationStatusRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Searchable administrative translation workspace.
 */
final class TranslationReportController extends ControllerBase {

  public function __construct(
    private readonly TranslationStatusRepository $repository,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('reg_core.translation_status_repository'));
  }

  /**
   * Uses native Drupal translation routes, access checks and moderation.
   */
  public function report(Request $request): array {
    $query = $request->query->all();
    $value = static fn(string $key, string $default = ''): string =>
      is_string($query[$key] ?? NULL) ? trim($query[$key]) : $default;
    $status = $value('status', 'missing');
    $target = $value('target');
    // Preserve old bookmarked report filters.
    if ($status === 'missing_rw') {
      $status = 'missing';
      $target = 'rw';
    }
    if ($status === 'pending_review') {
      $status = 'pending';
    }
    $filters = [
      'status' => in_array($status, ['all', 'missing', 'pending', 'outdated', 'complete'], TRUE) ? $status : 'missing',
      'target' => in_array($target, ['en', 'rw'], TRUE) ? $target : '',
      'q' => mb_substr($value('q'), 0, 120),
      'content_type' => preg_match('/^[a-z0-9_]+$/D', $value('content_type')) ? $value('content_type') : '',
      'owner' => mb_substr($value('owner'), 0, 80),
      'published' => in_array($value('published'), ['published', 'unpublished'], TRUE) ? $value('published') : '',
    ];
    $result = $this->repository->workspace($filters);
    $total = count($result['rows']);
    $pager = \Drupal::service('pager.manager')->createPager($total, 25);
    $rows = array_slice($result['rows'], $pager->getCurrentPage() * 25, 25);
    $route = \Drupal::routeMatch()->getRouteName();
    $destination = Url::fromRoute($route, [], ['query' => $filters + ['page' => $pager->getCurrentPage()]])->toString();
    $languages = \Drupal::languageManager()->getLanguages();
    foreach ($rows as &$row) {
      $node = $row['id'];
      $row['actions'] = [];
      $overview = Url::fromRoute('entity.node.content_translation_overview', ['node' => $node]);
      if ($overview->access()) {
        $row['overview_url'] = $overview->toString();
      }
      foreach (['en', 'rw'] as $langcode) {
        if (!isset($languages[$langcode]) || $row['states'][$langcode] === 'restricted') {
          continue;
        }
        $options = ['language' => $languages[$langcode], 'query' => ['destination' => $destination]];
        if ($row['states'][$langcode] === 'missing') {
          if (!isset($languages[$row['source_language']])) {
            continue;
          }
          $url = Url::fromRoute('entity.node.content_translation_add', [
            'node' => $node, 'source' => $row['source_language'], 'target' => $langcode,
          ], $options);
          $operation = 'add';
        }
        elseif ($row['source_language'] === $langcode) {
          $url = Url::fromRoute('entity.node.edit_form', ['node' => $node], $options);
          $operation = 'edit';
        }
        else {
          $url = Url::fromRoute('entity.node.content_translation_edit', [
            'node' => $node, 'language' => $langcode,
          ], $options);
          $operation = 'edit';
        }
        if ($url->access()) {
          $row['actions'][$langcode] = ['url' => $url->toString(), 'operation' => $operation];
        }
      }
    }
    unset($row);
    $tabs = [];
    foreach ($result['summary'] as $key => $count) {
      $tabFilters = array_replace($filters, ['status' => $key]);
      $tabs[$key] = ['count' => $count, 'url' => Url::fromRoute($route, [], ['query' => $tabFilters])->toString()];
    }
    return [
      '#theme' => 'reg_translation_report',
      '#rows' => $rows,
      '#summary' => $result['summary'],
      '#filters' => $filters,
      '#bundles' => $result['bundles'],
      '#tabs' => $tabs,
      '#total' => $total,
      '#first' => $total ? $pager->getCurrentPage() * 25 + 1 : 0,
      '#last' => min($total, ($pager->getCurrentPage() + 1) * 25),
      '#clear_url' => Url::fromRoute($route)->toString(),
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['reg_core/admin_dashboard', 'reg_core/translation_workspace']],
      '#cache' => ['contexts' => ['url.query_args', 'user.permissions', 'user.node_grants:view'], 'max-age' => 0],
    ];
  }

}
