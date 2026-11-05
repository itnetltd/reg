<?php

namespace Drupal\reg_core\Procurement;

use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Transactional, permission-checked reconciliation of existing planning nodes. */
final class ProcurementImporter {

  public const PLAN_KEY = 'EUCL-FY2025-2026';
  public const PLAN_TITLE = 'EUCL Procurement Plan 2025/2026';

  /**
   * Requires explicit import and procurement administration permissions.
   */
  private function authorize(): void {
    if (!\Drupal::currentUser()->hasPermission('import reg procurement plans') || !\Drupal::currentUser()->hasPermission('administer reg procurement plans')) {
      throw new \RuntimeException('Procurement import permission required.');
    }
  }

  /**
   * Finds a unique source plan, or safely adopts the matching existing plan. */
  public function plan(): ?NodeInterface {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan')->condition('field_reg_source_id', self::PLAN_KEY)->execute();
    if (!$ids) {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan')->condition('title', self::PLAN_TITLE)->condition('field_reg_financial_year', '2025/2026')->condition('field_reg_entity', 'eucl')->execute();
    }
    if (count($ids) > 1) {
      throw new \RuntimeException('Multiple matching plans; resolve before import.');
    }
    $plan = $ids ? $storage->load(reset($ids)) : NULL;
    if ($plan) {
      $this->editable($plan);
    }
    elseif (!\Drupal::entityTypeManager()->getAccessControlHandler('node')->createAccess('reg_procurement_plan')) {
      throw new \RuntimeException('Cannot create procurement plan.');
    }
    if ($plan && (!$plan->get('field_reg_source_id')->isEmpty() && $plan->get('field_reg_source_id')->value !== self::PLAN_KEY || $plan->get('field_reg_entity')->value !== 'eucl' || $plan->get('field_reg_financial_year')->value !== '2025/2026')) {
      throw new \RuntimeException('Plan identity mismatch.');
    }
    return $plan;
  }

