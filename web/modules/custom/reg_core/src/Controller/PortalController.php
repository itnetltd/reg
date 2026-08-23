<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Outage\OutageRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public service pages and the FAQ JSON endpoint.
 */
final class PortalController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly OutageRepositoryInterface $outageRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('reg_core.dms_outage_repository'),
    );
  }

  /**
   * Returns the dedicated front-page route; presentation is handled by Twig.
   */
  public function home(): array {
    return [
      '#markup' => '',
      '#cache' => ['max-age' => 3600],
    ];
  }

  /**
   * Builds the phase-one online services gateway.
   */
  public function services(): array {
    $nodes = $this->loadNodes('reg_service', 50, 'field_reg_order', 'ASC');
    $items = [];

    foreach ($nodes as $node) {
      $url = $this->linkFieldUrl($node, 'field_reg_url') ?: $node->toUrl()->toString();
      $items[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-portal-card']],
        'title' => ['#markup' => '<h2>' . Html::escape($node->label()) . '</h2>'],
        'summary' => ['#markup' => '<p>' . Html::escape($this->plainValue($node, 'field_reg_summary')) . '</p>'],
        'link' => Link::fromTextAndUrl($this->t('Open service'), $this->urlFromString($url))->toRenderable(),
      ];
    }

    if (!$items) {
      $settings = $this->config('reg_core.settings');
      $fallback = [
        ['Online services', 'Access REG/EUCL digital services.', $settings->get('links.online_services')],
        ['New connection', 'Start or follow the electricity connection process.', $settings->get('links.new_connection')],
        ['Outage Center', 'Check current and planned interruptions.', Url::fromRoute('reg_core.outages')->toString()],
        ['Bill estimator', 'Estimate electricity cost using approved tariff assumptions.', Url::fromRoute('reg_core.bill_estimator')->toString()],
        ['Carbon calculator', 'Estimate electricity-related carbon impact.', Url::fromRoute('reg_core.carbon_calculator')->toString()],
        ['Frequently asked questions', 'Find approved customer-support answers.', Url::fromRoute('reg_core.faq')->toString()],
      ];
      foreach ($fallback as [$title, $summary, $url]) {
        $items[] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['reg-portal-card']],
          'title' => ['#markup' => '<h2>' . $title . '</h2>'],
          'summary' => ['#markup' => '<p>' . $summary . '</p>'],
          'link' => $url ? Link::fromTextAndUrl($this->t('Open service'), $this->urlFromString($url))->toRenderable() : ['#markup' => '<span>' . $this->t('Awaiting approved service URL') . '</span>'],
        ];
      }
    }

    return [
      '#attached' => ['library' => ['reg_core/portal']],
      '#prefix' => '<div class="reg-container reg-portal">',
      '#suffix' => '</div>',
      'intro' => [
        '#markup' => '<p class="reg-portal__intro">' . $this->t('This gateway brings REG services into one consistent customer experience. Transactional systems remain authoritative and are opened through approved links, secure handoffs or future APIs.') . '</p>',
      ],
      'grid' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-portal__grid']],
        'items' => $items,
      ],
      '#cache' => ['tags' => ['node_list:reg_service', 'config:reg_core.settings']],
    ];
  }

  /**
   * Builds the DMS-authoritative outage center with emergency fallback notices.
   */
  public function outages(Request $request): array {
    $district = trim((string) $request->query->get('district', ''));
    $status = trim((string) $request->query->get('status', ''));
    $status = in_array($status, ['current', 'planned', 'resolved'], TRUE) ? $status : '';
    $result = $this->outageRepository->getOutages([
      'district' => $district,
      'status' => $status,
    ]);

    $rows = [];
    foreach ($result->outages as $outage) {
      $title = (string) (($outage['affected_area'] ?? '') ?: ($outage['outage_id'] ?? ''));
      $location = implode(' · ', array_filter([
        (string) ($outage['district'] ?? ''),
        (string) ($outage['sector'] ?? ''),
      ]));
      $timing = array_filter([
        $this->formatOutageDate('Started', $outage['start_time'] ?? NULL),
        $this->formatOutageDate('Expected restoration', $outage['expected_restoration'] ?? NULL),
        $this->formatOutageDate('Restored', $outage['actual_restoration'] ?? NULL),
      ]);
      $coordinates = $outage['coordinates'] ?? NULL;
      $map_summary = is_array($coordinates)
        ? (string) $this->t('Coordinates: @lat, @lon', [
          '@lat' => $coordinates['latitude'],
          '@lon' => $coordinates['longitude'],
        ])
        : '';
      if (is_array($outage['geojson'] ?? NULL)) {
        $map_summary = trim($map_summary . ' ' . $this->t('Mapped affected-area data is available.'));
      }

      $rows[] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-outage-item']],
        'content' => [
          '#markup' => '<div><h2>' . Html::escape($title) . '</h2>'
            . '<p>' . Html::escape((string) ($outage['public_cause'] ?? '')) . '</p>'
            . (!empty($outage['customer_impact']) ? '<p><strong>' . $this->t('Customer impact:') . '</strong> ' . Html::escape((string) $outage['customer_impact']) . '</p>' : '')
            . ($location !== '' ? '<small>' . Html::escape($location) . '</small>' : '')
            . ($timing ? '<small>' . Html::escape(implode(' · ', $timing)) . '</small>' : '')
            . ($map_summary !== '' ? '<small>' . Html::escape($map_summary) . '</small>' : '')
            . (!empty($outage['last_updated']) ? '<small>' . Html::escape($this->formatOutageDate('Last updated', $outage['last_updated'])) . '</small>' : '')
            . '<small>' . Html::escape((string) $this->t('Outage ID: @id · Type: @type', [
              '@id' => $outage['outage_id'] ?? '',
              '@type' => ($outage['type'] ?? '') ?: 'unspecified',
            ])) . '</small></div>',
        ],
        'badge' => [
          '#markup' => '<span class="reg-status-badge">' . Html::escape((string) (($outage['status'] ?? '') ?: $this->t('Update'))) . '</span>',
        ],
      ];
    }

    if (!$rows) {
      $rows['empty'] = ['#markup' => '<p>' . $this->t('No outage records matched your filters.') . '</p>'];
    }

    return [
      '#attached' => ['library' => ['reg_core/portal']],
      '#prefix' => '<div class="reg-container reg-portal">',
      '#suffix' => '</div>',
      'intro' => [
        '#markup' => '<p class="reg-portal__intro' . ($result->isFallback() ? ' reg-portal__intro--warning' : '') . '">' . Html::escape($result->message) . '</p>',
      ],
      'filter' => [
        '#markup' => '<form class="reg-filter-form" method="get"><label><span class="visually-hidden">' . $this->t('District or location') . '</span><input name="district" value="' . Html::escape($district) . '" placeholder="' . $this->t('District or location') . '"></label><label><span class="visually-hidden">' . $this->t('Status') . '</span><select name="status"><option value="">' . $this->t('All statuses') . '</option><option value="current"' . ($status === 'current' ? ' selected' : '') . '>' . $this->t('Current') . '</option><option value="planned"' . ($status === 'planned' ? ' selected' : '') . '>' . $this->t('Planned') . '</option><option value="resolved"' . ($status === 'resolved' ? ' selected' : '') . '>' . $this->t('Resolved') . '</option></select></label><button type="submit">' . $this->t('Search outages') . '</button></form>',
      ],
      'list' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-list']],
        'rows' => $rows,
      ],
      '#cache' => [
        'contexts' => ['url.query_args:district', 'url.query_args:status'],
        'tags' => ['reg_core:dms_outages', 'node_list:reg_outage', 'config:reg_core.settings'],
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Formats a normalized DMS timestamp for concise public display.
   */
  private function formatOutageDate(string $label, mixed $value): string {
    if (!is_string($value) || $value === '') {
      return '';
    }
    try {
      return $label . ': ' . (new \DateTimeImmutable($value))->format('Y-m-d H:i T');
    }
    catch (\Exception) {
      return '';
    }
  }

  /**
   * Loads published nodes of a bundle.
   *
   * @return \Drupal\node\NodeInterface[]
   */
  private function loadNodes(string $bundle, int $limit, string $sort, string $direction): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort($sort, $direction)
      ->range(0, $limit);
    return $storage->loadMultiple($query->execute());
  }

  /**
   * Returns a safe plain-text field value.
   */
  private function plainValue(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    $item = $node->get($field)->first();
    $value = $item?->get('value')->getValue();
    return trim(strip_tags((string) $value));
  }

  /**
   * Converts a stored URL string into a Drupal URL object.
   */
  private function urlFromString(string $url): Url {
    return str_starts_with($url, '/')
      ? Url::fromUserInput($url)
      : Url::fromUri($url);
  }

  /**
   * Returns a link field URI, normalized for Url::fromUri().
   */
  private function linkFieldUrl(NodeInterface $node, string $field): ?string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) {
      return NULL;
    }
    $uri = (string) $node->get($field)->first()?->get('uri')->getValue();
    if ($uri === '') {
      return NULL;
    }
    return str_starts_with($uri, '/') ? 'internal:' . $uri : $uri;
  }

}
