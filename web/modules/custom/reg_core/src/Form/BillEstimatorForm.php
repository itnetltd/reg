<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;
use Drupal\reg_core\Formatting\PublicNumberFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the REG electricity bill estimator using the October 2025 tariff.
 *
 * All calculations are VAT and regulatory-fee exclusive. The estimator does
 * not replace REG/EUCL billing systems or an official customer invoice.
 */
final class BillEstimatorForm extends FormBase {

  public function __construct(
    private readonly AnalyticsEventTrackerInterface $analytics,
    private readonly PublicNumberFormatter $numberFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AnalyticsEventTrackerInterface::class),
      $container->get('reg_core.public_number_formatter'),
    );
  }

  /**
   * Effective date of the approved schedule supplied for implementation.
   */
  private const EFFECTIVE_DATE = '1 October 2025';

  /**
   * Flat all-energy tariffs in RWF/kWh.
   */
  private const FLAT_RATES = [
    'telecom_towers' => 289.0,
    'hotel_under_660000' => 239.0,
    'hotel_over_660000' => 175.0,
    'health_facilities' => 214.0,
    'schools' => 214.0,
    'broadcaster_under_660000' => 276.0,
    'broadcasting_shared_660000' => 110.0,
    'commercial_data_centres' => 175.0,
    'water_pumping' => 133.0,
    'water_treatment' => 133.0,
    'public_ev_charging' => 110.0,
    'steel_mining_cement_standard' => 97.0,
  ];

  /**
   * Industrial prepaid flat tariffs in RWF/kWh.
   */
  private const INDUSTRIAL_PREPAID_RATES = [
    'industrial_prepaid_small' => 175.0,
    'industrial_prepaid_medium' => 156.0,
    'industrial_prepaid_large' => 124.0,
  ];

  /**
   * Industrial postpaid smart-meter tariff structure.
   *
   * Energy is charged in RWF/kWh. Maximum demand is charged in
   * RWF/kVA/month for the recorded period maximum.
   */
  private const INDUSTRIAL_SMART_RATES = [
    'industrial_smart_small' => [
      'energy' => 175.0,
      'peak_demand' => 11017.0,
      'off_peak_demand' => 0.0,
      'shoulder_demand' => 4008.0,
    ],
    'industrial_smart_medium' => [
      'energy' => 133.0,
      'peak_demand' => 10514.0,
      'off_peak_demand' => 0.0,
      'shoulder_demand' => 3588.0,
    ],
    'industrial_smart_large' => [
      'energy' => 110.0,
      'peak_demand' => 7184.0,
      'off_peak_demand' => 0.0,
      'shoulder_demand' => 2004.0,
    ],
    'industrial_smart_steel_mining_cement' => [
      'energy' => 97.0,
      'peak_demand' => 7184.0,
      'off_peak_demand' => 0.0,
      'shoulder_demand' => 2004.0,
    ],
  ];

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_bill_estimator_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'reg_core/portal';
    $form['#attributes']['data-reg-analytics-submit'] = 'bill_estimator_start';
    $form['#prefix'] = '<div class="reg-container reg-portal"><div class="reg-calculator reg-calculator--wide">';
    $form['#suffix'] = '</div></div>';

    $form['intro'] = [
      '#markup' => '<div class="reg-calculator__notice reg-calculator__notice--info"><strong>' . $this->t('Tariff effective @date', ['@date' => self::EFFECTIVE_DATE]) . '</strong><br>' . $this->t('All estimates are VAT and regulatory-fee exclusive. The result is indicative and is not an official invoice.') . '</div>',
    ];

    $form['customer_category'] = [
      '#type' => 'select',
      '#title' => $this->t('Customer category'),
      '#options' => $this->categoryOptions(),
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select customer category -'),
      '#default_value' => $form_state->getValue('customer_category'),
    ];

    $form['consumption'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly energy consumption (kWh)'),
      '#min' => 0,
      '#step' => '0.01',
      '#required' => TRUE,
      '#default_value' => $form_state->getValue('consumption'),
    ];

    $form['smart_meter'] = [
      '#type' => 'details',
      '#title' => $this->t('Smart-meter maximum demand — industrial postpaid only'),
      '#description' => $this->t('Complete these values only when an industrial postpaid smart-meter category is selected. Peak: 6:00 PM–10:59 PM; off-peak: 11:00 PM–7:59 AM; shoulder: 8:00 AM–5:59 PM.'),
      '#open' => FALSE,
    ];
    $form['smart_meter']['peak_demand'] = [
      '#type' => 'number',
      '#title' => $this->t('Peak maximum demand (kVA/month)'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $form_state->getValue('peak_demand') ?? 0,
    ];
    $form['smart_meter']['off_peak_demand'] = [
      '#type' => 'number',
      '#title' => $this->t('Off-peak maximum demand (kVA/month)'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $form_state->getValue('off_peak_demand') ?? 0,
      '#description' => $this->t('The supplied tariff sets the off-peak maximum-demand rate to 0 RWF/kVA/month.'),
    ];
    $form['smart_meter']['shoulder_demand'] = [
      '#type' => 'number',
      '#title' => $this->t('Shoulder maximum demand (kVA/month)'),
      '#min' => 0,
      '#step' => '0.01',
      '#default_value' => $form_state->getValue('shoulder_demand') ?? 0,
    ];

    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Calculate estimate'),
      '#button_type' => 'primary',
    ];

    if ($result = $form_state->get('result')) {
      $form['result'] = $this->buildResult($result);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $category = (string) $form_state->getValue('customer_category');
    $consumption = (float) $form_state->getValue('consumption');

    if ($consumption < 0) {
      $form_state->setErrorByName('consumption', $this->t('Consumption cannot be negative.'));
    }

    if (isset(self::INDUSTRIAL_SMART_RATES[$category])) {
      foreach (['peak_demand', 'off_peak_demand', 'shoulder_demand'] as $field_name) {
        if ((float) $form_state->getValue($field_name) < 0) {
          $form_state->setErrorByName($field_name, $this->t('Maximum demand cannot be negative.'));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $category = (string) $form_state->getValue('customer_category');
    $consumption = (float) $form_state->getValue('consumption');
    $peak_demand = (float) $form_state->getValue('peak_demand');
    $off_peak_demand = (float) $form_state->getValue('off_peak_demand');
    $shoulder_demand = (float) $form_state->getValue('shoulder_demand');

    $form_state
      ->set('result', $this->calculate(
        $category,
        $consumption,
        $peak_demand,
        $off_peak_demand,
        $shoulder_demand,
      ))
      ->setRebuild(TRUE);
    $this->analytics->record('bill_estimator_complete', [
      'dimension_type' => 'category',
      'dimension_value' => preg_replace('/[^a-z0-9_-]/', '', $category) ?: 'unknown',
    ]);
  }

  /**
   * Returns the estimator category options grouped for usability.
   */
  private function categoryOptions(): array {
    return [
      (string) $this->t('Households and general customers') => [
        'residential' => $this->t('Residential customer — progressive monthly blocks'),
        'non_residential' => $this->t('Non-residential customer — progressive monthly blocks'),
      ],
      (string) $this->t('Sector-specific all-energy tariffs') => [
        'telecom_towers' => $this->t('Telecom towers'),
        'hotel_under_660000' => $this->t('Hotel — annual consumption below 660,000 kWh'),
        'hotel_over_660000' => $this->t('Hotel — annual consumption above 660,000 kWh'),
        'health_facilities' => $this->t('Health facility'),
        'schools' => $this->t('School or higher-learning institution'),
        'broadcaster_under_660000' => $this->t('Broadcaster — annual consumption below 660,000 kWh'),
        'broadcasting_shared_660000' => $this->t('Shared broadcasting infrastructure — at least 660,000 kWh/year'),
        'commercial_data_centres' => $this->t('Commercial data centre'),
        'water_pumping' => $this->t('Water pumping station'),
        'water_treatment' => $this->t('Water treatment plant'),
        'public_ev_charging' => $this->t('Public electric charging infrastructure'),
        'steel_mining_cement_standard' => $this->t('Steel, mining or cement industry — at least 1,000,000 kWh/year, standard energy rate'),
      ],
      (string) $this->t('Industrial prepaid — without smart meter') => [
        'industrial_prepaid_small' => $this->t('Small industry — prepaid flat rate'),
        'industrial_prepaid_medium' => $this->t('Medium industry — prepaid flat rate'),
        'industrial_prepaid_large' => $this->t('Large industry — prepaid flat rate'),
      ],
      (string) $this->t('Industrial postpaid — smart meter') => [
        'industrial_smart_small' => $this->t('Small industry — smart meter'),
        'industrial_smart_medium' => $this->t('Medium industry — smart meter'),
        'industrial_smart_large' => $this->t('Large industry — smart meter'),
        'industrial_smart_steel_mining_cement' => $this->t('Steel, mining or cement — at least 1,000,000 kWh/year, smart meter'),
      ],
    ];
  }

  /**
   * Calculates an indicative tariff-exclusive bill.
   */
  private function calculate(
    string $category,
    float $consumption,
    float $peak_demand,
    float $off_peak_demand,
    float $shoulder_demand,
  ): array {
    $rows = [];
    $total = 0.0;

    if ($category === 'residential') {
      $first = min($consumption, 20.0);
      $second = min(max($consumption - 20.0, 0.0), 30.0);
      $third = max($consumption - 50.0, 0.0);
      $rows = array_filter([
        $this->chargeRow($this->t('Residential block: 0–20 kWh'), $first, 89.0, 'kWh'),
        $this->chargeRow($this->t('Residential block: above 20–50 kWh'), $second, 310.0, 'kWh'),
        $this->chargeRow($this->t('Residential block: above 50 kWh'), $third, 369.0, 'kWh'),
      ], static fn(array $row): bool => $row['quantity'] > 0);
      $total = array_sum(array_column($rows, 'amount'));
    }
    elseif ($category === 'non_residential') {
      $first = min($consumption, 100.0);
      $second = max($consumption - 100.0, 0.0);
      $rows = array_filter([
        $this->chargeRow($this->t('Non-residential block: 0–100 kWh'), $first, 355.0, 'kWh'),
        $this->chargeRow($this->t('Non-residential block: above 100 kWh'), $second, 376.0, 'kWh'),
      ], static fn(array $row): bool => $row['quantity'] > 0);
      $total = array_sum(array_column($rows, 'amount'));
    }
    elseif (isset(self::FLAT_RATES[$category])) {
      $rate = self::FLAT_RATES[$category];
      $rows[] = $this->chargeRow($this->t('Energy charge'), $consumption, $rate, 'kWh');
      $total = $consumption * $rate;
    }
    elseif (isset(self::INDUSTRIAL_PREPAID_RATES[$category])) {
      $rate = self::INDUSTRIAL_PREPAID_RATES[$category];
      $rows[] = $this->chargeRow($this->t('Prepaid flat energy charge'), $consumption, $rate, 'kWh');
      $total = $consumption * $rate;
    }
    elseif (isset(self::INDUSTRIAL_SMART_RATES[$category])) {
      $rates = self::INDUSTRIAL_SMART_RATES[$category];
      $rows = [
        $this->chargeRow($this->t('Energy charge'), $consumption, $rates['energy'], 'kWh'),
        $this->chargeRow($this->t('Peak maximum-demand charge'), $peak_demand, $rates['peak_demand'], 'kVA'),
        $this->chargeRow($this->t('Off-peak maximum-demand charge'), $off_peak_demand, $rates['off_peak_demand'], 'kVA'),
        $this->chargeRow($this->t('Shoulder maximum-demand charge'), $shoulder_demand, $rates['shoulder_demand'], 'kVA'),
      ];
      $total = array_sum(array_column($rows, 'amount'));
    }

    return [
      'category' => $this->categoryLabel($category),
      'consumption' => $consumption,
      'rows' => $rows,
      'total' => $total,
      'effective_date' => self::EFFECTIVE_DATE,
    ];
  }

  /**
   * Builds one calculation-breakdown row.
   */
  private function chargeRow(string $label, float $quantity, float $rate, string $unit): array {
    return [
      'label' => $label,
      'quantity' => $quantity,
      'unit' => $unit,
      'rate' => $rate,
      'amount' => $quantity * $rate,
    ];
  }

  /**
   * Finds the selected option label.
   */
  private function categoryLabel(string $category): string {
    foreach ($this->categoryOptions() as $options) {
      if (isset($options[$category])) {
        return (string) $options[$category];
      }
    }
    return $category;
  }

  /**
   * Builds a render array for the estimate and calculation breakdown.
   */
  private function buildResult(array $result): array {
    $header = [
      $this->t('Charge'),
      $this->t('Quantity'),
      $this->t('Rate'),
      $this->t('Amount'),
    ];
    $rows = [];
    foreach ($result['rows'] as $row) {
      $rows[] = [
        $row['label'],
        $this->numberFormatter->decimal($row['quantity']) . ' ' . $row['unit'],
        $this->numberFormatter->decimal($row['rate']) . ' RWF/' . $row['unit'],
        $this->numberFormatter->decimal($row['amount']) . ' RWF',
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['reg-calculator__result']],
      'heading' => [
        '#markup' => '<h2>' . $this->t('Indicative estimate') . '</h2><p><strong>' . $result['category'] . '</strong></p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#attributes' => ['class' => ['reg-calculator__table']],
        '#responsive' => TRUE,
      ],
      'total' => [
        '#markup' => '<p class="reg-calculator__total"><span>' . $this->t('Estimated energy and demand charges') . '</span><strong>' . $this->numberFormatter->decimal($result['total']) . ' RWF</strong></p>',
      ],
      'disclaimer' => [
        '#markup' => '<p class="reg-calculator__disclaimer">' . $this->t('Tariff effective @date. VAT and regulatory fees are not included because approved percentages were not supplied with this schedule. This estimate is for guidance only and is not an official REG/EUCL invoice.', ['@date' => $result['effective_date']]) . '</p>',
      ],
    ];
  }

}
