<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\reg_core\Energy\BillEstimator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds and edits effective-dated tariff schedules.
 */
final class BillTariffScheduleForm extends EntityForm {

  public function __construct(
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('datetime.time'), $container->get('current_user'));
  }

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $schedule = $this->entity;
    $form['identity'] = [
      '#type' => 'details',
      '#title' => $this->t('Schedule identity and applicability'),
      '#open' => TRUE,
    ];
    $form['identity']['label'] = [
      '#type' => 'textfield', '#title' => $this->t('Public customer-category label'),
      '#default_value' => $schedule->label(), '#required' => TRUE, '#maxlength' => 255,
    ];
    $form['identity']['id'] = [
      '#type' => 'machine_name', '#default_value' => $schedule->id(),
      '#machine_name' => ['exists' => '\Drupal\reg_core\Entity\BillTariffSchedule::load'],
      '#disabled' => !$schedule->isNew(),
    ];
    $form['identity']['category'] = [
      '#type' => 'machine_name', '#title' => $this->t('Public category ID'),
      '#default_value' => $schedule->get('category'), '#required' => TRUE,
      '#description' => $this->t('Keep this stable across historical schedules so public links and reporting remain compatible.'),
      '#machine_name' => ['exists' => [$this, 'categoryNeverExists']],
    ];
    $form['identity']['customer_group'] = [
      '#type' => 'select', '#title' => $this->t('Customer group'),
      '#options' => [
        'household_general' => $this->t('Households and general customers'),
        'sector_specific' => $this->t('Sector-specific all-energy tariffs'),
        'industrial_prepaid' => $this->t('Industrial prepaid — without smart meter'),
        'industrial_smart' => $this->t('Industrial postpaid — smart meter'),
      ],
      '#default_value' => $schedule->get('customer_group') ?: 'household_general', '#required' => TRUE,
    ];
    $form['identity']['status'] = [
      '#type' => 'checkbox', '#title' => $this->t('Enabled'),
      '#default_value' => $schedule->status(),
      '#description' => $this->t('Only enabled schedules can be used by the public estimator.'),
    ];
    $form['identity']['effective_from'] = [
      '#type' => 'date', '#title' => $this->t('Effective from'),
      '#default_value' => $schedule->get('effective_from'), '#required' => TRUE,
    ];
    $form['identity']['effective_to'] = [
      '#type' => 'date', '#title' => $this->t('Effective to'),
      '#default_value' => $schedule->get('effective_to'),
      '#description' => $this->t('Leave empty for an open-ended schedule. Close the prior schedule before enabling a replacement.'),
    ];

    $form['rates'] = ['#type' => 'details', '#title' => $this->t('Rates'), '#open' => TRUE];
    $form['rates']['tariff_type'] = [
      '#type' => 'select', '#title' => $this->t('Calculation type'),
      '#options' => ['progressive' => $this->t('Progressive bands'), 'flat' => $this->t('Flat energy rate'), 'industrial' => $this->t('Industrial energy and demand')],
      '#default_value' => $schedule->getTariffType(), '#required' => TRUE,
    ];
    $form['rates']['unit'] = [
      '#type' => 'textfield', '#title' => $this->t('Public rate unit'),
      '#default_value' => $schedule->get('unit') ?: 'RWF/kWh', '#required' => TRUE,
    ];
    $form['rates']['flat_rate'] = $this->rateElement($this->t('Flat energy rate (RWF/kWh)'), $schedule->get('flat_rate'));
    $form['rates']['energy_rate'] = $this->rateElement($this->t('Industrial energy rate (RWF/kWh)'), $schedule->get('energy_rate'));
    $form['rates']['peak_demand_rate'] = $this->rateElement($this->t('Peak demand rate (RWF/kVA/month)'), $schedule->get('peak_demand_rate'));
    $form['rates']['off_peak_demand_rate'] = $this->rateElement($this->t('Off-peak demand rate (RWF/kVA/month)'), $schedule->get('off_peak_demand_rate'));
    $form['rates']['shoulder_demand_rate'] = $this->rateElement($this->t('Shoulder demand rate (RWF/kVA/month)'), $schedule->get('shoulder_demand_rate'));

    $form['bands'] = [
      '#type' => 'table', '#title' => $this->t('Progressive tariff bands'),
      '#header' => [$this->t('Enabled'), $this->t('Public label'), $this->t('Minimum kWh'), $this->t('Maximum kWh'), $this->t('Rate RWF/kWh'), $this->t('Weight')],
      '#description' => $this->t('Maximum is exclusive. Leave maximum empty only on the final open-ended band.'),
    ];
    $bands = $schedule->get('bands') ?: [];
    $rowCount = max(6, count($bands) + 1);
    for ($delta = 0; $delta < $rowCount; $delta++) {
      $band = $bands[$delta] ?? [];
      $form['bands'][$delta]['enabled'] = ['#type' => 'checkbox', '#default_value' => $band['enabled'] ?? FALSE];
      $form['bands'][$delta]['label'] = ['#type' => 'textfield', '#default_value' => $band['label'] ?? '', '#size' => 34];
      $form['bands'][$delta]['min'] = ['#type' => 'number', '#default_value' => $band['min'] ?? 0, '#min' => 0, '#step' => '0.01'];
      $form['bands'][$delta]['max'] = ['#type' => 'number', '#default_value' => $band['max'] ?? '', '#min' => 0, '#step' => '0.01'];
      $form['bands'][$delta]['rate'] = ['#type' => 'number', '#default_value' => $band['rate'] ?? 0, '#min' => 0, '#step' => '0.01'];
      $form['bands'][$delta]['weight'] = ['#type' => 'weight', '#default_value' => $band['weight'] ?? $delta, '#delta' => 20];
    }

    $form['governance'] = ['#type' => 'details', '#title' => $this->t('Source and governance'), '#open' => TRUE];
    $form['governance']['source_title'] = ['#type' => 'textfield', '#title' => $this->t('Source title'), '#default_value' => $schedule->get('source_title'), '#required' => TRUE];
    $form['governance']['source_reference'] = ['#type' => 'textfield', '#title' => $this->t('Source reference'), '#default_value' => $schedule->get('source_reference')];
    $form['governance']['source_url'] = ['#type' => 'url', '#title' => $this->t('Source URL'), '#default_value' => $schedule->get('source_url')];
    $form['governance']['approval_date'] = ['#type' => 'date', '#title' => $this->t('Approval date'), '#default_value' => $schedule->get('approval_date')];
    $form['governance']['internal_notes'] = ['#type' => 'textarea', '#title' => $this->t('Internal notes'), '#default_value' => $schedule->get('internal_notes'), '#description' => $this->t('Not displayed publicly.')];
    if (!$schedule->isNew()) {
      $editorId = (int) $schedule->get('updated_by');
      $editor = $editorId ? $this->entityTypeManager->getStorage('user')->load($editorId) : NULL;
      $audit = ($schedule->get('updated') ?: $this->t('Unknown')) . ' — ' . ($editor ? $editor->getDisplayName() : $this->t('System migration'));
      $form['governance']['audit'] = ['#type' => 'item', '#title' => $this->t('Last saved / editor'), '#plain_text' => $audit];
    }
    return $form;
  }

  public function categoryNeverExists(): bool {
    return FALSE;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    $start = (string) $form_state->getValue('effective_from');
    $end = (string) $form_state->getValue('effective_to');
    if ($end !== '' && $end < $start) {
      $form_state->setErrorByName('effective_to', $this->t('The effective-to date must be on or after the effective-from date.'));
    }
    if ($form_state->getValue('tariff_type') === 'progressive') {
      foreach (BillEstimator::validateBands($form_state->getValue('bands') ?: []) as $error) {
        $form_state->setErrorByName('bands', $this->t('@message', ['@message' => $error]));
      }
      if (!array_filter($form_state->getValue('bands') ?: [], static fn(array $band): bool => !empty($band['enabled']))) {
        $form_state->setErrorByName('bands', $this->t('Enable at least one progressive tariff band.'));
      }
    }
    foreach (['flat_rate', 'energy_rate', 'peak_demand_rate', 'off_peak_demand_rate', 'shoulder_demand_rate'] as $field) {
      if ((float) $form_state->getValue($field) < 0) {
        $form_state->setErrorByName($field, $this->t('Rates cannot be negative.'));
      }
    }
    if (!$form_state->getValue('status') || $start === '') {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('reg_bill_tariff_schedule');
    $ids = $storage->getQuery()->accessCheck(FALSE)
      ->condition('category', $form_state->getValue('category'))
      ->condition('status', TRUE)->execute();
    foreach ($storage->loadMultiple($ids) as $other) {
      if ($other->id() !== $this->entity->id() && BillEstimator::dateRangesOverlap($start, $end, $other->get('effective_from'), $other->get('effective_to'))) {
        $form_state->setErrorByName('effective_from', $this->t('This enabled date range conflicts with schedule %schedule.', ['%schedule' => $other->label()]));
      }
    }
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $schedule = $this->entity;
    if ($schedule->isNew()) {
      $schedule->set('id', $form_state->getValue('id'));
    }
    $schedule->set('label', trim((string) $form_state->getValue('label')));
    foreach (['category', 'customer_group', 'tariff_type', 'unit', 'effective_from', 'effective_to', 'flat_rate', 'energy_rate', 'peak_demand_rate', 'off_peak_demand_rate', 'shoulder_demand_rate', 'source_title', 'source_reference', 'source_url', 'approval_date', 'internal_notes'] as $property) {
      $value = $form_state->getValue($property);
      if (in_array($property, ['flat_rate', 'energy_rate', 'peak_demand_rate', 'off_peak_demand_rate', 'shoulder_demand_rate'], TRUE)) {
        $value = (float) $value;
      }
      $schedule->set($property, $value ?? '');
    }
    $bands = array_values(array_map(static fn(array $band): array => [
      'enabled' => (bool) ($band['enabled'] ?? FALSE), 'label' => trim((string) ($band['label'] ?? '')),
      'min' => (float) ($band['min'] ?? 0), 'max' => ($band['max'] ?? '') === '' ? '' : (float) $band['max'],
      'rate' => (float) ($band['rate'] ?? 0), 'weight' => (int) ($band['weight'] ?? 0),
    ], $form_state->getValue('bands') ?: []));
    $schedule->set('bands', $bands);
    $schedule->setStatus((bool) $form_state->getValue('status'));
    $schedule->set('updated', gmdate(DATE_ATOM, $this->time->getCurrentTime()));
    $schedule->set('updated_by', (int) $this->currentUser->id());
    $status = $schedule->save();
    $this->messenger()->addStatus($this->t('Saved tariff schedule %label.', ['%label' => $schedule->label()]));
    $form_state->setRedirect('entity.reg_bill_tariff_schedule.collection');
    return $status;
  }

  private function rateElement(string $title, mixed $value): array {
    return ['#type' => 'number', '#title' => $title, '#default_value' => $value ?? 0, '#min' => 0, '#step' => '0.01'];
  }

}
