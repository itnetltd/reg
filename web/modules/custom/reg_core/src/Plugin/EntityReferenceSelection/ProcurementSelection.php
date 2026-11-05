<?php
namespace Drupal\reg_core\Plugin\EntityReferenceSelection;

use Drupal\Core\Entity\Attribute\EntityReferenceSelection;
use Drupal\Core\Entity\Plugin\EntityReferenceSelection\DefaultSelection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/** Allows authorized officers to reference editable draft plans and tenders. */
#[EntityReferenceSelection(id: 'reg_procurement', label: new TranslatableMarkup('REG procurement records, including editable drafts'), entity_types: ['node'], group: 'reg_procurement', weight: 0)]
final class ProcurementSelection extends DefaultSelection {
  protected function buildEntityQuery($match = NULL, $match_operator = 'CONTAINS') {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(FALSE);
    $bundles = array_intersect(array_values($this->configuration['target_bundles'] ?? []), ['reg_procurement_plan', 'reg_procurement_plan_item', 'reg_tender']);
    if (!$bundles || !$this->currentUser->hasPermission('administer reg procurement plans')) return $query->condition('nid', NULL);
    $query->condition('type', $bundles, 'IN');
    if ($match !== NULL) $query->condition('title', $match, $match_operator);
    $allowed = [];
    foreach ($storage->loadMultiple($query->execute()) as $node) {
      if ($node->access('update', $this->currentUser)) $allowed[] = $node->id();
    }
    return $query->condition('nid', $allowed ?: [0], 'IN')->sort('title');
  }
  public function createNewEntity($entity_type_id, $bundle, $label, $uid) {
    throw new \LogicException('Create procurement content through its explicit Drupal Add form.');
  }
}
