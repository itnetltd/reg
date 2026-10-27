<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Energy\EnergyToolStatusResolver;
use Drupal\reg_core\Formatting\PublicNumberFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an indicative electricity carbon-footprint calculator.
 */
final class CarbonCalculatorForm extends FormBase {

  private const MAX_CONSUMPTION = 1000000;

  public function __construct(
    private readonly PublicNumberFormatter $numberFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('reg_core.public_number_formatter'));
  }

  public function getFormId(): string {
    return 'reg_carbon_calculator_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reg_core.settings');
    $carbon = (array) $config->get('carbon');
    $factor = (float) ($carbon['emission_factor'] ?? 0);
    $available = EnergyToolStatusResolver::isCarbonFactorUsable($carbon);
    $form['#attached']['library'][] = 'reg_core/portal';
    $form['#prefix'] = '<div class="reg-container reg-portal"><div class="reg-calculator">';
    $form['#suffix'] = '</div></div>';
    $form['#cache']['tags'] = $config->getCacheTags();
    $form['#cache']['contexts'] = ['languages:language_interface'];
    // Results and submitted consumption must never enter shared render caches.
    $form['#cache']['max-age'] = 0;

    if (!$available) {
      $form['notice'] = [
        '#markup' => '<div class="reg-calculator__notice">' . $this->t('Awaiting factor. A usable published electricity emission factor is not currently available.') . '</div>',
      ];
    }
    if (is_finite($factor) && $factor > 0) {
      $form['factor'] = [
        '#markup' => '<p>' . $this->t('Electricity emission factor: @factor @unit (data year: @year).', [
          '@factor' => $this->numberFormatter->decimal($factor, 5),
          '@unit' => $carbon['unit'] ?? '',
          '@year' => $carbon['data_year'] ?? '',
        ]) . '</p><p>' . $this->t('Methodology: @method', ['@method' => $carbon['methodology_note'] ?? '']) . '</p>',
      ];
    }
    $source = (string) ($carbon['source_url'] ?? '');
    if (\Drupal\Component\Utility\UrlHelper::isValid($source, TRUE)
      && in_array(strtolower((string) parse_url($source, PHP_URL_SCHEME)), ['http', 'https'], TRUE)) {
      $form['source'] = [
        '#type' => 'link',
        '#title' => $this->t('Source and methodology: @source', ['@source' => $carbon['source_title'] ?? '']),
        '#url' => Url::fromUri($source),
      ];
    }
    $form['consumption'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly electricity consumption (kWh)'),
      '#min' => 0,
      '#max' => self::MAX_CONSUMPTION,
      '#step' => '0.01',
      '#required' => TRUE,
      '#description' => $this->t('Enter a value from 0 to 1,000,000 kWh.'),
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Estimate carbon footprint'),
      '#button_type' => 'primary',
      '#disabled' => !$available,
    ];

    $result = $form_state->get('result');
    if ($available && $result && $result['factor'] === $factor && !$form_state->hasAnyErrors()) {
      $form['result'] = [
        '#markup' => '<div class="reg-calculator__result" role="status"><strong>' . $this->t('Estimated emissions: @amount kg CO2e', [
          '@amount' => $this->numberFormatter->decimal($result['emissions'], 5),
        ]) . '</strong><p>' . $this->t('@kwh kWh × @factor kg CO2e/kWh.', [
          '@kwh' => $this->numberFormatter->decimal($result['consumption']),
          '@factor' => $this->numberFormatter->decimal($result['factor'], 5),
        ]) . '</p>' . ($result['emissions'] >= 1000 ? '<p>' . $this->t('Equivalent to @tonnes tonnes CO2e.', [
          '@tonnes' => $this->numberFormatter->decimal($result['emissions_tonnes'], 5),
        ]) . '</p>' : '') . '</div>',
      ];
    }
    $form['disclaimer'] = [
      '#markup' => '<p class="reg-calculator__notice">' . $this->t("Indicative estimate based on Rwanda's published @year lifecycle electricity generation intensity. This is not a verified carbon inventory.", [
        '@year' => $carbon['data_year'] ?? 2024,
      ]) . '</p>',
    ];
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $form_state->set('result', NULL);
    unset($form['result']);
    if (!$this->validConsumption($form_state->getValue('consumption'))) {
      $form_state->setErrorByName('consumption', $this->t('Enter a numeric consumption from 0 to 1,000,000 kWh, with at most two decimal places.'));
    }
    if (!EnergyToolStatusResolver::isCarbonFactorUsable((array) $this->config('reg_core.settings')->get('carbon'))) {
      $form_state->setErrorByName('consumption', $this->t('Awaiting factor. Estimates are currently unavailable.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->set('result', NULL);
    $carbon = (array) $this->config('reg_core.settings')->get('carbon');
    $value = $form_state->getValue('consumption');
    if ($form_state->hasAnyErrors() || !$this->validConsumption($value)
      || !EnergyToolStatusResolver::isCarbonFactorUsable($carbon)) {
      $form_state->setRebuild(TRUE);
      return;
    }
    $consumption = (float) $value;
    $factor = (float) $carbon['emission_factor'];
    $emissions = $consumption * $factor;
    $form_state->set('result', [
      'emissions' => $emissions,
      'emissions_tonnes' => $emissions / 1000,
      'consumption' => $consumption,
      'factor' => $factor,
    ])->setRebuild(TRUE);
  }

  private function validConsumption(mixed $value): bool {
    if (!is_scalar($value) || !is_numeric($value)) {
      return FALSE;
    }
    $number = (float) $value;
    return is_finite($number) && $number >= 0 && $number <= self::MAX_CONSUMPTION
      && abs($number * 100 - round($number * 100)) < 0.000001;
  }

}
