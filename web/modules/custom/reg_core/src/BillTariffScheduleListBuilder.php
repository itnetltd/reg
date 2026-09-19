<?php

namespace Drupal\reg_core;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Lists dated bill-estimator tariff schedules for administrators.
 */
final class BillTariffScheduleListBuilder extends ConfigEntityListBuilder {

  public function buildHeader(): array {
    return [
      'label' => $this->t('Schedule'),
      'category' => $this->t('Category ID'),
      'type' => $this->t('Type'),
      'effective' => $this->t('Effective dates'),
      'status' => $this->t('Status'),
    ] + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    $row['label'] = $entity->toLink(NULL, 'edit-form');
    $row['category'] = $entity->get('category');
    $row['type'] = ucfirst((string) $entity->get('tariff_type'));
    $row['effective'] = $entity->get('effective_from') . ' — ' . ($entity->get('effective_to') ?: $this->t('Open ended'));
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

}
