<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Form\SupplierBidForm;
use Drupal\reg_core\Form\SupplierInviteForm;
use Drupal\reg_core\Form\SupplierProfileForm;
use Drupal\reg_core\Supplier\SupplierManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Public-theme supplier account and organization workspace. */
final class SupplierPortalController extends ControllerBase {

  public function __construct(private readonly EntityTypeManagerInterface $regEntityTypeManager, private readonly AccountProxyInterface $regCurrentUser, private readonly SupplierManager $supplierManager, private readonly FormBuilderInterface $regFormBuilder, private readonly DateFormatterInterface $dateFormatter, private readonly Connection $database) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('current_user'), $container->get('reg_core.supplier_manager'), $container->get('form_builder'), $container->get('date.formatter'), $container->get('database'));
  }

  public function login(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('user.login', [], ['query' => ['destination' => '/supplier/dashboard']])->toString());
  }

  public function verify(int $uid, string $token): RedirectResponse {
    $hash = hash('sha256', $token);
    $record = $this->database->select('reg_core_supplier_verification', 'v')->fields('v')->condition('token_hash', $hash)->condition('uid', $uid)->condition('used', 0)->condition('expires', \Drupal::time()->getRequestTime(), '>')->execute()->fetchAssoc();
    $user = $this->regEntityTypeManager->getStorage('user')->load($uid);
    if (!$record || !$user instanceof UserInterface) throw new AccessDeniedHttpException('This verification link is invalid or has expired.');
    $this->database->update('reg_core_supplier_verification')->fields(['used' => 1])->condition('token_hash', $hash)->execute();
    $user->activate()->addRole('reg_supplier')->set('field_reg_email_verified', 1)->save();
    reg_core_supplier_audit('email_verified', 0, $uid);
    user_login_finalize($user);
    $this->messenger()->addStatus($this->t('Your email address is verified. Complete your supplier organization profile.'));
    return new RedirectResponse(Url::fromRoute('reg_core.supplier_profile_setup')->toString());
  }

  public function dashboard(): array {
    $organization = $this->supplierManager->organization();
    $user = $this->regEntityTypeManager->getStorage('user')->load($this->regCurrentUser->id());
    $counts = ['tenders' => 0, 'bids' => 0, 'team' => 0]; if ($organization) { $storage = $this->regEntityTypeManager->getStorage('node'); $counts['bids'] = (int) $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_bid')->condition('field_reg_supplier_org.target_id', $organization->id())->count()->execute(); $counts['team'] = count($this->supplierManager->members($organization)); foreach ($storage->loadMultiple($storage->getQuery()->accessCheck(TRUE)->condition('type', 'reg_tender')->condition('status', 1)->condition('field_reg_submission_method', 'reg_online')->execute()) as $tender) if ($this->supplierManager->tenderAcceptsRegBids($tender)) $counts['tenders']++; }
    return ['#theme' => 'reg_supplier_dashboard', '#organization' => $organization, '#organization_role' => $this->supplierManager->organizationRole(), '#completeness' => $organization && $user instanceof UserInterface ? $this->supplierManager->completeness($organization, $user) : NULL, '#counts' => $counts, '#links' => $this->workspaceLinks(), '#attached' => ['library' => ['reg_core/supplier_portal']], '#cache' => ['contexts' => ['user'], 'max-age' => 0]];
  }

  public function profile(): array { return $this->regFormBuilder->getForm(SupplierProfileForm::class); }

  public function team(): array {
    $organization = $this->supplierManager->organization();
    if (!$organization) throw new AccessDeniedHttpException('Complete the organization profile first.');
    return ['#theme' => 'reg_supplier_team', '#members' => $this->supplierManager->members($organization), '#can_invite' => $this->supplierManager->canManageTeam(), '#links' => $this->workspaceLinks(), '#attached' => ['library' => ['reg_core/supplier_portal']], '#cache' => ['contexts' => ['user'], 'max-age' => 0]];
  }

  public function invite(): array { return $this->regFormBuilder->getForm(SupplierInviteForm::class); }

  public function tenders(): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'reg_tender')->condition('status', 1)->condition('field_reg_tender_status', 'active')->condition('field_reg_submission_method', 'reg_online')->sort('field_reg_closing_date')->execute();
    $items = [];
    foreach ($storage->loadMultiple($ids) as $tender) if ($tender instanceof NodeInterface && $this->supplierManager->tenderAcceptsRegBids($tender)) $items[] = ['title' => $tender->label(), 'reference' => (string) $tender->get('field_reg_reference')->value, 'status' => $this->t('REG Online'), 'updated' => $this->dateFormatter->format(strtotime((string) $tender->get('field_reg_closing_date')->value . ' UTC'), 'short'), 'url' => Url::fromRoute('reg_core.bid_start', ['tender' => $tender->id()])->toString()];
    return $this->portal($this->t('Open REG online tenders'), $items);
  }

  public function bids(): array {
    $organization = $this->supplierManager->organization(); $items = [];
    if ($organization) {
      $storage = $this->regEntityTypeManager->getStorage('node');
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_bid')->condition('field_reg_supplier_org.target_id', $organization->id())->sort('changed', 'DESC')->execute();
      foreach ($storage->loadMultiple($ids) as $bid) $items[] = ['title' => $bid->get('field_reg_bid_tender')->entity?->label() ?: $bid->label(), 'reference' => (string) $bid->get('field_reg_submission_ref')->value, 'status' => $this->listLabel($bid, 'field_reg_submission_status'), 'updated' => $this->dateFormatter->format($bid->getChangedTime(), 'short')];
    }
    return $this->portal($this->t('Organization bids'), $items);
  }

  public function bid(int $tender): array {
    $node = $this->regEntityTypeManager->getStorage('node')->load($tender);
    if (!$node instanceof NodeInterface || !$this->supplierManager->tenderAcceptsRegBids($node)) throw new NotFoundHttpException();
    if (!$this->supplierManager->organization()) { $this->messenger()->addWarning($this->t('Create your supplier organization before preparing a bid.')); return $this->redirect('reg_core.supplier_profile'); }
    if (!$this->supplierManager->canPrepareBid($node)) throw new AccessDeniedHttpException('Your organization status or membership role does not allow bid preparation.');
    return $this->regFormBuilder->getForm(SupplierBidForm::class, $node);
  }

  private function portal($heading, array $items): array { return ['#theme' => 'reg_supplier_portal', '#heading' => $heading, '#items' => $items, '#links' => $this->workspaceLinks(), '#attached' => ['library' => ['reg_core/supplier_portal']], '#cache' => ['contexts' => ['user'], 'max-age' => 0]]; }
  private function workspaceLinks(): array { return [['label' => $this->t('Dashboard'), 'url' => Url::fromRoute('reg_core.supplier_dashboard')->toString()], ['label' => $this->t('Profile'), 'url' => Url::fromRoute('reg_core.supplier_profile')->toString()], ['label' => $this->t('Team'), 'url' => Url::fromRoute('reg_core.supplier_team')->toString()], ['label' => $this->t('Tenders'), 'url' => Url::fromRoute('reg_core.supplier_tenders')->toString()], ['label' => $this->t('Bids'), 'url' => Url::fromRoute('reg_core.supplier_bids')->toString()]]; }
  private function listLabel(NodeInterface $node, string $field): string { $value = (string) $node->get($field)->value; return (string) (($node->getFieldDefinition($field)->getFieldStorageDefinition()->getSetting('allowed_values') ?: [])[$value] ?? $value); }
}
