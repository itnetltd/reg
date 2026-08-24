<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\UrlHelper;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Form\SupplierBidForm;
use Drupal\reg_core\Form\SupplierInviteForm;
use Drupal\reg_core\Form\SupplierLoginForm;
use Drupal\reg_core\Form\SupplierProfileForm;
use Drupal\reg_core\Supplier\SupplierManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Public-theme supplier account and organization workspace. */
final class SupplierPortalController extends ControllerBase {

  public function __construct(private readonly EntityTypeManagerInterface $regEntityTypeManager, private readonly AccountProxyInterface $regCurrentUser, private readonly SupplierManager $supplierManager, private readonly FormBuilderInterface $regFormBuilder, private readonly DateFormatterInterface $dateFormatter, private readonly Connection $database, private readonly ConfigFactoryInterface $regConfigFactory) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('current_user'), $container->get('reg_core.supplier_manager'), $container->get('form_builder'), $container->get('date.formatter'), $container->get('database'), $container->get('config.factory'));
  }

  public function login(Request $request): array|RedirectResponse {
    if ($this->regCurrentUser->isAuthenticated()) {
      $supplier_role = $this->regCurrentUser->hasRole('reg_supplier_pending') || $this->regCurrentUser->hasRole('reg_supplier');
      if ($supplier_role && $this->supplierManager->membership() && $this->supplierManager->organization()) {
        return new RedirectResponse(Url::fromRoute('reg_core.supplier_dashboard')->toString());
      }
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-supplier-shell']],
        'message' => ['#markup' => '<div class="messages messages--warning" role="status"><p>' . $this->t('You are already signed in. Sign out before signing in as a supplier.') . '</p></div>'],
        'logout' => [
          '#type' => 'link',
          '#title' => $this->t('SIGN OUT'),
          '#url' => Url::fromRoute('user.logout.confirm'),
          '#attributes' => ['class' => ['reg-supplier-button']],
        ],
        '#attached' => ['library' => ['reg_core/supplier_portal']],
        '#cache' => ['contexts' => ['user'], 'max-age' => 0],
      ];
    }
    $destination = $this->safeDestination((string) $request->query->get('destination')) ?: '/supplier/dashboard';
    $request->query->set('destination', $destination);
    return [
      'intro' => ['#markup' => '<div class="reg-supplier-form__intro"><h1>' . $this->t('SUPPLIER SIGN IN') . '</h1><p>' . $this->t('Sign in with the email address used for supplier registration.') . '</p></div>'],
      'form' => $this->regFormBuilder->getForm(SupplierLoginForm::class),
      'links' => ['#markup' => '<p><a href="' . Url::fromRoute('user.pass')->toString() . '">' . $this->t('Forgot Password?') . '</a></p><p>' . $this->t('New supplier?') . ' <a href="' . Url::fromRoute('reg_core.supplier_register', [], ['query' => ['destination' => $destination]])->toString() . '">' . $this->t('REGISTER →') . '</a></p>'],
      '#attached' => ['library' => ['reg_core/supplier_portal']],
    ];
  }

  public function registrationSuccess(Request $request): array {
    $session = $request->getSession();
    $email = (string) $session->get('reg_supplier_registration_email', '');
    $mail_delayed = (bool) $session->remove('reg_supplier_mail_delayed');
    $confirmation_enabled = (bool) $session->remove('reg_supplier_confirmation_enabled');
    if ($mail_delayed) {
      $notification = $this->t('Your registration has been received. Email confirmation may be delayed.');
    }
    elseif ($confirmation_enabled) {
      $notification = $this->t('Confirmation and email-verification messages have been sent to:');
    }
    else {
      $notification = $this->t('An email-verification message has been sent to:');
    }
    return [
      '#type' => 'container', '#attributes' => ['class' => ['reg-supplier-shell']],
      'heading' => ['#markup' => '<h1>' . $this->t('REGISTRATION RECEIVED') . '</h1>'],
      'message' => ['#markup' => '<p>' . $this->t('Thank you for registering as a supplier.') . '</p><p>' . $notification . '</p>' . ($email !== '' ? '<p><strong>' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</strong></p>' : '') . '<p>' . $this->t('Your registration remains securely stored in Drupal while notification delivery is processed.') . '</p>'],
      'login' => ['#type' => 'link', '#title' => $this->t('SUPPLIER SIGN IN'), '#url' => Url::fromRoute('reg_core.supplier_login'), '#attributes' => ['class' => ['reg-supplier-button']]],
      '#attached' => ['library' => ['reg_core/supplier_portal']],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function privacy(): array {
    return ['#markup' => '<div class="reg-supplier-shell"><h1>' . $this->t('Supplier privacy and data use') . '</h1><p>' . $this->t('REG uses supplier registration information for identity verification, procurement participation, security review, and required audit records. Private supplier documents are available only to authorized supplier representatives and REG procurement reviewers.') . '</p></div>', '#attached' => ['library' => ['reg_core/supplier_portal']]];
  }

  public function verify(int $uid, string $token): RedirectResponse {
    $hash = hash('sha256', $token);
    $record = $this->database->select('reg_core_supplier_verification', 'v')->fields('v')->condition('token_hash', $hash)->condition('uid', $uid)->condition('used', 0)->condition('expires', \Drupal::time()->getRequestTime(), '>')->execute()->fetchAssoc();
    $user = $this->regEntityTypeManager->getStorage('user')->load($uid);
    if (!$record || !$user instanceof UserInterface) {
      $this->messenger()->addError($this->t('This verification link is invalid or has expired. Please register again or contact REG Procurement.'));
      return new RedirectResponse(Url::fromRoute('reg_core.supplier_register')->toString());
    }
    $this->database->update('reg_core_supplier_verification')->fields(['used' => 1])->condition('token_hash', $hash)->execute();
    $require_approval = $this->regConfigFactory->get('reg_core.procurement_settings')->get('require_supplier_approval') !== FALSE;
    $organization = $this->supplierManager->organization($uid);
    $auto_approve = !$require_approval && $organization instanceof NodeInterface && (string) $organization->get('field_reg_document_scan_status')->value === 'clean';
    $user->activate()->set('field_reg_email_verified', 1);
    if ($auto_approve) {
      $user->addRole('reg_supplier')->removeRole('reg_supplier_pending');
    }
    else {
      $user->addRole('reg_supplier_pending')->removeRole('reg_supplier');
    }
    $user->save();
    if ($organization instanceof NodeInterface && (string) $organization->get('field_reg_supplier_status')->value === 'email_unverified') {
      $new_status = $auto_approve ? 'approved' : 'pending';
      $organization->set('field_reg_supplier_status', $new_status)->setNewRevision(TRUE);
      $organization->setRevisionLogMessage($auto_approve ? 'Supplier email verified; approval was not required and the clean registration was activated.' : 'Supplier email verified; registration moved to pending approval.');
      $organization->save();
      reg_core_supplier_audit('status_changed', (int) $organization->id(), $uid, 'email_unverified -> ' . $new_status);
    }
    reg_core_supplier_audit('email_verified', (int) ($organization?->id() ?: 0), $uid);
    user_login_finalize($user);
    $this->messenger()->addStatus($auto_approve ? $this->t('Your email address is verified and your supplier registration is active.') : $this->t('Your email address is verified. Your supplier registration is pending REG approval.'));
    $destination = $this->safeDestination((string) ($record['destination'] ?? '')) ?: $this->safeDestination((string) \Drupal::request()->getSession()->get('reg_supplier_return_destination', ''));
    return new RedirectResponse($destination ?: Url::fromRoute('reg_core.supplier_dashboard')->toString());
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

  public function bid(int $tender): array|RedirectResponse {
    $node = $this->regEntityTypeManager->getStorage('node')->load($tender);
    if (!$node instanceof NodeInterface || !$this->supplierManager->tenderAcceptsRegBids($node)) throw new NotFoundHttpException();
    if ($this->regCurrentUser->isAnonymous()) {
      return new RedirectResponse(Url::fromRoute('reg_core.supplier_login', [], ['query' => ['destination' => Url::fromRoute('reg_core.bid_start', ['tender' => $node->id()])->toString()]])->toString());
    }
    if (!$this->supplierManager->organization()) { $this->messenger()->addWarning($this->t('Create your supplier organization before preparing a bid.')); return $this->redirect('reg_core.supplier_profile'); }
    if (!$this->supplierManager->canPrepareBid($node)) { $this->messenger()->addWarning($this->t('Your supplier registration must be approved before you can prepare a bid.')); return $this->redirect('reg_core.supplier_dashboard'); }
    return $this->regFormBuilder->getForm(SupplierBidForm::class, $node);
  }

  private function portal($heading, array $items): array { return ['#theme' => 'reg_supplier_portal', '#heading' => $heading, '#items' => $items, '#links' => $this->workspaceLinks(), '#attached' => ['library' => ['reg_core/supplier_portal']], '#cache' => ['contexts' => ['user'], 'max-age' => 0]]; }
  private function workspaceLinks(): array {
    $links = [
      ['label' => $this->t('Dashboard'), 'url' => Url::fromRoute('reg_core.supplier_dashboard')->toString()],
      ['label' => $this->t('Company Profile'), 'url' => Url::fromRoute('reg_core.supplier_profile')->toString()],
      ['label' => $this->t('Documents'), 'url' => Url::fromRoute('reg_core.supplier_profile')->toString()],
      ['label' => $this->t('Account Settings'), 'url' => Url::fromRoute('entity.user.edit_form', ['user' => $this->regCurrentUser->id()])->toString()],
    ];
    if ((string) ($this->supplierManager->organization()?->get('field_reg_supplier_status')->value ?? '') === 'approved') {
      $links[] = ['label' => $this->t('Open REG Tenders'), 'url' => Url::fromRoute('reg_core.supplier_tenders')->toString()];
      $links[] = ['label' => $this->t('My Bids'), 'url' => Url::fromRoute('reg_core.supplier_bids')->toString()];
      $links[] = ['label' => $this->t('Team'), 'url' => Url::fromRoute('reg_core.supplier_team')->toString()];
    }
    return $links;
  }
  private function safeDestination(string $destination): string { $destination = trim($destination); return $destination !== '' && !UrlHelper::isExternal($destination) && str_starts_with($destination, '/') && !str_starts_with($destination, '//') && !str_contains($destination, '\\') && !preg_match('/[\x00-\x1F\x7F]/', $destination) ? $destination : ''; }
  private function listLabel(NodeInterface $node, string $field): string { $value = (string) $node->get($field)->value; return (string) (($node->getFieldDefinition($field)->getFieldStorageDefinition()->getSetting('allowed_values') ?: [])[$value] ?? $value); }
}
