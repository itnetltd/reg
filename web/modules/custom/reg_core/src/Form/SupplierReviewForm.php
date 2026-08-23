<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Supplier\SupplierManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Procurement decision form with revision and audit history. */
final class SupplierReviewForm extends FormBase {
  public function __construct(private readonly SupplierManager $supplierManager, private readonly AccountProxyInterface $regCurrentUser, private readonly FileUrlGeneratorInterface $fileUrlGenerator, private readonly MailManagerInterface $mailManager, private readonly LanguageManagerInterface $regLanguageManager) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('reg_core.supplier_manager'), $container->get('current_user'), $container->get('file_url_generator'), $container->get('plugin.manager.mail'), $container->get('language_manager')); }
  public function getFormId(): string { return 'reg_supplier_review'; }
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $supplier = NULL): array {
    $form_state->set('supplier_id', $supplier->id()); $form['summary'] = ['#type' => 'details', '#title' => $this->t('Organization record'), '#open' => TRUE, 'data' => ['#theme' => 'item_list', '#items' => [$this->t('Legal name: @v', ['@v' => $supplier->label()]), $this->t('TIN: @v', ['@v' => $supplier->get('field_reg_tin')->value ?: '—']), $this->t('Registration: @v', ['@v' => $supplier->get('field_reg_registration_no')->value]), $this->t('Status: @v', ['@v' => $supplier->get('field_reg_supplier_status')->value]), $this->t('Document scan: @v', ['@v' => $supplier->get('field_reg_document_scan_status')->value ?: 'not started']), $this->t('Documents: @v private file(s)', ['@v' => $supplier->get('field_reg_private_documents')->count()])]]];
    $documents = []; foreach ($supplier->get('field_reg_private_documents')->referencedEntities() as $file) $documents[] = Link::fromTextAndUrl($file->getFilename(), Url::fromUri($this->fileUrlGenerator->generateAbsoluteString($file->getFileUri())))->toRenderable(); $form['documents'] = ['#type' => 'details', '#title' => $this->t('Private registration documents'), '#open' => TRUE, 'list' => ['#theme' => 'item_list', '#items' => $documents, '#empty' => $this->t('No documents uploaded.')]];
    $team = []; foreach ($this->supplierManager->members($supplier) as $member) $team[] = $member['name'] . ' — ' . $member['role'] . ' (' . $member['status'] . ')'; $form['team'] = ['#type' => 'details', '#title' => $this->t('Authorized team members'), 'list' => ['#theme' => 'item_list', '#items' => $team]];
    $history = []; foreach ($this->supplierManager->auditHistory($supplier) as $event) $history[] = gmdate('Y-m-d H:i', (int) $event['created']) . ' — ' . str_replace('_', ' ', $event['event']) . ($event['detail'] ? ': ' . $event['detail'] : ''); $form['history'] = ['#type' => 'details', '#title' => $this->t('Audit history'), 'list' => ['#theme' => 'item_list', '#items' => $history]];
    $form['decision'] = ['#type' => 'radios', '#title' => $this->t('Decision'), '#required' => TRUE, '#options' => ['verified' => $this->t('Verify'), 'correction' => $this->t('Request correction'), 'rejected' => $this->t('Reject'), 'suspended' => $this->t('Suspend'), 'expired' => $this->t('Mark expired')]];
    $form['reason'] = ['#type' => 'textarea', '#title' => $this->t('Decision reason'), '#description' => $this->t('Required for correction, rejection, suspension, or expiry; visible to authorized reviewers and recorded in the audit trail.')]; $form['internal_note'] = ['#type' => 'textarea', '#title' => $this->t('Internal review note'), '#default_value' => $supplier->get('field_reg_internal_review_note')->value];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('RECORD DECISION'), '#button_type' => 'primary']; return $form;
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void { $decision = $form_state->getValue('decision'); if ($decision === 'verified') { $supplier = \Drupal::entityTypeManager()->getStorage('node')->load($form_state->get('supplier_id')); if ((string) $supplier->get('field_reg_document_scan_status')->value !== 'clean') $form_state->setErrorByName('decision', $this->t('Verification is blocked until all private registration documents have passed malware scanning.')); } elseif (!trim((string) $form_state->getValue('reason'))) $form_state->setErrorByName('reason', $this->t('A reason is required for this decision.')); }
  public function submitForm(array &$form, FormStateInterface $form_state): void { $supplier = \Drupal::entityTypeManager()->getStorage('node')->load($form_state->get('supplier_id')); $decision = $form_state->getValue('decision'); $reason = trim((string) $form_state->getValue('reason')); $supplier->set('field_reg_supplier_status', $decision)->set('field_reg_review_reason', $reason)->set('field_reg_internal_review_note', trim((string) $form_state->getValue('internal_note')))->setNewRevision(TRUE); $supplier->setRevisionLogMessage('Supplier review decision: ' . $decision); $supplier->save(); reg_core_supplier_audit('review_' . $decision, (int) $supplier->id(), (int) $this->regCurrentUser->id(), $reason); $email = (string) $supplier->get('field_reg_email')->value; if ($email) $this->mailManager->mail('reg_core', 'supplier_review', $email, $this->regLanguageManager->getCurrentLanguage()->getId(), ['organization' => $supplier->label(), 'decision' => ucfirst($decision), 'reason' => $reason]); $this->messenger()->addStatus($this->t('The supplier decision was recorded.')); $form_state->setRedirect('reg_core.supplier_admin'); }
}
