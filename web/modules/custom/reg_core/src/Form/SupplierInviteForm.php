<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Supplier\SupplierManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/** Organization-admin team invitation form. */
final class SupplierInviteForm extends FormBase {
  public function __construct(private readonly SupplierManager $supplierManager, private readonly AccountProxyInterface $regCurrentUser, private readonly Connection $database, private readonly TimeInterface $time, private readonly MailManagerInterface $mailManager, private readonly FloodInterface $flood, private readonly LanguageManagerInterface $regLanguageManager) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('reg_core.supplier_manager'), $container->get('current_user'), $container->get('database'), $container->get('datetime.time'), $container->get('plugin.manager.mail'), $container->get('flood'), $container->get('language_manager')); }
  public function getFormId(): string { return 'reg_supplier_invite'; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if (!$this->supplierManager->canManageTeam() || !$this->supplierManager->organization()) throw new AccessDeniedHttpException();
    $form['first_name'] = ['#type' => 'textfield', '#title' => $this->t('First name'), '#required' => TRUE]; $form['last_name'] = ['#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE];
    $form['email'] = ['#type' => 'email', '#title' => $this->t('Work email'), '#required' => TRUE];
    $form['role'] = ['#type' => 'select', '#title' => $this->t('Organization role'), '#required' => TRUE, '#options' => ['supplier_admin' => $this->t('Supplier administrator'), 'bid_manager' => $this->t('Bid manager'), 'bid_contributor' => $this->t('Bid contributor'), 'supplier_viewer' => $this->t('Supplier viewer')]];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('SEND INVITATION'), '#button_type' => 'primary']; $form['#attached']['library'][] = 'reg_core/supplier_portal'; return $form;
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void { if (!$this->flood->isAllowed('reg_core_supplier_invite', 20, 3600, (string) $this->regCurrentUser->id())) $form_state->setErrorByName('email', $this->t('Too many invitations. Please try again later.')); }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $org = $this->supplierManager->organization(); $now = $this->time->getRequestTime(); $email = mb_strtolower(trim((string) $form_state->getValue('email'))); $token = Crypt::randomBytesBase64(32);
    $this->database->insert('reg_core_supplier_invitation')->fields(['token_hash' => hash('sha256', $token), 'organization_nid' => $org->id(), 'email' => $email, 'email_hash' => hash('sha256', $email), 'first_name' => trim((string) $form_state->getValue('first_name')), 'last_name' => trim((string) $form_state->getValue('last_name')), 'organization_role' => $form_state->getValue('role'), 'invited_by' => $this->regCurrentUser->id(), 'expires' => $now + 604800, 'accepted' => 0, 'created' => $now])->execute();
    $url = Url::fromRoute('reg_core.supplier_invite_accept', ['token' => $token], ['absolute' => TRUE])->toString(); $this->mailManager->mail('reg_core', 'supplier_invitation', $email, $this->regLanguageManager->getCurrentLanguage()->getId(), ['invitation_url' => $url, 'organization' => $org->label()]);
    $this->flood->register('reg_core_supplier_invite', 3600, (string) $this->regCurrentUser->id()); reg_core_supplier_audit('team_invited', (int) $org->id(), (int) $this->regCurrentUser->id(), $form_state->getValue('role'));
    $this->messenger()->addStatus($this->t('The invitation was sent and expires in seven days.')); $form_state->setRedirect('reg_core.supplier_team');
  }
}
