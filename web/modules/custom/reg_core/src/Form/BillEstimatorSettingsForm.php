<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Energy\BillEstimator;

/**
 * Configures global taxes, fees and public notes for the REG estimator.
 */
final class BillEstimatorSettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['reg_core.bill_estimator'];
  }

  public function getFormId(): string {
    return 'reg_core_bill_estimator_settings';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reg_core.bill_estimator');
    $form['general'] = ['#type' => 'details', '#title' => $this->t('General'), '#open' => TRUE];
    $form['general']['currency'] = [
      '#type' => 'textfield', '#title' => $this->t('Currency code'),
      '#default_value' => $config->get('currency') ?: 'RWF', '#required' => TRUE, '#maxlength' => 8,
    ];
    $form['general']['public_note'] = [
      '#type' => 'textarea', '#title' => $this->t('Public introductory note'),
      '#default_value' => $config->get('public_note'), '#rows' => 3,
    ];

    $form['schedules'] = [
      '#type' => 'details', '#title' => $this->t('Tariff schedules'), '#open' => TRUE,
      'guidance' => ['#markup' => '<p>' . $this->t('Rates are stored as dated schedules by customer category. Create a new schedule when approved rates change, and close the prior schedule to preserve history.') . '</p>'],
      'manage' => [
        '#type' => 'link', '#title' => $this->t('Manage tariff schedules'),
        '#url' => Url::fromRoute('entity.reg_bill_tariff_schedule.collection'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
    ];

    $feeLabels = [
      'vat' => $this->t('VAT'),
      'regulatory' => $this->t('Regulatory fee'),
      'other' => $this->t('Other approved fee'),
    ];
    $form['fees'] = ['#type' => 'details', '#title' => $this->t('Taxes and fees'), '#open' => TRUE, '#tree' => TRUE];
    foreach ($feeLabels as $key => $defaultLabel) {
      $fee = $config->get('fees.' . $key) ?: [];
      $form['fees'][$key] = ['#type' => 'details', '#title' => $defaultLabel, '#open' => $key === 'vat'];
      $form['fees'][$key]['enabled'] = ['#type' => 'checkbox', '#title' => $this->t('Approved and available'), '#default_value' => $fee['enabled'] ?? FALSE];
      $form['fees'][$key]['included'] = ['#type' => 'checkbox', '#title' => $this->t('Include in the public estimate total'), '#default_value' => $fee['included'] ?? FALSE];
      $form['fees'][$key]['type'] = ['#type' => 'select', '#title' => $this->t('Value type'), '#options' => ['percentage' => $this->t('Percentage of energy and demand subtotal'), 'fixed' => $this->t('Fixed amount')], '#default_value' => $fee['type'] ?? 'percentage'];
      $form['fees'][$key]['value'] = ['#type' => 'number', '#title' => $this->t('Value'), '#default_value' => $fee['value'] ?? 0, '#min' => 0, '#step' => '0.01'];
      $form['fees'][$key]['label'] = ['#type' => 'textfield', '#title' => $this->t('Public label'), '#default_value' => $fee['label'] ?? $defaultLabel, '#required' => TRUE];
      $form['fees'][$key]['explanation'] = ['#type' => 'textarea', '#title' => $this->t('Public explanation'), '#default_value' => $fee['explanation'] ?? '', '#rows' => 2];
    }

    $form['disclaimer_group'] = ['#type' => 'details', '#title' => $this->t('Disclaimer and public notes'), '#open' => TRUE];
    $form['disclaimer_group']['disclaimer'] = [
      '#type' => 'textarea', '#title' => $this->t('Public result disclaimer'),
      '#default_value' => $config->get('disclaimer'), '#required' => TRUE, '#rows' => 4,
    ];
    $form['governance'] = [
      '#type' => 'details', '#title' => $this->t('Source and governance'), '#open' => FALSE,
      'help' => ['#markup' => '<p>' . $this->t('Each schedule records its source title, reference, optional URL, approval date, internal notes, last update time and editor. Schedule edits invalidate Drupal configuration caches automatically.') . '</p>'],
    ];
    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    foreach ($form_state->getValue('fees') ?: [] as $key => $fee) {
      foreach (BillEstimator::validateFee($fee) as $error) {
        $form_state->setErrorByName('fees][' . $key, $this->t('@message', ['@message' => $error]));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fees = [];
    foreach ($form_state->getValue('fees') ?: [] as $key => $fee) {
      $fees[$key] = [
        'enabled' => (bool) ($fee['enabled'] ?? FALSE),
        'included' => (bool) ($fee['included'] ?? FALSE),
        'type' => (string) ($fee['type'] ?? 'percentage'),
        'value' => (float) ($fee['value'] ?? 0),
        'label' => trim((string) ($fee['label'] ?? '')),
        'explanation' => trim((string) ($fee['explanation'] ?? '')),
      ];
    }
    $this->config('reg_core.bill_estimator')
      ->set('currency', strtoupper(trim((string) $form_state->getValue('currency'))))
      ->set('public_note', trim((string) $form_state->getValue('public_note')))
      ->set('disclaimer', trim((string) $form_state->getValue('disclaimer')))
      ->set('fees', $fees)
      ->save();
    parent::submitForm($form, $form_state);
  }

}
