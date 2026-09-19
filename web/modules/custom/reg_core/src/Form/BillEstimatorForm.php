<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;
use Drupal\reg_core\Energy\BillEstimatorInterface;
use Drupal\reg_core\Exception\BillEstimatorUnavailableException;
use Drupal\reg_core\Formatting\PublicNumberFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides the REG electricity bill estimator from approved CMS schedules.
 */
final class BillEstimatorForm extends FormBase {

  public function __construct(
    protected AnalyticsEventTrackerInterface $analytics,
    protected PublicNumberFormatter $numberFormatter,
    protected BillEstimatorInterface $estimator,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AnalyticsEventTrackerInterface::class),
      $container->get('reg_core.public_number_formatter'),
      $container->get(BillEstimatorInterface::class),
    );
  }

  public function getFormId(): string {
    return 'reg_bill_estimator_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'reg_core/portal';
    $form['#attributes']['data-reg-analytics-submit'] = 'bill_estimator_start';
    $form['#prefix'] = '<div class="reg-container reg-portal"><div class="reg-calculator reg-calculator--wide">';
    $form['#suffix'] = '</div></div>';

    $settings = $this->estimator->getPublicSettings();
    $categoryOptions = $this->estimator->getCategoryOptions();
    if ($categoryOptions === []) {
      $form['unavailable'] = [
        '#markup' => '<div class="reg-calculator__notice reg-calculator__notice--info"><strong>' . $this->t('Bill estimator temporarily unavailable') . '</strong><br>' . $this->t('No approved tariff schedule is currently available. Please try again later or contact REG on 2727.') . '</div>',
      ];
      return $form;
    }

    $publicNote = trim((string) ($settings['public_note'] ?? ''));
    $form['intro'] = [
      '#markup' => '<div class="reg-calculator__notice reg-calculator__notice--info"><strong>' . $this->t('Estimate with the currently effective approved tariff schedule') . '</strong>' . ($publicNote !== '' ? '<br>' . Html::escape($publicNote) : '') . '</div>',
    ];
    $form['customer_category'] = [
      '#type' => 'select', '#title' => $this->t('Customer category'),
      '#options' => $categoryOptions, '#required' => TRUE,
      '#empty_option' => $this->t('- Select customer category -'),
      '#default_value' => $form_state->getValue('customer_category'),
    ];
    $form['consumption'] = [
      '#type' => 'number', '#title' => $this->t('Monthly energy consumption (kWh)'),
      '#min' => 0, '#step' => '0.01', '#required' => TRUE,
      '#default_value' => $form_state->getValue('consumption'),
    ];
    $form['smart_meter'] = [
      '#type' => 'details', '#title' => $this->t('Smart-meter maximum demand — industrial postpaid only'),
      '#description' => $this->t('Complete these values only when an industrial postpaid smart-meter category is selected. Peak: 6:00 PM–10:59 PM; off-peak: 11:00 PM–7:59 AM; shoulder: 8:00 AM–5:59 PM.'),
      '#open' => FALSE,
    ];
    foreach ([
      'peak_demand' => $this->t('Peak maximum demand (kVA/month)'),
      'off_peak_demand' => $this->t('Off-peak maximum demand (kVA/month)'),
      'shoulder_demand' => $this->t('Shoulder maximum demand (kVA/month)'),
    ] as $field => $label) {
      $form['smart_meter'][$field] = [
        '#type' => 'number', '#title' => $label, '#min' => 0, '#step' => '0.01',
        '#default_value' => $form_state->getValue($field) ?? 0,
      ];
    }
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Calculate estimate'), '#button_type' => 'primary'];
    if ($result = $form_state->get('result')) {
      $form['result'] = $this->buildResult($result, (string) ($settings['currency'] ?? 'RWF'));
    }
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    // A rebuilt form may still carry the prior estimate in private form state.
    // Clear it before validating so invalid inputs never display a stale total.
    $form_state->set('result', NULL);
    unset($form['result']);
    $category = (string) $form_state->getValue('customer_category');
    if ((float) $form_state->getValue('consumption') < 0) {
      $form_state->setErrorByName('consumption', $this->t('Consumption cannot be negative.'));
    }
    try {
      $schedule = $this->estimator->getActiveSchedule($category);
      if (($schedule['tariff_type'] ?? '') === 'industrial') {
        foreach (['peak_demand', 'off_peak_demand', 'shoulder_demand'] as $field) {
          if ((float) $form_state->getValue($field) < 0) {
            $form_state->setErrorByName($field, $this->t('Maximum demand cannot be negative.'));
          }
        }
      }
    }
    catch (BillEstimatorUnavailableException) {
      $form_state->setErrorByName('customer_category', $this->t('The selected tariff schedule is temporarily unavailable. Please choose another category or try again later.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $category = (string) $form_state->getValue('customer_category');
    try {
      $result = $this->estimator->calculate($category, (float) $form_state->getValue('consumption'), [
        'peak' => (float) $form_state->getValue('peak_demand'),
        'off_peak' => (float) $form_state->getValue('off_peak_demand'),
        'shoulder' => (float) $form_state->getValue('shoulder_demand'),
      ]);
    }
    catch (BillEstimatorUnavailableException) {
      $this->messenger()->addError($this->t('The bill estimator is temporarily unavailable because no single approved schedule applies.'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $form_state->set('result', $result)->setRebuild(TRUE);
    $this->analytics->record('bill_estimator_complete', [
      'dimension_type' => 'category',
      'dimension_value' => preg_replace('/[^a-z0-9_-]/', '', $category) ?: 'unknown',
    ]);
  }

  private function buildResult(array $result, string $currency): array {
    $rows = [];
    $feeExplanations = [];
    foreach ($result['rows'] as $row) {
      if (!empty($row['fee'])) {
        $quantity = $row['unit'] === '%' ? $this->numberFormatter->decimal($row['quantity']) . '%' : $this->t('Fixed fee');
        $rate = $row['unit'] === '%' ? $this->t('of subtotal') : $this->numberFormatter->decimal($row['rate']) . ' ' . $currency;
        if (($row['explanation'] ?? '') !== '') {
          $feeExplanations[] = $row['label'] . ': ' . $row['explanation'];
        }
      }
      else {
        $quantity = $this->numberFormatter->decimal($row['quantity']) . ' ' . $row['unit'];
        $rate = $this->numberFormatter->decimal($row['rate']) . ' ' . $currency . '/' . $row['unit'];
      }
      $rows[] = [$row['label'], $quantity, $rate, $this->numberFormatter->decimal($row['amount']) . ' ' . $currency];
    }

    $source = [];
    if (($result['source_title'] ?? '') !== '') {
      $source['label'] = ['#markup' => '<strong>' . $this->t('Tariff source:') . '</strong> '];
      $source['title'] = ($result['source_url'] ?? '') !== ''
        ? Link::fromTextAndUrl($result['source_title'], Url::fromUri($result['source_url'], ['attributes' => ['rel' => 'noopener']]))->toRenderable()
        : ['#plain_text' => $result['source_title']];
      if (($result['source_reference'] ?? '') !== '') {
        $source['reference'] = ['#plain_text' => ' (' . $result['source_reference'] . ')'];
      }
    }

    return [
      '#type' => 'container', '#attributes' => ['class' => ['reg-calculator__result']],
      'heading' => ['#markup' => '<h2>' . $this->t('Indicative estimate') . '</h2><p><strong>' . Html::escape($result['category']) . '</strong></p>'],
      'table' => [
        '#type' => 'table', '#header' => [$this->t('Charge'), $this->t('Quantity'), $this->t('Rate'), $this->t('Amount')],
        '#rows' => $rows, '#attributes' => ['class' => ['reg-calculator__table']], '#responsive' => TRUE,
      ],
      'fee_explanations' => $feeExplanations ? ['#theme' => 'item_list', '#items' => $feeExplanations, '#attributes' => ['class' => ['reg-calculator__fee-notes']]] : [],
      'subtotal' => ['#markup' => '<p><strong>' . $this->t('Energy and demand subtotal:') . '</strong> ' . $this->numberFormatter->decimal($result['subtotal']) . ' ' . Html::escape($currency) . '</p>'],
      'total' => ['#markup' => '<p class="reg-calculator__total"><span>' . $this->t('Estimated total') . '</span><strong>' . $this->numberFormatter->decimal($result['total']) . ' ' . Html::escape($currency) . '</strong></p>'],
      'effective' => ['#markup' => '<p><strong>' . $this->t('Tariff effective:') . '</strong> ' . Html::escape($result['effective_date']) . '</p>'],
      'source' => $source,
      'disclaimer' => ['#markup' => '<p class="reg-calculator__disclaimer">' . Html::escape((string) ($result['disclaimer'] ?? '')) . '</p>'],
    ];
  }

}
