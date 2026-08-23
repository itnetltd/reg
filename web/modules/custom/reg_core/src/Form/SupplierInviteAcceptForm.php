<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Supplier\SupplierManager;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Accepts an expiring, organization-scoped supplier invitation. */
final class SupplierInviteAcceptForm extends FormBase {
  private array $invitation = [];
  public function __construct(private readonly Connection $database, private readonly TimeInterface $time, private readonly EntityTypeManagerInterface $regEntityTypeManager, private readonly SupplierManager $supplierManager) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('database'), $container->get('datetime.time'), $container->get('entity_type.manager'), $container->get('reg_core.supplier_manager')); }
  public function getFormId(): string { return 'reg_supplier_invite_accept'; }
  public function buildForm(array $form, FormStateInterface $form_state, ?string $token = NULL): array {
    $this->invitation = $this->database->select('reg_core_supplier_invitation', 'i')->fields('i')->condition('token_hash', hash('sha256', (string) $token))->condition('accepted', 0)->condition('expires', $this->time->getRequestTime(), '>')->execute()->fetchAssoc() ?: [];
    if (!$this->invitation) throw new AccessDeniedHttpException('This invitation is invalid or expired.'); $form_state->set('invitation', $this->invitation);
    $form['summary'] = ['#markup' => '<p>' . $this->t('Accept the invitation for <strong>@email</strong>.', ['@email' => $this->invitation['email']]) . '</p>'];
    if ($this->currentUser()->isAnonymous() && user_load_by_mail($this->invitation['email'])) { $form['existing'] = ['#markup' => '<p>' . $this->t('An account already uses this email. Sign in with that account, then reopen this invitation link.') . '</p>' . Link::fromTextAndUrl($this->t('SUPPLIER LOGIN'), Url::fromRoute('reg_core.supplier_login'))->toString()]; return $form; }
    if ($this->currentUser()->isAnonymous()) { $form['password'] = ['#type' => 'password_confirm', '#required' => TRUE]; $form['terms'] = ['#type' => 'checkbox', '#title' => $this->t('I accept the supplier portal terms.'), '#required' => TRUE]; }
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('ACCEPT INVITATION'), '#button_type' => 'primary']; return $form;
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void { $i = $form_state->get('invitation'); if ($this->currentUser()->isAuthenticated() && mb_strtolower((string) $this->currentUser()->getEmail()) !== $i['email']) $form_state->setErrorByName('actions', $this->t('Sign in with the email address that received this invitation.')); $membership = $this->supplierManager->membership(); if ($membership && (int) $membership->get('field_reg_supplier_org')->target_id !== (int) $i['organization_nid']) $form_state->setErrorByName('actions', $this->t('This account already belongs to a different supplier organization. Contact REG Procurement for assistance.')); }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $i = $form_state->get('invitation'); $user = $this->currentUser()->isAuthenticated() ? $this->regEntityTypeManager->getStorage('user')->load($this->currentUser()->id()) : NULL;
    if (!$user instanceof UserInterface) { $pass = $form_state->getValue('password'); $user = User::create(['name' => $i['email'], 'mail' => $i['email'], 'pass' => is_array($pass) ? $pass['pass1'] : $pass, 'status' => 1, 'roles' => ['reg_supplier'], 'field_reg_first_name' => $i['first_name'], 'field_reg_last_name' => $i['last_name'], 'field_reg_email_verified' => 1, 'field_reg_supplier_terms_at' => $this->time->getRequestTime()]); $user->save(); user_login_finalize($user); }
    if (!$user->hasRole('reg_supplier') || !(bool) $user->get('field_reg_email_verified')->value) { $user->addRole('reg_supplier')->set('field_reg_email_verified', 1)->save(); }
    $org = $this->regEntityTypeManager->getStorage('node')->load($i['organization_nid']); if (!$org instanceof NodeInterface) throw new AccessDeniedHttpException();
    $this->supplierManager->createMembership($org, $user, $i['organization_role'], (int) $i['invited_by']); $this->database->update('reg_core_supplier_invitation')->fields(['accepted' => 1])->condition('token_hash', $i['token_hash'])->execute(); reg_core_supplier_audit('team_invitation_accepted', (int) $org->id(), (int) $user->id(), $i['organization_role']); $form_state->setRedirect('reg_core.supplier_dashboard');
  }
}
