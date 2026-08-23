<?php

namespace Drupal\reg_core\Supplier;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\user\UserInterface;

/** Organization membership and supplier authorization rules. */
final class SupplierManager {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly AccountProxyInterface $currentUser,
    private readonly TimeInterface $time,
    private readonly Connection $database,
  ) {}

  public function membership(?int $uid = NULL): ?NodeInterface {
    $uid ??= (int) $this->currentUser->id();
    if ($uid < 1) return NULL;
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_membership')->condition('field_reg_member_user.target_id', $uid)->condition('field_reg_membership_status', 'active')->sort('created', 'ASC')->range(0, 1)->execute();
    $membership = $ids ? $storage->load(reset($ids)) : NULL;
    return $membership instanceof NodeInterface ? $membership : NULL;
  }

  public function organization(?int $uid = NULL): ?NodeInterface {
    $organization = $this->membership($uid)?->get('field_reg_supplier_org')->entity;
    return $organization instanceof NodeInterface ? $organization : NULL;
  }

  public function organizationRole(?int $uid = NULL): string {
    return (string) ($this->membership($uid)?->get('field_reg_org_role')->value ?? '');
  }

  public function canManageTeam(?int $uid = NULL): bool {
    return $this->organizationRole($uid) === 'supplier_admin';
  }

  public function canPrepareBid(NodeInterface $tender, ?int $uid = NULL): bool {
    $organization = $this->organization($uid);
    $role = $this->organizationRole($uid);
    $status = (string) ($organization?->get('field_reg_supplier_status')->value ?? '');
    return $organization instanceof NodeInterface
      && in_array($status, ['pending', 'verified'], TRUE)
      && in_array($role, ['supplier_admin', 'bid_manager', 'bid_contributor'], TRUE)
      && $this->tenderAcceptsRegBids($tender);
  }

  public function canSubmitBid(NodeInterface $tender, ?int $uid = NULL): bool {
    $organization = $this->organization($uid);
    return $this->canPrepareBid($tender, $uid)
      && (string) $organization?->get('field_reg_supplier_status')->value === 'verified'
      && (string) $organization?->get('field_reg_document_scan_status')->value === 'clean'
      && in_array($this->organizationRole($uid), ['supplier_admin', 'bid_manager'], TRUE);
  }

  public function tenderAcceptsRegBids(NodeInterface $tender): bool {
    if ($tender->bundle() !== 'reg_tender' || !$tender->isPublished()) return FALSE;
    if ((string) $tender->get('field_reg_submission_method')->value !== 'reg_online' || (string) $tender->get('field_reg_tender_status')->value !== 'active') return FALSE;
    $deadline = strtotime((string) $tender->get('field_reg_closing_date')->value . ' UTC') ?: 0;
    return $deadline > $this->time->getRequestTime();
  }

  public function createMembership(NodeInterface $organization, UserInterface $user, string $role, int $invited_by = 0): NodeInterface {
    if ($existing = $this->membership((int) $user->id())) return $existing;
    $membership = Node::create(['type' => 'reg_supplier_membership', 'title' => 'Supplier membership ' . $organization->id() . ':' . $user->id(), 'uid' => $user->id(), 'status' => 0, 'field_reg_supplier_org' => $organization->id(), 'field_reg_member_user' => $user->id(), 'field_reg_org_role' => $role, 'field_reg_membership_status' => 'active', 'field_reg_invited_by' => $invited_by ?: NULL]);
    $membership->save();
    reg_core_supplier_audit('membership_created', (int) $organization->id(), (int) $user->id(), $role);
    return $membership;
  }

  public function tinExists(string $tin, int $exclude_nid = 0): bool {
    $normalized = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $tin) ?? '');
    if ($normalized === '') return FALSE;
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_profile')->condition('field_reg_tin', $normalized)->execute();
    return (bool) array_filter(array_map('intval', $ids), static fn(int $id): bool => $id !== $exclude_nid);
  }

  public function members(NodeInterface $organization): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_membership')->condition('field_reg_supplier_org.target_id', $organization->id())->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $membership) {
      $user = $membership->get('field_reg_member_user')->entity;
      if (!$user instanceof UserInterface) continue;
      $rows[] = ['name' => trim((string) $user->get('field_reg_first_name')->value . ' ' . (string) $user->get('field_reg_last_name')->value) ?: $user->getDisplayName(), 'email' => $user->getEmail(), 'role' => $this->listLabel($membership, 'field_reg_org_role'), 'status' => $this->listLabel($membership, 'field_reg_membership_status')];
    }
    return $rows;
  }

  public function auditHistory(NodeInterface $organization): array {
    return $this->database->select('reg_core_supplier_audit', 'a')->fields('a', ['event', 'detail', 'uid', 'created'])->condition('organization_nid', $organization->id())->orderBy('created', 'DESC')->range(0, 100)->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function completeness(NodeInterface $organization, UserInterface $user): array {
    $company = !$organization->get('field_reg_company_name')->isEmpty() && !$organization->get('field_reg_registration_no')->isEmpty() && !$organization->get('field_reg_supplier_categories')->isEmpty();
    $documents = !$organization->get('field_reg_private_documents')->isEmpty();
    $email = (bool) $user->get('field_reg_email_verified')->value;
    $verified = (string) $organization->get('field_reg_supplier_status')->value === 'verified';
    return ['company' => $company, 'documents' => $documents, 'email' => $email, 'verified' => $verified, 'complete_count' => count(array_filter([$company, $documents, $email, $verified])), 'total' => 4];
  }

  private function listLabel(NodeInterface $node, string $field): string {
    $value = (string) $node->get($field)->value;
    $allowed = $node->getFieldDefinition($field)->getFieldStorageDefinition()->getSetting('allowed_values') ?: [];
    return (string) ($allowed[$value] ?? $value);
  }
}
