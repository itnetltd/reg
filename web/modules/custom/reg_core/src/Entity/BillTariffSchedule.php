<?php

namespace Drupal\reg_core\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\Attribute\ConfigEntityType;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\reg_core\BillTariffScheduleListBuilder;
use Drupal\reg_core\Form\BillTariffScheduleDeleteForm;
use Drupal\reg_core\Form\BillTariffScheduleForm;

/**
 * Stores one category's approved rates for an effective date range.
 */
#[ConfigEntityType(
  id: 'reg_bill_tariff_schedule',
  label: new TranslatableMarkup('REG bill tariff schedule'),
  label_collection: new TranslatableMarkup('REG bill tariff schedules'),
  label_singular: new TranslatableMarkup('REG bill tariff schedule'),
  label_plural: new TranslatableMarkup('REG bill tariff schedules'),
  handlers: [
    'list_builder' => BillTariffScheduleListBuilder::class,
    'form' => [
      'add' => BillTariffScheduleForm::class,
      'edit' => BillTariffScheduleForm::class,
      'delete' => BillTariffScheduleDeleteForm::class,
    ],
  ],
  admin_permission: 'administer REG bill estimator',
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'status' => 'status',
  ],
  links: [
    'collection' => '/admin/config/reg/bill-estimator/schedules',
    'add-form' => '/admin/config/reg/bill-estimator/schedules/add',
    'edit-form' => '/admin/config/reg/bill-estimator/schedules/{reg_bill_tariff_schedule}',
    'delete-form' => '/admin/config/reg/bill-estimator/schedules/{reg_bill_tariff_schedule}/delete',
  ],
  config_prefix: 'bill_tariff_schedule',
  config_export: [
    'id', 'label', 'status', 'category', 'customer_group', 'tariff_type',
    'unit', 'effective_from', 'effective_to', 'bands', 'flat_rate',
    'energy_rate', 'peak_demand_rate', 'off_peak_demand_rate',
    'shoulder_demand_rate', 'source_title', 'source_reference',
    'source_url', 'approval_date', 'internal_notes', 'updated', 'updated_by',
  ],
)]
final class BillTariffSchedule extends ConfigEntityBase implements BillTariffScheduleInterface {

  protected string $label;
  protected string $category = '';
  protected string $customer_group = 'household_general';
  protected string $tariff_type = 'flat';
  protected string $unit = 'RWF/kWh';
  protected string $effective_from = '';
  protected string $effective_to = '';
  protected array $bands = [];
  protected float $flat_rate = 0.0;
  protected float $energy_rate = 0.0;
  protected float $peak_demand_rate = 0.0;
  protected float $off_peak_demand_rate = 0.0;
  protected float $shoulder_demand_rate = 0.0;
  protected string $source_title = '';
  protected string $source_reference = '';
  protected string $source_url = '';
  protected string $approval_date = '';
  protected string $internal_notes = '';
  protected string $updated = '';
  protected int $updated_by = 0;

  public function getCategory(): string {
    return $this->category;
  }

  public function getTariffType(): string {
    return $this->tariff_type;
  }

  public function appliesOn(string $date): bool {
    return $this->status()
      && $this->effective_from !== ''
      && $this->effective_from <= $date
      && ($this->effective_to === '' || $this->effective_to >= $date);
  }

  public function toCalculationArray(): array {
    return [
      'id' => $this->id(),
      'label' => $this->label(),
      'category' => $this->category,
      'customer_group' => $this->customer_group,
      'tariff_type' => $this->tariff_type,
      'unit' => $this->unit,
      'effective_from' => $this->effective_from,
      'effective_to' => $this->effective_to,
      'bands' => $this->bands,
      'flat_rate' => $this->flat_rate,
      'energy_rate' => $this->energy_rate,
      'peak_demand_rate' => $this->peak_demand_rate,
      'off_peak_demand_rate' => $this->off_peak_demand_rate,
      'shoulder_demand_rate' => $this->shoulder_demand_rate,
      'source_title' => $this->source_title,
      'source_reference' => $this->source_reference,
      'source_url' => $this->source_url,
      'approval_date' => $this->approval_date,
      'updated' => $this->updated,
      'updated_by' => $this->updated_by,
    ];
  }

}