  /**
   * Rejects inaccessible, published, approved or pending-revision records.
   */
  private function editable(NodeInterface $node): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    if (!$node->access('update') || $node->isPublished() || (int) $storage->getLatestRevisionId($node->id()) !== (int) $node->getRevisionId()) {
      throw new \RuntimeException('Published, inaccessible or pending-revision record requires manual reconciliation.');
    }
    if ($node->hasField('field_reg_proc_plan_status') && !in_array($node->get('field_reg_proc_plan_status')->value, [
      'draft',
      'under_review',
    ], TRUE)) {
      throw new \RuntimeException('Approved/archived plans cannot be overwritten by import.');
    }
  }

  /**
   * Captures revision state to reject stale previews, including new duplicates. */
  private function fingerprint(?NodeInterface $plan): string {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $versions = $plan ? ['plan' => [$plan->id(), $storage->getLatestRevisionId($plan->id())]] : [];
    if ($plan) {
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan_item')->condition('field_reg_procurement_plan_ref', $plan->id())->sort('nid')->execute();
      foreach ($ids as $id) {
        $versions[$id] = $storage->getLatestRevisionId($id);
      }
    }
    return hash('sha256', json_encode($versions, JSON_THROW_ON_ERROR));
  }

  /**
   * No entity saves occur here; errors block only the affected rows. */
  public function preview(array $rows): array {
    $this->authorize();
    $plan = $this->plan();
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    foreach ($rows as &$row) {
      $row['action'] = 'insert';
      $row['nid'] = NULL;
      $row['values'] = $row['data'];
      $ids = $plan && $row['data']['import_key'] ? $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_procurement_plan_item')->condition('field_reg_procurement_plan_ref', $plan->id())->condition('field_reg_source_id', $row['data']['import_key'])->execute() : [];
      if (count($ids) > 1) {
        $row['errors'][] = 'Duplicate stored import key; resolve manually.';
      }
      elseif ($ids) {
        $node = $storage->load(reset($ids));
        $row['nid'] = $node->id();
        try {
          $this->editable($node);
        }
        catch (\RuntimeException $e) {
          $row['errors'][] = $e->getMessage();
        }
        $baseline = json_decode($node->get('field_reg_import_state')->value ?? '', TRUE)['baseline'] ?? NULL;
        if (!is_array($baseline)) {
          $row['errors'][] = 'Existing item has no importer baseline; review and reconcile manually.';
        }
        $changed = FALSE;
        foreach (ProcurementCsv::FIELDS as $key => $field) {
          $current = $node->get($field)->isEmpty() ? NULL : (string) $node->get($field)->value;
          $incoming = $row['data'][$key];
          $old = $baseline[$key] ?? NULL;
          // Compare decimal scale without float conversion.
          $decimal = static function ($value) {
            $parts = explode('.', (string) $value, 2);
            return ltrim($parts[0], '0') . '.' . str_pad(rtrim($parts[1] ?? '', '0'), 2, '0');
          };
          $eq = static fn($a, $b) => in_array($key, [
            'estimated_budget',
            'fy_budget',
          ], TRUE) && $a !== NULL && $b !== NULL ? $decimal($a) === $decimal($b) : $a === $b;
          if (!$eq($current, $old) && !$eq($incoming, $old) && !$eq($incoming, $current)) {
            $row['errors'][] = 'Editor/source conflict: ' . $key;
          }
          if (!$eq($current, $old) && $eq($incoming, $old)) {
            $row['values'][$key] = $current;
            $row['warnings'][] = 'Preserved editor change: ' . $key;
          }
          if (!$eq($row['values'][$key], $current) || $incoming !== $old) {
            $changed = TRUE;
          }
        }
        $changed = $changed || ($baseline['source_metadata'] ?? []) !== ($row['data']['source_metadata'] ?? []);
        $row['action'] = $changed ? 'update' : 'skip';
      }
      elseif (!\Drupal::entityTypeManager()->getAccessControlHandler('node')->createAccess('reg_procurement_plan_item')) {
        $row['errors'][] = 'Cannot create procurement plan items.';
      }
      if ($row['errors']) {
        $row['action'] = 'error';
      }
    }
    return ['fingerprint' => $this->fingerprint($plan), 'rows' => $rows];
  }

  /**
   * Commits explicitly selected, error-free rows atomically under a plan lock. */
  public function commit(array $preview, array $selected, FileInterface $csv, ?FileInterface $workbook, string $expected_hash, ?string $workbook_hash): array {
    $this->authorize();
    foreach (array_filter([$csv, $workbook]) as $source) {
      if (!str_starts_with($source->getFileUri(), 'private://procurement-plans/import/')) {
        throw new \RuntimeException('Import sources must be private uploads.');
      }
    }
    if (hash_file('sha256', $csv->getFileUri()) !== $expected_hash || ($workbook && hash_file('sha256', $workbook->getFileUri()) !== $workbook_hash)) {
      throw new \RuntimeException('Source changed after preview; preview again.');
    }
    $lock = \Drupal::lock();
    if (!$lock->acquire('reg_procurement_import:' . self::PLAN_KEY, 300)) {
      throw new \RuntimeException('Another import is running; retry later.');
    }
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $transaction = \Drupal::database()->startTransaction();
    try {
      \Drupal::entityTypeManager()->getStorage('node')->resetCache();
      $fresh = $this->preview(array_map(static fn($row) => array_intersect_key($row, array_flip([
        'record',
        'data',
        'errors',
        'warnings',
      ])), $preview['rows']));
      if ($fresh['fingerprint'] !== $preview['fingerprint']) {
        throw new \RuntimeException('Records changed since preview; preview again.');
      }
      if (!$selected) {
        throw new \RuntimeException('Select at least one approved row.');
      }
      $selected_rows = [];
      foreach ($selected as $index) {
        if (!isset($fresh['rows'][$index]) || $fresh['rows'][$index]['errors']) {
          throw new \RuntimeException('Selected row is invalid; nothing imported.');
        }
        $selected_rows[] = $fresh['rows'][$index];
      }
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $plan = $this->plan();
      if (!$plan) {
        $plan = $storage->create([
          'type' => 'reg_procurement_plan',
          'title' => self::PLAN_TITLE,
          'field_reg_source_id' => self::PLAN_KEY,
          'field_reg_financial_year' => '2025/2026',
          'field_reg_entity' => 'eucl',
          'field_reg_proc_plan_status' => 'draft',
          'status' => 0,
          'moderation_state' => 'draft',
        ]);
      }
      $plan_changed = $plan->isNew() || $plan->get('field_reg_source_id')->isEmpty();
      $plan->set('field_reg_source_id', self::PLAN_KEY);
      $documents = $plan->get('field_reg_private_documents')->getValue();
      $hashes = [];
      foreach ($plan->get('field_reg_private_documents')->referencedEntities() as $file) {
        $hashes[] = hash_file('sha256', $file->getFileUri());
      }
      foreach (array_filter([$csv, $workbook]) as $file) {
        $hash = hash_file('sha256', $file->getFileUri());
        if (!in_array($hash, $hashes, TRUE)) {
          $documents[] = [
            'target_id' => $file->id(),
            'display' => 0,
            'description' => 'Private procurement import source',
          ];
          $hashes[] = $hash;
          $plan_changed = TRUE;
        }
      }
      if ($plan_changed) {
        $plan->set('field_reg_private_documents', $documents);
        $this->save($plan, $expected_hash);
      }
      $counts = [
        'inserted' => 0,
        'updated' => 0,
        'skipped' => count($fresh['rows']) - count($selected_rows),
        'warnings' => 0,
        'errors' => 0,
      ];
      foreach ($selected_rows as $row) {
        $counts['warnings'] += count($row['warnings']);
        if ($row['action'] === 'skip') {
          $counts['skipped']++;
          continue;
        }
        $node = $row['nid'] ? $storage->load($row['nid']) : $storage->create([
          'type' => 'reg_procurement_plan_item',
          'field_reg_procurement_plan_ref' => $plan->id(),
          'field_reg_entity' => 'eucl',
          'status' => 0,
          'moderation_state' => 'draft',
          'field_reg_public_visibility' => 0,
        ]);
        foreach (ProcurementCsv::FIELDS as $key => $field) {
          if ($field === 'field_reg_description') {
            if ($node->get($field)->value === $row['values'][$key]) {
              continue;
            }
            $node->set($field, $row['values'][$key] === NULL ? NULL : [
              'value' => $row['values'][$key],
              'format' => 'plain_text',
            ]);
          }
          else {
            $node->set($field, $row['values'][$key]);
          }
        }
        $node->set('field_reg_review_status', 'needs_review');
        $node->set('field_reg_import_warnings', implode("\n", $row['warnings']));
        $node->set('field_reg_import_state', json_encode([
          'baseline' => $row['data'],
          'sha256' => $expected_hash,
          'workbook_sha256' => $workbook_hash,
          'imported' => gmdate('c'),
          'uid' => \Drupal::currentUser()->id(),
        ], JSON_THROW_ON_ERROR));
        $this->save($node, $expected_hash);
        $counts[$row['action'] === 'insert' ? 'inserted' : 'updated']++;
      }
      \Drupal::logger('reg_procurement')->notice('Plan @key CSV @hash: @counts', [
        '@key' => self::PLAN_KEY,
        '@hash' => $expected_hash,
        '@counts' => json_encode($counts),
      ]);
      unset($transaction);
      return $counts;
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      $storage?->resetCache();
      throw $e;
    }
    finally {
      $lock->release('reg_procurement_import:' . self::PLAN_KEY);
    }
  }

  /**
   * Validates and saves an unpublished revision with source audit metadata.
   */
  private function save(NodeInterface $node, string $hash): void {
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', 'draft');
    }
    $node->setUnpublished();
    $node->setNewRevision(TRUE);
    $node->setRevisionUserId(\Drupal::currentUser()->id());
    $node->setRevisionLogMessage('Reviewed CSV import SHA-256 ' . $hash);
    $violations = $node->validate();
    if ($violations->count()) {
      throw new \RuntimeException('Entity validation failed: ' . (string) $violations);
    }
    $node->save();
  }

}
