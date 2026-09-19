<?php

namespace Drupal\reg_core\Energy;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\reg_core\Entity\BillTariffScheduleInterface;
use Drupal\reg_core\Exception\BillEstimatorUnavailableException;
use Psr\Log\LoggerInterface;

/**
 * Resolves effective schedules and calculates the REG bill estimate.
 */
final class BillEstimator implements BillEstimatorInterface {

  private const GROUP_LABELS = [
    'household_general' => 'Households and general customers',
    'sector_specific' => 'Sector-specific all-energy tariffs',
    'industrial_prepaid' => 'Industrial prepaid — without smart meter',
    'industrial_smart' => 'Industrial postpaid — smart meter',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  public function getCategoryOptions(?string $date = NULL): array {
    $date ??= $this->today();
    $candidates = [];
    foreach ($this->loadEnabledSchedules() as $schedule) {
      $values = $schedule->toCalculationArray();
      if ($schedule->appliesOn($date)) {
        $candidates[$values['category']][] = $values;
      }
    }

    $options = [];
    foreach ($candidates as $category => $schedules) {
      if (count($schedules) !== 1) {
        $this->logger->error('Bill estimator category @category has @count applicable schedules on @date.', [
          '@category' => $category,
          '@count' => count($schedules),
          '@date' => $date,
        ]);
        continue;
      }
      $schedule = reset($schedules);
      $group = self::GROUP_LABELS[$schedule['customer_group']] ?? self::GROUP_LABELS['household_general'];
      $options[$group][$category] = $schedule['label'];
    }
    if ($options === []) {
      $this->logger->warning('Bill estimator has no single approved tariff schedule applicable on @date.', ['@date' => $date]);
    }
    return $options;
  }

  public function hasActiveSchedule(?string $date = NULL): bool {
    return $this->getCategoryOptions($date) !== [];
  }

  public function getActiveSchedule(string $category, ?string $date = NULL): array {
    $date ??= $this->today();
    $schedules = array_map(
      static fn(BillTariffScheduleInterface $schedule): array => $schedule->toCalculationArray(),
      $this->loadEnabledSchedules(),
    );
    try {
      return self::selectApplicableSchedule($schedules, $category, $date);
    }
    catch (BillEstimatorUnavailableException $exception) {
      $this->logger->error('Bill estimator unavailable for @category on @date: @message', [
        '@category' => $category ?: '(none)',
        '@date' => $date,
        '@message' => $exception->getMessage(),
      ]);
      throw $exception;
    }
  }

  public function calculate(string $category, float $consumption, array $demand = [], ?string $date = NULL): array {
    $schedule = $this->getActiveSchedule($category, $date);
    $result = self::calculateSchedule($schedule, $consumption, $demand, $this->getPublicSettings()['fees']);
    $result['disclaimer'] = $this->getPublicSettings()['disclaimer'];
    return $result;
  }

  public function getPublicSettings(): array {
    $config = $this->configFactory->get('reg_core.bill_estimator');
    return [
      'currency' => (string) ($config->get('currency') ?: 'RWF'),
      'public_note' => (string) $config->get('public_note'),
      'disclaimer' => (string) $config->get('disclaimer'),
      'fees' => is_array($config->get('fees')) ? $config->get('fees') : [],
    ];
  }

  /**
   * Selects exactly one enabled schedule for a category and date.
   */
  public static function selectApplicableSchedule(array $schedules, string $category, string $date): array {
    $matches = array_values(array_filter($schedules, static function (array $schedule) use ($category, $date): bool {
      return ($schedule['category'] ?? '') === $category
        && ($schedule['effective_from'] ?? '') !== ''
        && $schedule['effective_from'] <= $date
        && (($schedule['effective_to'] ?? '') === '' || $schedule['effective_to'] >= $date);
    }));
    if (count($matches) !== 1) {
      throw new BillEstimatorUnavailableException(count($matches) === 0
        ? 'No approved tariff schedule is effective for this category.'
        : 'More than one approved tariff schedule is effective for this category.');
    }
    return $matches[0];
  }

  /**
   * Calculates one schedule without service dependencies for reliable tests.
   */
  public static function calculateSchedule(array $schedule, float $consumption, array $demand = [], array $fees = []): array {
    if ($consumption < 0) {
      throw new \InvalidArgumentException('Consumption cannot be negative.');
    }
    foreach ($demand as $value) {
      if ((float) $value < 0) {
        throw new \InvalidArgumentException('Maximum demand cannot be negative.');
      }
    }

    $rows = [];
    $type = $schedule['tariff_type'] ?? '';
    if ($type === 'progressive') {
      $bands = array_values(array_filter($schedule['bands'] ?? [], static fn(array $band): bool => (bool) ($band['enabled'] ?? FALSE)));
      usort($bands, static fn(array $a, array $b): int => ((int) ($a['weight'] ?? 0)) <=> ((int) ($b['weight'] ?? 0)));
      foreach ($bands as $band) {
        $minimum = (float) ($band['min'] ?? 0);
        $maximum = ($band['max'] ?? '') === '' || ($band['max'] ?? NULL) === NULL ? NULL : (float) $band['max'];
        $quantity = max(0.0, ($maximum === NULL ? $consumption : min($consumption, $maximum)) - $minimum);
        if ($quantity > 0) {
          $rows[] = self::chargeRow((string) $band['label'], $quantity, (float) $band['rate'], 'kWh');
        }
      }
    }
    elseif ($type === 'flat') {
      $label = ($schedule['customer_group'] ?? '') === 'industrial_prepaid' ? 'Prepaid flat energy charge' : 'Energy charge';
      $rows[] = self::chargeRow($label, $consumption, (float) ($schedule['flat_rate'] ?? 0), 'kWh');
    }
    elseif ($type === 'industrial') {
      $rows = [
        self::chargeRow('Energy charge', $consumption, (float) ($schedule['energy_rate'] ?? 0), 'kWh'),
        self::chargeRow('Peak maximum-demand charge', (float) ($demand['peak'] ?? 0), (float) ($schedule['peak_demand_rate'] ?? 0), 'kVA'),
        self::chargeRow('Off-peak maximum-demand charge', (float) ($demand['off_peak'] ?? 0), (float) ($schedule['off_peak_demand_rate'] ?? 0), 'kVA'),
        self::chargeRow('Shoulder maximum-demand charge', (float) ($demand['shoulder'] ?? 0), (float) ($schedule['shoulder_demand_rate'] ?? 0), 'kVA'),
      ];
    }
    else {
      throw new BillEstimatorUnavailableException('The applicable tariff schedule has an unsupported calculation type.');
    }

    $subtotal = array_sum(array_column($rows, 'amount'));
    $feeRows = self::calculateFees($subtotal, $fees);
    return [
      'category' => (string) ($schedule['label'] ?? $schedule['category'] ?? ''),
      'category_id' => (string) ($schedule['category'] ?? ''),
      'tariff_type' => $type,
      'consumption' => $consumption,
      'rows' => array_merge($rows, $feeRows),
      'subtotal' => $subtotal,
      'total' => $subtotal + array_sum(array_column($feeRows, 'amount')),
      'effective_date' => (string) ($schedule['effective_from'] ?? ''),
      'source_title' => (string) ($schedule['source_title'] ?? ''),
      'source_reference' => (string) ($schedule['source_reference'] ?? ''),
      'source_url' => (string) ($schedule['source_url'] ?? ''),
    ];
  }

  /** Returns validation messages for progressive tariff bands. */
  public static function validateBands(array $bands): array {
    $errors = [];
    $enabled = array_values(array_filter($bands, static fn(array $band): bool => (bool) ($band['enabled'] ?? FALSE)));
    usort($enabled, static fn(array $a, array $b): int => ((float) ($a['min'] ?? 0)) <=> ((float) ($b['min'] ?? 0)));
    $previousMax = NULL;
    foreach ($enabled as $index => $band) {
      $min = (float) ($band['min'] ?? 0);
      $max = ($band['max'] ?? '') === '' ? NULL : (float) $band['max'];
      $rate = (float) ($band['rate'] ?? 0);
      if ($min < 0 || $rate < 0 || ($max !== NULL && $max <= $min)) {
        $errors[] = 'Band ' . ($index + 1) . ' has an invalid range or negative value.';
      }
      if ($previousMax === NULL && $index > 0) {
        $errors[] = 'An open-ended band must be the last enabled band.';
      }
      elseif ($previousMax !== NULL && $min < $previousMax) {
        $errors[] = 'Enabled tariff bands overlap.';
      }
      $previousMax = $max;
    }
    return array_values(array_unique($errors));
  }

  /** Returns whether two inclusive effective-date ranges overlap. */
  public static function dateRangesOverlap(string $startA, string $endA, string $startB, string $endB): bool {
    $endA = $endA === '' ? '9999-12-31' : $endA;
    $endB = $endB === '' ? '9999-12-31' : $endB;
    return $startA <= $endB && $startB <= $endA;
  }

  /** Returns validation messages for one configured fee. */
  public static function validateFee(array $fee): array {
    $errors = [];
    $value = (float) ($fee['value'] ?? 0);
    if ($value < 0) {
      $errors[] = 'Fee values cannot be negative.';
    }
    if (($fee['type'] ?? '') === 'percentage' && $value > 100) {
      $errors[] = 'Percentage fees cannot exceed 100%.';
    }
    if (!empty($fee['included']) && empty($fee['enabled'])) {
      $errors[] = 'A fee must be approved and available before it can be included.';
    }
    return $errors;
  }

  private static function calculateFees(float $subtotal, array $fees): array {
    $rows = [];
    foreach ($fees as $fee) {
      if (empty($fee['enabled']) || empty($fee['included'])) {
        continue;
      }
      $value = (float) ($fee['value'] ?? 0);
      $type = (string) ($fee['type'] ?? 'percentage');
      $amount = $type === 'fixed' ? $value : $subtotal * $value / 100;
      $rows[] = [
        'label' => (string) ($fee['label'] ?? 'Fee'),
        'quantity' => $type === 'fixed' ? 1.0 : $value,
        'unit' => $type === 'fixed' ? 'fee' : '%',
        'rate' => $type === 'fixed' ? $value : $subtotal,
        'amount' => $amount,
        'fee' => TRUE,
        'explanation' => (string) ($fee['explanation'] ?? ''),
      ];
    }
    return $rows;
  }

  private static function chargeRow(string $label, float $quantity, float $rate, string $unit): array {
    return [
      'label' => $label,
      'quantity' => $quantity,
      'unit' => $unit,
      'rate' => $rate,
      'amount' => $quantity * $rate,
      'fee' => FALSE,
    ];
  }

  /** @return \Drupal\reg_core\Entity\BillTariffScheduleInterface[] */
  private function loadEnabledSchedules(): array {
    $storage = $this->entityTypeManager->getStorage('reg_bill_tariff_schedule');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('status', TRUE)->execute();
    return $storage->loadMultiple($ids);
  }

  private function today(): string {
    return gmdate('Y-m-d', $this->time->getCurrentTime());
  }

}
