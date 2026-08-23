<?php

namespace Drupal\reg_core\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Outage\OutageRepositoryInterface;
use Drupal\reg_core\Formatting\PublicNumberFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public Outage Center pages backed by the source-independent repository.
 */
final class OutageController extends ControllerBase {

  public function __construct(
    private readonly OutageRepositoryInterface $outageRepository,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly ConfigFactoryInterface $regConfigFactory,
    private readonly PublicNumberFormatter $numberFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(OutageRepositoryInterface::class),
      $container->get('date.formatter'),
      $container->get('config.factory'),
      $container->get('reg_core.public_number_formatter'),
    );
  }

  /**
   * Builds current, planned, or historical outage listings.
   */
  public function listing(Request $request, string $mode = 'current'): array {
    $mode = in_array($mode, ['current', 'planned', 'history', 'resolved'], TRUE) ? $mode : 'current';
    $filters = [
      'district' => trim((string) $request->query->get('district', '')),
      'sector' => trim((string) $request->query->get('sector', '')),
      'search' => mb_substr(trim((string) $request->query->get('search', '')), 0, 120),
      'date' => trim((string) $request->query->get('date', '')),
      'status' => trim((string) $request->query->get('status', '')),
      'type' => trim((string) $request->query->get('type', '')),
      'mode' => $mode,
    ];
    $all_result = $this->outageRepository->getOutages();
    $result = $this->outageRepository->getOutages($filters);

    $districts = $this->uniqueListValues($all_result->outages, 'districts');
    $sector_records = $filters['district'] === '' ? $all_result->outages : array_filter($all_result->outages, static fn(array $outage): bool => in_array(mb_strtolower($filters['district']), array_map('mb_strtolower', $outage['districts'] ?? []), TRUE));
    $sectors = $this->uniqueListValues($sector_records, 'sectors');
    $outages = array_map(fn(array $outage): array => $this->viewModel($outage), $result->outages);

    return [
      '#theme' => 'reg_outage_center',
      '#outages' => $outages,
      '#mode' => $mode,
      '#filters' => $filters,
      '#districts' => $districts,
      '#sectors' => $sectors,
      '#statuses' => ['scheduled' => $this->t('Scheduled'), 'ongoing' => $this->t('Ongoing'), 'awaiting_confirmation' => $this->t('Scheduled window ended'), 'restored' => $this->t('Restored'), 'postponed' => $this->t('Postponed'), 'cancelled' => $this->t('Cancelled')],
      '#types' => ['planned_maintenance' => $this->t('Planned Maintenance'), 'planned_upgrade' => $this->t('Network Upgrade / Extension'), 'emergency' => $this->t('Emergency / Unplanned'), 'network_repair' => $this->t('Network Repair'), 'other' => $this->t('Other')],
      '#tabs' => $this->tabs(),
      '#source_message' => $result->message,
      '#fallback' => $result->isFallback(),
      '#last_updated' => $this->latestUpdate($outages),
      '#results_count' => count($outages),
      '#call_center' => $this->callCenter(),
      '#attached' => ['library' => ['reg_core/outage_center']],
      '#cache' => [
        'contexts' => ['url.path', 'url.query_args'],
        'tags' => ['reg_core:outages', 'node_list:reg_outage', 'config:reg_core.settings'],
        'max-age' => 60,
      ],
    ];
  }

  /**
   * Builds a public outage details page.
   */
  public function detail(string $outage_id): array {
    $result = $this->outageRepository->getOutages();
    $record = $this->findOutage($result->outages, $outage_id);
    if ($record === NULL) {
      throw new NotFoundHttpException('The requested outage could not be found.');
    }

    return [
      '#theme' => 'reg_outage_detail',
      '#outage' => $this->viewModel($record),
      '#source_message' => $result->message,
      '#fallback' => $result->isFallback(),
      '#back_url' => Url::fromRoute('reg_core.outages')->toString(),
      '#call_center' => $this->callCenter(),
      '#attached' => ['library' => ['reg_core/outage_center']],
      '#cache' => [
        'contexts' => ['url.path'],
        'tags' => ['reg_core:outages', 'node_list:reg_outage', 'config:reg_core.settings'],
        'max-age' => 60,
      ],
    ];
  }

  /**
   * Returns a safe title for an outage details route.
   */
  public function detailTitle(string $outage_id): string {
    $record = $this->outageRepository->getOutage($outage_id);
    if ($record === NULL) {
      return (string) $this->t('Outage details');
    }
    return (string) (($record['title'] ?? '') ?: $this->t('Outage details'));
  }

  /**
   * Prepares one normalized record for Twig without adding source fields.
   */
  private function viewModel(array $outage): array {
    $status = (string) ($outage['status'] ?? 'update');
    $type = (string) ($outage['outage_type'] ?? 'unspecified');
    $outage['status_label'] = match ($status) {
      'awaiting_confirmation' => (string) $this->t('Scheduled window ended'),
      default => ucfirst(str_replace('_', ' ', $status)),
    };
    $outage['status_class'] = Html::getClass($status);
    $outage['outage_type_label'] = ucfirst(str_replace('_', ' ', $type));
    $outage['start_time_formatted'] = $this->formatDate($outage['start_time'] ?? NULL);
    $outage['expected_restoration_formatted'] = $this->formatDate($outage['expected_restoration'] ?? NULL);
    $outage['actual_restoration_formatted'] = $this->formatDate($outage['actual_restoration'] ?? NULL);
    $outage['last_updated_formatted'] = $this->formatDate($outage['last_updated'] ?? NULL);
    $outage['segments'] = array_map(function (array $segment): array {
      $segment['date_formatted'] = $this->formatDateOnly($segment['start_time'] ?? NULL);
      $segment['start_formatted'] = $this->formatTime($segment['start_time'] ?? NULL);
      $segment['end_formatted'] = $this->formatTime($segment['end_time'] ?? NULL);
      $segment['actual_restoration_formatted'] = $this->formatDate($segment['actual_restoration'] ?? NULL);
      return $segment;
    }, $outage['segments'] ?? []);
    $outage['customers_affected_formatted'] = isset($outage['customers_affected'])
      ? $this->numberFormatter->integer((int) $outage['customers_affected'])
      : NULL;
    $outage['detail_url'] = Url::fromRoute('reg_core.outage_detail', ['outage_id' => $outage['outage_id']])->toString();
    return $outage;
  }

  /**
   * Formats an ISO timestamp in the Drupal site timezone.
   */
  private function formatDate(mixed $value): ?string {
    if (!is_string($value) || $value === '') {
      return NULL;
    }
    try {
      return $this->dateFormatter->format((new \DateTimeImmutable($value))->getTimestamp(), 'custom', 'd M Y, H:i T');
    }
    catch (\Exception) {
      return NULL;
    }
  }

  private function formatDateOnly(mixed $value): ?string {
    if (!is_string($value) || $value === '') return NULL;
    try { return $this->dateFormatter->format((new \DateTimeImmutable($value))->getTimestamp(), 'custom', 'D, d M Y'); }
    catch (\Exception) { return NULL; }
  }

  private function formatTime(mixed $value): ?string {
    if (!is_string($value) || $value === '') return NULL;
    try { return $this->dateFormatter->format((new \DateTimeImmutable($value))->getTimestamp(), 'custom', 'H:i'); }
    catch (\Exception) { return NULL; }
  }

  /**
   * Returns sorted unique values for a public location field.
   */
  private function uniqueValues(array $outages, string $key): array {
    $values = [];
    foreach ($outages as $outage) {
      $value = trim((string) ($outage[$key] ?? ''));
      if ($value !== '') {
        $values[mb_strtolower($value)] = $value;
      }
    }
    natcasesort($values);
    return array_values($values);
  }

  private function uniqueListValues(array $outages, string $key): array {
    $values = [];
    foreach ($outages as $outage) {
      foreach ($outage[$key] ?? [] as $value) $values[mb_strtolower($value)] = $value;
    }
    natcasesort($values);
    return array_values($values);
  }

  /**
   * Returns the most recent formatted update from view-model records.
   */
  private function latestUpdate(array $outages): ?string {
    $latest = NULL;
    foreach ($outages as $outage) {
      $raw = $outage['last_updated'] ?? NULL;
      if (is_string($raw) && ($latest === NULL || $raw > $latest)) {
        $latest = $raw;
      }
    }
    return $this->formatDate($latest);
  }

  /**
   * Builds route-backed tabs for keyboard and no-JavaScript navigation.
   */
  private function tabs(): array {
    return [
      'current' => ['label' => $this->t('Current Outages'), 'url' => Url::fromRoute('reg_core.outages_current')->toString()],
      'planned' => ['label' => $this->t('Planned Outages'), 'url' => Url::fromRoute('reg_core.outages_planned')->toString()],
      'history' => ['label' => $this->t('Resolved'), 'url' => Url::fromRoute('reg_core.outages_history')->toString()],
    ];
  }

  /**
   * Finds a record using constant-time public ID comparison.
   */
  private function findOutage(array $outages, string $outage_id): ?array {
    foreach ($outages as $outage) {
      if (hash_equals((string) ($outage['outage_id'] ?? ''), $outage_id)) {
        return $outage;
      }
    }
    return NULL;
  }

  /**
   * Returns configured public call-centre display and telephone values.
   *
   * @return array{label: string, telephone: string}
   *   Public support contact values.
   */
  private function callCenter(): array {
    $label = trim((string) ($this->regConfigFactory->get('reg_core.settings')->get('support.call_center') ?: '2727'));
    $telephone = preg_replace('/[^0-9+]/', '', $label) ?: '2727';
    return ['label' => $label, 'telephone' => $telephone];
  }

}
