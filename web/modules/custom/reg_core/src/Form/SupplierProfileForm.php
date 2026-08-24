<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Supplier\SupplierManager;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Supplier legal-entity onboarding form. */
final class SupplierProfileForm extends FormBase {
  public function __construct(private readonly EntityTypeManagerInterface $regEntityTypeManager, private readonly AccountProxyInterface $regCurrentUser, private readonly SupplierManager $supplierManager) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('entity_type.manager'), $container->get('current_user'), $container->get('reg_core.supplier_manager')); }
  public function getFormId(): string { return 'reg_supplier_profile_form'; }
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = $this->regEntityTypeManager->getStorage('user')->load($this->regCurrentUser->id());
    if (!$user instanceof UserInterface || !(bool) $user->get('field_reg_email_verified')->value) { $form['notice'] = ['#markup' => '<p>' . $this->t('Verify your email address before creating a supplier organization.') . '</p>']; return $form; }
    $org = $this->supplierManager->organization(); $v = static fn(?NodeInterface $n, string $f, mixed $fallback = ''): mixed => $n && $n->hasField($f) && !$n->get($f)->isEmpty() ? ($n->get($f)->getFieldDefinition()->getType() === 'entity_reference' ? array_column($n->get($f)->getValue(), 'target_id') : $n->get($f)->value) : $fallback;
    $form['status'] = ['#markup' => $org ? '<p class="reg-supplier-status">' . $this->t('Profile status: @status', ['@status' => ucfirst(str_replace('_', ' ', (string) $v($org, 'field_reg_supplier_status')))]) . '</p>' : ''];
    if ($org && in_array((string) $v($org, 'field_reg_supplier_status'), ['rejected', 'suspended', 'archived'], TRUE)) { $form['locked'] = ['#markup' => '<div class="messages messages--warning"><p>' . $this->t('This organization profile is not open for self-service changes. Contact REG Procurement and quote your company registration number.') . '</p></div>']; $form['#attached']['library'][] = 'reg_core/supplier_portal'; return $form; }
    $form['company_name'] = ['#type' => 'textfield', '#title' => $this->t('Legal company name'), '#required' => TRUE, '#default_value' => $v($org, 'field_reg_company_name'), '#maxlength' => 255];
    $form['trading_name'] = ['#type' => 'textfield', '#title' => $this->t('Trading name'), '#default_value' => $v($org, 'field_reg_trading_name'), '#maxlength' => 255];
    $form['country'] = ['#type' => 'select', '#title' => $this->t('Country of registration'), '#required' => TRUE, '#options' => ['RW' => 'Rwanda', 'BI' => 'Burundi', 'CD' => 'DR Congo', 'KE' => 'Kenya', 'TZ' => 'Tanzania', 'UG' => 'Uganda', 'OTHER' => $this->t('Other')], '#default_value' => $v($org, 'field_reg_country', 'RW')];
    $form['tin'] = ['#type' => 'textfield', '#title' => $this->t('Tax identification number (TIN)'), '#default_value' => $v($org, 'field_reg_tin'), '#maxlength' => 64, '#states' => ['required' => [':input[name="country"]' => ['value' => 'RW']]]];
    $form['registration_no'] = ['#type' => 'textfield', '#title' => $this->t('Company registration number'), '#required' => TRUE, '#default_value' => $v($org, 'field_reg_registration_no'), '#maxlength' => 128];
    $form['company_type'] = ['#type' => 'select', '#title' => $this->t('Supplier type'), '#required' => TRUE, '#options' => ['company' => $this->t('Company'), 'consultancy_firm' => $this->t('Consultancy Firm'), 'individual_consultant' => $this->t('Individual Consultant'), 'joint_venture' => $this->t('Joint Venture'), 'other' => $this->t('Other approved supplier type')], '#default_value' => $v($org, 'field_reg_company_type', 'company')];
    foreach (['address' => 'Registered address', 'email' => 'Official company email', 'telephone' => 'Main phone'] as $key => $label) $form[$key] = ['#type' => $key === 'address' ? 'textarea' : ($key === 'email' ? 'email' : 'tel'), '#title' => $this->t($label), '#required' => TRUE, '#default_value' => $v($org, 'field_reg_' . $key)];
    foreach (['province' => ['reg_province', 'Province'], 'district' => ['reg_district', 'District']] as $key => [$vocabulary, $label]) { $location_options = []; foreach ($this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary) as $term) $location_options[$term->tid] = $term->name; $form[$key] = ['#type' => 'select', '#title' => $this->t($label), '#empty_option' => $this->t('- Optional -'), '#options' => $location_options, '#default_value' => ($v($org, 'field_reg_' . $key . '_ref', [NULL]))[0] ?? NULL, '#states' => ['visible' => [':input[name="country"]' => ['value' => 'RW']]]]; }
    $form['website'] = ['#type' => 'url', '#title' => $this->t('Website'), '#default_value' => $org && !$org->get('field_reg_website')->isEmpty() ? $org->get('field_reg_website')->uri : ''];
    $terms = $this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree('reg_supplier_category'); $options = []; foreach ($terms as $term) $options[$term->tid] = $term->name;
    $form['categories'] = ['#type' => 'checkboxes', '#title' => $this->t('Supplier categories'), '#required' => TRUE, '#options' => $options, '#default_value' => $v($org, 'field_reg_supplier_categories', [])];
    $form['documents'] = ['#type' => 'managed_file', '#title' => $this->t('Registration and compliance documents'), '#description' => $this->t('PDF, DOC, DOCX, JPG, JPEG, or PNG. Maximum 25 MB per file. Files remain private and must pass malware scanning.'), '#multiple' => TRUE, '#upload_location' => 'private://supplier-registration/' . $this->regCurrentUser->id(), '#upload_validators' => ['FileExtension' => ['extensions' => 'pdf doc docx jpg jpeg png'], 'FileSizeLimit' => ['fileLimit' => 26214400]], '#default_value' => $v($org, 'field_reg_private_documents', [])];
    $form['actions']['draft'] = ['#type' => 'submit', '#value' => $this->t('SAVE DRAFT'), '#submit' => ['::submitForm']]; $form['actions']['review'] = ['#type' => 'submit', '#value' => $this->t('SUBMIT FOR VERIFICATION'), '#button_type' => 'primary', '#submit' => ['::submitForm']];
    $form['#attributes']['class'][] = 'reg-supplier-form'; $form['#attached']['library'][] = 'reg_core/supplier_portal'; return $form;
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $tin = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $form_state->getValue('tin')) ?? ''); $org = $this->supplierManager->organization();
    if ($form_state->getValue('country') === 'RW' && $tin === '') $form_state->setErrorByName('tin', $this->t('TIN is required for organizations registered in Rwanda.'));
    $registration = $this->supplierManager->normalizeIdentity((string) $form_state->getValue('registration_no'));
    if (($tin || $registration) && $this->supplierManager->identityExists($tin, $registration, (int) ($org?->id() ?: 0))) $form_state->setErrorByName('tin', $this->t('An organization with this registration information already exists. Please contact REG Procurement for assistance.'));
    $allowed_mimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png']; foreach (array_filter($form_state->getValue('documents') ?: []) as $fid) { $file = $this->regEntityTypeManager->getStorage('file')->load($fid); if ($file instanceof FileInterface && !in_array($file->getMimeType(), $allowed_mimes, TRUE)) $form_state->setErrorByName('documents', $this->t('A document has an unsupported file type. Upload only a genuine PDF, DOC, DOCX, JPG, JPEG, or PNG file.')); }
    if ($form_state->getTriggeringElement()['#value'] === (string) $this->t('SUBMIT FOR VERIFICATION') && !$form_state->getValue('documents')) $form_state->setErrorByName('documents', $this->t('At least one registration document is required for verification.'));
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $org = $this->supplierManager->organization(); $new = !$org; if (!$org) $org = Node::create(['type' => 'reg_supplier_profile', 'uid' => $this->regCurrentUser->id(), 'status' => 0]);
    $review = $form_state->getTriggeringElement()['#value'] === (string) $this->t('SUBMIT FOR VERIFICATION'); $documents = array_values(array_filter(array_map('intval', $form_state->getValue('documents') ?: [])));
    foreach ($documents as $fid) if (($file = $this->regEntityTypeManager->getStorage('file')->load($fid)) instanceof FileInterface) { $file->setPermanent(); $file->save(); }
    $current_status = (string) $org->get('field_reg_supplier_status')->value; $next_status = $review ? 'pending' : ($current_status === 'approved' ? 'request_update' : ($current_status ?: 'email_unverified'));
    $org->setTitle(trim((string) $form_state->getValue('company_name')))->set('field_reg_company_name', trim((string) $form_state->getValue('company_name')))->set('field_reg_trading_name', trim((string) $form_state->getValue('trading_name')))->set('field_reg_country', $form_state->getValue('country'))->set('field_reg_tin', strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string) $form_state->getValue('tin')) ?? ''))->set('field_reg_registration_no', trim((string) $form_state->getValue('registration_no')))->set('field_reg_company_type', $form_state->getValue('company_type'))->set('field_reg_address', $form_state->getValue('address'))->set('field_reg_province_ref', $form_state->getValue('province') ?: NULL)->set('field_reg_district_ref', $form_state->getValue('district') ?: NULL)->set('field_reg_email', mb_strtolower(trim((string) $form_state->getValue('email'))))->set('field_reg_telephone', trim((string) $form_state->getValue('telephone')))->set('field_reg_website', $form_state->getValue('website') ? ['uri' => $form_state->getValue('website')] : NULL)->set('field_reg_supplier_categories', array_values(array_filter($form_state->getValue('categories') ?: [])))->set('field_reg_private_documents', $documents)->set('field_reg_supplier_status', $next_status)->set('field_reg_document_scan_status', $documents ? 'pending' : NULL);
    if ($review) $org->set('field_reg_submitted_at', gmdate('Y-m-d\TH:i:s')); $org->setNewRevision(TRUE); $org->setRevisionLogMessage($review ? 'Supplier profile submitted for verification.' : 'Supplier profile draft saved.'); $org->save();
    if ($new) { $this->supplierManager->createMembership($org, $this->regEntityTypeManager->getStorage('user')->load($this->regCurrentUser->id()), 'supplier_admin'); reg_core_supplier_audit('organization_created', (int) $org->id(), (int) $this->regCurrentUser->id()); }
    if ($documents) reg_core_supplier_audit('documents_uploaded', (int) $org->id(), (int) $this->regCurrentUser->id(), count($documents) . ' private registration file(s)');
    reg_core_supplier_audit($review ? 'profile_submitted' : 'profile_saved', (int) $org->id(), (int) $this->regCurrentUser->id(), $documents ? 'Documents awaiting security scan.' : '');
    $this->messenger()->addStatus($review ? $this->t('Your profile was submitted for verification.') : $this->t('Your draft was saved.')); $form_state->setRedirect('reg_core.supplier_dashboard');
  }
}
