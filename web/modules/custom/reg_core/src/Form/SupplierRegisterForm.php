<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\reg_core\Supplier\SupplierManager;
use Drupal\reg_core\Supplier\SupplierDocumentScannerInterface;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Registers one pending supplier account and organization profile. */
final class SupplierRegisterForm extends FormBase {

  protected Connection $database;
  protected TimeInterface $time;
  protected FloodInterface $flood;
  protected MailManagerInterface $mailManager;
  protected LanguageManagerInterface $regLanguageManager;
  protected EntityTypeManagerInterface $regEntityTypeManager;
  protected SupplierManager $supplierManager;
  protected LockBackendInterface $registrationLock;
  protected SupplierDocumentScannerInterface $documentScanner;

  public function __construct(
    Connection $database,
    TimeInterface $time,
    FloodInterface $flood,
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager,
    EntityTypeManagerInterface $entity_type_manager,
    SupplierManager $supplier_manager,
    LockBackendInterface $registration_lock,
    SupplierDocumentScannerInterface $document_scanner,
  ) {
    $this->database = $database;
    $this->time = $time;
    $this->flood = $flood;
    $this->mailManager = $mail_manager;
    $this->regLanguageManager = $language_manager;
    $this->regEntityTypeManager = $entity_type_manager;
    $this->supplierManager = $supplier_manager;
    $this->registrationLock = $registration_lock;
    $this->documentScanner = $document_scanner;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'), $container->get('datetime.time'),
      $container->get('flood'), $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('entity_type.manager'), $container->get('reg_core.supplier_manager'), $container->get('lock.persistent'), $container->get('reg_core.supplier_document_scanner'),
    );
  }

  public function getFormId(): string {
    return 'reg_supplier_register';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    if ($this->currentUser()->isAuthenticated()) {
      return ['notice' => ['#markup' => '<p>' . $this->t('You are already signed in. Sign out before registering another organization.') . '</p>']];
    }
    $form['intro'] = ['#markup' => '<div class="reg-supplier-form__intro"><h1>' . $this->t('SUPPLIER REGISTRATION') . '</h1><p>' . $this->t('Register your company to participate in procurement opportunities managed through the REG Procurement Portal.') . '</p></div>'];
    $form['company'] = ['#type' => 'fieldset', '#title' => $this->t('A. COMPANY INFORMATION')];
    $form['company']['company_name'] = ['#type' => 'textfield', '#title' => $this->t('Company / Organization Name'), '#required' => TRUE, '#maxlength' => 255];
    $form['company']['tin'] = ['#type' => 'textfield', '#title' => $this->t('TIN'), '#required' => TRUE, '#maxlength' => 64];
    $form['company']['registration_no'] = ['#type' => 'textfield', '#title' => $this->t('Business Registration Number'), '#required' => TRUE, '#maxlength' => 128];
    $form['company']['country'] = ['#type' => 'select', '#title' => $this->t('Country'), '#required' => TRUE, '#options' => ['RW' => 'Rwanda', 'BI' => 'Burundi', 'CD' => 'DR Congo', 'KE' => 'Kenya', 'TZ' => 'Tanzania', 'UG' => 'Uganda', 'OTHER' => $this->t('Other')], '#default_value' => 'RW'];
    $form['company']['province_city'] = ['#type' => 'textfield', '#title' => $this->t('Province / City'), '#maxlength' => 128];
    $form['company']['address'] = ['#type' => 'textarea', '#title' => $this->t('Physical Address'), '#required' => TRUE];
    $form['company']['website'] = ['#type' => 'url', '#title' => $this->t('Website'), '#maxlength' => 2048];

    $form['contact'] = ['#type' => 'fieldset', '#title' => $this->t('B. PRIMARY CONTACT')];
    foreach (['first_name' => 'First Name', 'last_name' => 'Last Name', 'position' => 'Position / Job Title'] as $key => $label) {
      $form['contact'][$key] = ['#type' => 'textfield', '#title' => $this->t($label), '#required' => TRUE, '#maxlength' => 100];
    }
    $form['contact']['email'] = ['#type' => 'email', '#title' => $this->t('Email Address'), '#required' => TRUE, '#maxlength' => 254];
    $form['contact']['phone'] = ['#type' => 'tel', '#title' => $this->t('Phone Number'), '#required' => TRUE, '#maxlength' => 32];
    $form['contact']['alternative_phone'] = ['#type' => 'tel', '#title' => $this->t('Alternative Phone'), '#maxlength' => 32];

    $form['procurement'] = ['#type' => 'fieldset', '#title' => $this->t('C. PROCUREMENT PROFILE')];
    $form['procurement']['supplier_type'] = ['#type' => 'select', '#title' => $this->t('Supplier Type'), '#required' => TRUE, '#options' => ['company' => $this->t('Company'), 'consultancy_firm' => $this->t('Consultancy Firm'), 'individual_consultant' => $this->t('Individual Consultant'), 'joint_venture' => $this->t('Joint Venture'), 'other' => $this->t('Other approved supplier type')]];
    $category_options = [];
    foreach ($this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree('reg_supplier_category') as $term) {
      if (in_array($term->name, ['Goods', 'Works', 'Consultancy Services', 'Non-Consultancy Services', 'Other'], TRUE)) {
        $category_options[$term->tid] = $term->name;
      }
    }
    $form['procurement']['categories'] = ['#type' => 'checkboxes', '#title' => $this->t('Procurement Categories'), '#required' => TRUE, '#options' => $category_options];

    $form['account'] = ['#type' => 'fieldset', '#title' => $this->t('D. ACCOUNT')];
    $form['account']['account_note'] = ['#markup' => '<p>' . $this->t('Your email address will be your sign-in identifier.') . '</p>'];
    $form['account']['password'] = ['#type' => 'password_confirm', '#required' => TRUE, '#description' => $this->t('Use at least 12 characters including uppercase, lowercase, a number, and a symbol.')];

    $form['documents'] = ['#type' => 'fieldset', '#title' => $this->t('E. DOCUMENTS'), '#description' => $this->t('Files are stored privately. Allowed formats: PDF, DOC, DOCX, JPG, JPEG, PNG. Maximum 25 MB each.')];
    foreach (['business_certificate' => 'Business Registration Certificate', 'tin_document' => 'TIN / Tax Registration', 'other_document' => 'Other Supporting Document'] as $key => $label) {
      $form['documents'][$key] = ['#type' => 'managed_file', '#title' => $this->t($label), '#upload_location' => 'private://supplier-registration/pending', '#upload_validators' => ['FileExtension' => ['extensions' => 'pdf doc docx jpg jpeg png'], 'FileSizeLimit' => ['fileLimit' => 26214400]]];
    }

    $form['declaration'] = ['#type' => 'fieldset', '#title' => $this->t('F. DECLARATION')];
    $form['declaration']['terms'] = ['#type' => 'checkbox', '#title' => $this->t('I confirm that the information submitted is accurate and that I am authorized to register this organization.'), '#required' => TRUE];
    $form['declaration']['privacy'] = ['#markup' => '<p><a href="' . Url::fromRoute('reg_core.supplier_privacy')->toString() . '">' . $this->t('Read the REG privacy and data-use information') . '</a>.</p>'];
    $destination = $this->safeDestination((string) $this->getRequest()->query->get('destination', ''));
    $form['destination'] = ['#type' => 'hidden', '#value' => $destination];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('REGISTER SUPPLIER'), '#button_type' => 'primary'];
    $form['signin'] = ['#markup' => '<p>' . $this->t('Already registered?') . ' <a href="' . Url::fromRoute('reg_core.supplier_login', [], ['query' => array_filter(['destination' => $destination])])->toString() . '">' . $this->t('SIGN IN →') . '</a></p>'];
    $form['#attributes']['class'][] = 'reg-supplier-form';
    $form['#attached']['library'][] = 'reg_core/supplier_portal';
    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $ip = (string) $this->getRequest()->getClientIp();
    if (!$this->flood->isAllowed('reg_core_supplier_register', 5, 3600, $ip)) {
      $form_state->setErrorByName('email', $this->t('Too many registration attempts. Please try again later.'));
      return;
    }
    $this->flood->register('reg_core_supplier_register', 3600, $ip);
    $email = mb_strtolower(trim((string) $form_state->getValue('email')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
      $form_state->setErrorByName('email', $this->t('Enter a valid email address.'));
    }
    if (user_load_by_mail($email) || user_load_by_name($email)) {
      $form_state->setErrorByName('email', $this->t('An account with this email already exists. Please sign in or use password reset.'));
    }
    foreach (['phone', 'alternative_phone'] as $field) {
      $phone = trim((string) $form_state->getValue($field));
      if ($phone !== '' && !preg_match('/^\+?[0-9][0-9 ()-]{6,20}$/', $phone)) {
        $form_state->setErrorByName($field, $this->t('Enter a valid phone number.'));
      }
    }
    $password_value = $form_state->getValue('password');
    $password = is_array($password_value) ? (string) ($password_value['pass1'] ?? '') : (string) $password_value;
    if (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
      $form_state->setErrorByName('password', $this->t('The password must be at least 12 characters and include uppercase, lowercase, a number, and a symbol.'));
    }
    $tin = $this->supplierManager->normalizeIdentity((string) $form_state->getValue('tin'));
    $registration = $this->supplierManager->normalizeIdentity((string) $form_state->getValue('registration_no'));
    if ($tin === '' || $registration === '') {
      $form_state->setErrorByName($tin === '' ? 'tin' : 'registration_no', $this->t('Enter valid organization registration information.'));
    }
    elseif ($this->supplierManager->identityExists($tin, $registration)) {
      $form_state->setErrorByName('tin', $this->t('An organization with this registration information already exists. Please sign in or contact REG Procurement for assistance.'));
    }
    $allowed_mimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png'];
    foreach (['business_certificate', 'tin_document', 'other_document'] as $field) {
      foreach (array_filter((array) $form_state->getValue($field)) as $fid) {
        $file = $this->regEntityTypeManager->getStorage('file')->load($fid);
        if (!$file instanceof FileInterface || !in_array($file->getMimeType(), $allowed_mimes, TRUE)) {
          $form_state->setErrorByName($field, $this->t('Upload only a genuine PDF, DOC, DOCX, JPG, JPEG, or PNG file.'));
        }
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tin = $this->supplierManager->normalizeIdentity((string) $form_state->getValue('tin'));
    $registration = $this->supplierManager->normalizeIdentity((string) $form_state->getValue('registration_no'));
    $locks = ['reg_supplier_tin:' . hash('sha256', $tin), 'reg_supplier_registration:' . hash('sha256', $registration)];
    sort($locks);
    $acquired = [];
    foreach ($locks as $lock) {
      if (!$this->registrationLock->acquire($lock, 15.0)) {
        foreach ($acquired as $held) $this->registrationLock->release($held);
        $this->messenger()->addError($this->t('A registration using this organization information is already being processed. Please wait and try again.'));
        $form_state->setRebuild(TRUE);
        return;
      }
      $acquired[] = $lock;
    }

    $transaction = NULL;
    $registration_data = NULL;
    try {
      $transaction = $this->database->startTransaction();
      if ($this->supplierManager->identityExists($tin, $registration)) {
        throw new \RuntimeException('Duplicate supplier identity detected during registration commit.');
      }
      $now = $this->time->getRequestTime();
      $email = mb_strtolower(trim((string) $form_state->getValue('email')));
      $password_value = $form_state->getValue('password');
      $password = is_array($password_value) ? (string) ($password_value['pass1'] ?? '') : (string) $password_value;
      $user = User::create(['name' => $email, 'mail' => $email, 'status' => 1, 'roles' => ['reg_supplier_pending'], 'field_reg_first_name' => trim((string) $form_state->getValue('first_name')), 'field_reg_last_name' => trim((string) $form_state->getValue('last_name')), 'field_reg_phone' => trim((string) $form_state->getValue('phone')), 'field_reg_email_verified' => 0, 'field_reg_supplier_terms_at' => $now]);
      $user->setPassword($password);
      $user->save();
      $file_ids = [];
      $scan_results = [];
      foreach (['business_certificate', 'tin_document', 'other_document'] as $field) {
        foreach (array_filter((array) $form_state->getValue($field)) as $fid) {
          if (($file = $this->regEntityTypeManager->getStorage('file')->load($fid)) instanceof FileInterface) {
            $file->setOwnerId((int) $user->id());
            $file->setPermanent();
            $file->save();
            $file_ids[] = (int) $fid;
            $scan_results[] = $this->documentScanner->queue($file, 'supplier_registration');
          }
        }
      }
      $org = Node::create([
        'type' => 'reg_supplier_profile', 'title' => trim((string) $form_state->getValue('company_name')), 'uid' => $user->id(), 'status' => 0,
        'field_reg_company_name' => trim((string) $form_state->getValue('company_name')), 'field_reg_tin' => $this->supplierManager->normalizeIdentity((string) $form_state->getValue('tin')), 'field_reg_registration_no' => $this->supplierManager->normalizeIdentity((string) $form_state->getValue('registration_no')),
        'field_reg_country' => $form_state->getValue('country'), 'field_reg_province_city' => trim((string) $form_state->getValue('province_city')), 'field_reg_address' => trim((string) $form_state->getValue('address')), 'field_reg_website' => $form_state->getValue('website') ? ['uri' => $form_state->getValue('website')] : NULL,
        'field_reg_contact_first_name' => trim((string) $form_state->getValue('first_name')), 'field_reg_contact_last_name' => trim((string) $form_state->getValue('last_name')), 'field_reg_contact_position' => trim((string) $form_state->getValue('position')), 'field_reg_email' => $email, 'field_reg_telephone' => trim((string) $form_state->getValue('phone')), 'field_reg_alternative_phone' => trim((string) $form_state->getValue('alternative_phone')),
        'field_reg_company_type' => $form_state->getValue('supplier_type'), 'field_reg_supplier_categories' => array_values(array_filter($form_state->getValue('categories') ?: [])), 'field_reg_private_documents' => $file_ids,
        'field_reg_supplier_status' => 'email_unverified', 'field_reg_document_scan_status' => in_array('rejected', $scan_results, TRUE) ? 'rejected' : ($file_ids && !empty(array_diff($scan_results, ['clean'])) ? 'pending' : 'clean'), 'field_reg_submitted_at' => gmdate('Y-m-d\TH:i:s', $now),
      ]);
      $org->save();
      $this->supplierManager->createMembership($org, $user, 'supplier_admin');
      $token = Crypt::randomBytesBase64(32);
      $destination = $this->safeDestination((string) $form_state->getValue('destination'));
      $this->database->insert('reg_core_supplier_verification')->fields(['token_hash' => hash('sha256', $token), 'uid' => $user->id(), 'expires' => $now + 86400, 'used' => 0, 'created' => $now, 'destination' => $destination])->execute();
      $verification_url = Url::fromRoute('reg_core.supplier_verify', ['uid' => $user->id(), 'token' => $token], ['absolute' => TRUE])->toString();
      reg_core_supplier_audit('account_registered', (int) $org->id(), (int) $user->id(), 'email_unverified');

      $registration_data = [
        'uid' => (int) $user->id(),
        'organization_id' => (int) $org->id(),
        'company' => $org->label(),
        'tin' => $tin,
        'registration_number' => $registration,
        'supplier_type' => ucfirst(str_replace('_', ' ', (string) $form_state->getValue('supplier_type'))),
        'contact_name' => trim((string) $form_state->getValue('first_name') . ' ' . (string) $form_state->getValue('last_name')),
        'email' => $email,
        'phone' => trim((string) $form_state->getValue('phone')),
        'registration_date' => gmdate('Y-m-d H:i \U\T\C', $now),
        'reference' => sprintf('REG-SUP-%06d', (int) $org->id()),
        'verification_url' => $verification_url,
        'portal_url' => Url::fromRoute('reg_core.supplier_login', [], ['absolute' => TRUE])->toString(),
        'review_url' => Url::fromRoute('reg_core.supplier_admin_review', ['supplier' => $org->id()], ['absolute' => TRUE])->toString(),
        'destination' => $destination,
      ];
      // Committing the account, profile, membership, and status is the primary
      // operation. Notifications are deliberately sent only after this point.
      unset($transaction);
      $transaction = NULL;
    }
    catch (\Throwable $exception) {
      if ($transaction !== NULL) {
        $transaction->rollBack();
      }
      \Drupal::logger('reg_core')->error('Supplier registration failed: @message', ['@message' => $exception->getMessage()]);
      $this->messenger()->addError($this->t('We could not complete your registration. Please review the form and try again, or contact REG Procurement.'));
      $form_state->setRebuild(TRUE);
    }
    finally {
      foreach ($acquired as $held) $this->registrationLock->release($held);
    }

    if ($registration_data === NULL) {
      return;
    }

    $notifications_ok = $this->sendRegistrationNotifications($registration_data);
    $settings = $this->config('reg_core.procurement_settings');
    $session = $this->getRequest()->getSession();
    $session->set('reg_supplier_registration_email', $registration_data['email']);
    $session->set('reg_supplier_return_destination', $registration_data['destination']);
    $session->set('reg_supplier_confirmation_enabled', (bool) $settings->get('send_supplier_confirmation'));
    $session->set('reg_supplier_mail_delayed', !$notifications_ok);
    if (!$notifications_ok) {
      $this->messenger()->addWarning($this->t('Your registration has been received. Email confirmation may be delayed.'));
    }
    // Drupal's generic destination handling must not skip the mandatory
    // registration-received confirmation page.
    $this->getRequest()->query->remove('destination');
    $form_state->setRedirect('reg_core.supplier_registration_success');
  }

  /** Sends configured post-commit notifications without exposing documents. */
  private function sendRegistrationNotifications(array $registration): bool {
    $config = $this->config('reg_core.procurement_settings');
    $site = $this->config('system.site');
    $sender_email = trim((string) ($config->get('notification_sender_email') ?: $site->get('mail')));
    $common = [
      'sender_name' => trim((string) ($config->get('notification_sender_name') ?: $site->get('name'))),
      'sender_email' => $sender_email,
      '_error_message' => FALSE,
    ];
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    // Account verification remains mandatory and independent from the
    // optional registration-receipt message.
    $all_sent = $this->sendRegistrationMail('supplier_verification', $registration['email'], $langcode, $common + [
      'verification_url' => $registration['verification_url'],
      'name' => $registration['contact_name'],
    ], $registration);

    if ((bool) $config->get('send_supplier_confirmation')) {
      $all_sent = $this->sendRegistrationMail('supplier_registration_confirmation', $registration['email'], $langcode, $common + [
        'company' => $registration['company'],
        'reference' => $registration['reference'],
        'status' => 'Pending Review',
        'portal_url' => $registration['portal_url'],
      ], $registration) && $all_sent;
    }

    if ((bool) $config->get('send_procurement_notification')) {
      $recipients = array_unique(array_filter([
        mb_strtolower(trim((string) $config->get('supplier_registration_notification_email'))),
        mb_strtolower(trim((string) $config->get('secondary_notification_email'))),
      ], static fn(string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== FALSE));
      if ($recipients === []) {
        \Drupal::logger('reg_core')->error('Procurement notification is enabled but no valid recipient is configured for supplier @reference.', ['@reference' => $registration['reference']]);
        $all_sent = FALSE;
      }
      foreach ($recipients as $recipient) {
        $all_sent = $this->sendRegistrationMail('supplier_registration_procurement', $recipient, $langcode, $common + [
          'company' => $registration['company'],
          'tin' => $registration['tin'],
          'registration_number' => $registration['registration_number'],
          'supplier_type' => $registration['supplier_type'],
          'contact_name' => $registration['contact_name'],
          'email' => $registration['email'],
          'phone' => $registration['phone'],
          'registration_date' => $registration['registration_date'],
          'reference' => $registration['reference'],
          'review_url' => $registration['review_url'],
        ], $registration) && $all_sent;
      }
    }

    return $all_sent;
  }

  /** Sends one notification and converts transport failures into log entries. */
  private function sendRegistrationMail(string $key, string $recipient, string $langcode, array $params, array $registration): bool {
    try {
      $mail = $this->mailManager->mail('reg_core', $key, $recipient, $langcode, $params, $params['sender_email'] ?: NULL);
      if (!empty($mail['result'])) {
        return TRUE;
      }
      \Drupal::logger('reg_core')->error('Supplier registration email @key failed for @reference and recipient @recipient.', ['@key' => $key, '@reference' => $registration['reference'], '@recipient' => $recipient]);
    }
    catch (\Throwable $exception) {
      \Drupal::logger('reg_core')->error('Supplier registration email @key threw an exception for @reference: @message', ['@key' => $key, '@reference' => $registration['reference'], '@message' => $exception->getMessage()]);
    }
    return FALSE;
  }

  private function safeDestination(string $destination): string {
    $destination = trim($destination);
    return $destination !== '' && !UrlHelper::isExternal($destination) && str_starts_with($destination, '/') && !str_starts_with($destination, '//') ? $destination : '';
  }

}
