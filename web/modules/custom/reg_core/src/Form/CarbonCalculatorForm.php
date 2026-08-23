<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\reg_core\Formatting\PublicNumberFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an indicative electricity carbon-footprint calculator.
 */
final class CarbonCalculatorForm extends FormBase {

  public function __construct(
    private readonly PublicNumberFormatter $numberFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('reg_core.public_number_formatter'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_carbon_calculator_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $factor = (float) $this->config('reg_core.settings')->get('carbon.emission_factor');
    $form['#attached']['library'][] = 'reg_core/portal';
    $form['#prefix'] = '<div class="reg-container reg-portal"><div class="reg-calculator">';
    $form['#suffix'] = '</div></div>';

    if ($factor <= 0) {
      $form['notice'] = [
        '#markup' => '<div class="reg-calculator__notice">' . $this->t('The calculator is installed but the REG-approved electricity emission factor has not yet been configured.') . '</div>',
      ];
    }

    $form['consumption'] = [
      '#type' => 'number',
      '#title' => $this->t('Monthly electricity consumption (kWh)'),
      '#min' => 0,
      '#step' => '0.01',
      '#required' => TRUE,
    ];
    $form['actions']['#type'] = 'actions';
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Estimate carbon footprint'),
      '#button_type' => 'primary',
      '#disabled' => $factor <= 0,
    ];

    if ($result = $form_state->get('result')) {
      $form['result'] = [
        '#markup' => '<div class="reg-calculator__result"><strong>' . $this->t('Indicative footprint: @amount kg CO₂e', ['@amount' => $this->numberFormatter->decimal($result['emissions'])]) . '</strong><p>' . $this->t('@kwh kWh × @factor kg CO₂e/kWh.', ['@kwh' => $this->numberFormatter->decimal($result['consumption']), '@factor' => $this->numberFormatter->decimal($result['factor'], 4)]) . '</p><small>' . $this->t('This awareness estimate depends on the approved factor and does not constitute a verified emissions inventory.') . '</small></div>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $consumption = (float) $form_state->getValue('consumption');
    $factor = (float) $this->config('reg_core.settings')->get('carbon.emission_factor');
    $form_state->set('result', [
      'emissions' => $consumption * $factor,
      'consumption' => $consumption,
      'factor' => $factor,
    ])->setRebuild(TRUE);
  }

}
