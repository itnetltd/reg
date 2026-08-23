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
use Drupal\Core\Url;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/** Creates an email-verified, password-based supplier account. */
final class SupplierRegisterForm extends FormBase {
  public function __construct(private readonly Connection $database, private readonly TimeInterface $time, private readonly FloodInterface $flood, private readonly MailManagerInterface $mailManager, private readonly RequestStack $regRequestStack, private readonly LanguageManagerInterface $regLanguageManager) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('database'), $container->get('datetime.time'), $container->get('flood'), $container->get('plugin.manager.mail'), $container->get('request_stack'), $container->get('language_manager')); }
  public function getFormId(): string { return 'reg_supplier_register'; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($this->currentUser()->isAuthenticated()) return ['notice' => ['#markup' => '<p>' . $this->t('You are already signed in. Sign out before registering a different supplier account.') . '</p>']];
    $form['intro'] = ['#markup' => '<p>' . $this->t('Create a secure account for your organization’s REG online tender workspace. Your email address must be verified before onboarding.') . '</p>'];
    $form['first_name'] = ['#type' => 'textfield', '#title' => $this->t('First name'), '#required' => TRUE, '#maxlength' => 100];
    $form['last_name'] = ['#type' => 'textfield', '#title' => $this->t('Last name'), '#required' => TRUE, '#maxlength' => 100];
    $form['email'] = ['#type' => 'email', '#title' => $this->t('Work email'), '#required' => TRUE, '#maxlength' => 254];
    $form['phone'] = ['#type' => 'tel', '#title' => $this->t('Phone number'), '#required' => TRUE, '#maxlength' => 32];
    $form['password'] = ['#type' => 'password_confirm', '#required' => TRUE];
    $form['terms'] = ['#type' => 'checkbox', '#title' => $this->t('I accept the supplier portal terms and confirm I am authorized to represent my organization.'), '#required' => TRUE];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('CREATE SUPPLIER ACCOUNT'), '#button_type' => 'primary'];
    $form['#attributes']['class'][] = 'reg-supplier-form'; $form['#attached']['library'][] = 'reg_core/supplier_portal';
    return $form;
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ip = (string) $this->regRequestStack->getCurrentRequest()?->getClientIp();
    if (!$this->flood->isAllowed('reg_core_supplier_register', 5, 3600, $ip)) $form_state->setErrorByName('email', $this->t('Too many registration attempts. Please try again later.'));
    $email = mb_strtolower(trim((string) $form_state->getValue('email')));
    if (user_load_by_mail($email)) $form_state->setErrorByName('email', $this->t('An account with this email cannot be created. Use sign in or password reset instead.'));
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $now = $this->time->getRequestTime(); $email = mb_strtolower(trim((string) $form_state->getValue('email'))); $pass = $form_state->getValue('password');
    $user = User::create(['name' => $email, 'mail' => $email, 'pass' => is_array($pass) ? $pass['pass1'] : $pass, 'status' => 0, 'roles' => ['reg_supplier'], 'field_reg_first_name' => trim((string) $form_state->getValue('first_name')), 'field_reg_last_name' => trim((string) $form_state->getValue('last_name')), 'field_reg_phone' => trim((string) $form_state->getValue('phone')), 'field_reg_email_verified' => 0, 'field_reg_supplier_terms_at' => $now]);
    $user->save();
    $token = Crypt::randomBytesBase64(32); $hash = hash('sha256', $token);
    $this->database->insert('reg_core_supplier_verification')->fields(['token_hash' => $hash, 'uid' => $user->id(), 'expires' => $now + 86400, 'used' => 0, 'created' => $now])->execute();
    $verification_url = Url::fromRoute('reg_core.supplier_verify', ['uid' => $user->id(), 'token' => $token], ['absolute' => TRUE])->toString();
    $this->mailManager->mail('reg_core', 'supplier_verification', $email, $this->regLanguageManager->getCurrentLanguage()->getId(), ['verification_url' => $verification_url, 'name' => $form_state->getValue('first_name')]);
    $this->flood->register('reg_core_supplier_register', 3600, (string) $this->regRequestStack->getCurrentRequest()?->getClientIp());
    reg_core_supplier_audit('account_registered', 0, (int) $user->id());
    $this->messenger()->addStatus($this->t('Check your email for a verification link. The link expires in 24 hours.'));
    $form_state->setRedirect('reg_core.supplier_login');
  }
}
