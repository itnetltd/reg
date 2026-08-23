<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Component\Utility\Html;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Supplier\SupplierManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Organization-scoped REG online bid workspace. */
final class SupplierBidForm extends FormBase {
  private NodeInterface $tender;
  public function __construct(private readonly EntityTypeManagerInterface $regEntityTypeManager, private readonly AccountProxyInterface $regCurrentUser, private readonly SupplierManager $supplierManager) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('entity_type.manager'), $container->get('current_user'), $container->get('reg_core.supplier_manager')); }
  public function getFormId(): string { return 'reg_supplier_bid_form'; }
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $tender = NULL): array {
    $this->tender = $tender; $form_state->set('tender_id', $tender->id()); $org = $this->supplierManager->organization(); $bid = $this->existingBid($tender, $org);
    $form['heading'] = ['#markup' => '<h1>' . $this->t('Bid workspace') . '</h1><p><strong>' . Html::escape($tender->label()) . '</strong></p><dl><dt>' . $this->t('Tender reference') . '</dt><dd>' . Html::escape((string) $tender->get('field_reg_reference')->value) . '</dd><dt>' . $this->t('Deadline') . '</dt><dd>' . Html::escape((string) $tender->get('field_reg_closing_date')->value) . ' UTC</dd><dt>' . $this->t('Submission status') . '</dt><dd>' . ucfirst((string) ($bid?->get('field_reg_submission_status')->value ?: 'not started')) . '</dd></dl>'];
    if ((string) $org->get('field_reg_supplier_status')->value !== 'verified') $form['notice'] = ['#markup' => '<div class="messages messages--warning">' . $this->t('Your organization may prepare and save a draft while verification is pending. Final submission requires Verified status, an authorized role, and clean malware scan results.') . '</div>'];
    $form['information'] = ['#type' => 'textarea', '#title' => $this->t('Bid information'), '#default_value' => $bid?->get('field_reg_bid_information')->value, '#required' => TRUE];
    foreach (['technical' => 'Technical documents', 'financial' => 'Financial documents'] as $key => $title) $form[$key] = ['#type' => 'managed_file', '#title' => $this->t($title), '#multiple' => TRUE, '#upload_location' => 'private://supplier-bids/' . $org->id() . '/' . $tender->id() . '/' . $key, '#upload_validators' => ['FileExtension' => ['extensions' => 'pdf doc docx xls xlsx'], 'FileSizeLimit' => ['fileLimit' => 26214400]], '#default_value' => $bid ? array_column($bid->get('field_reg_' . $key . '_documents')->getValue(), 'target_id') : []];
    $form['declaration'] = ['#type' => 'checkbox', '#title' => $this->t('I confirm that I am authorized to submit this bid for the supplier organization and that the information is complete and accurate.'), '#default_value' => (bool) $bid?->get('field_reg_bid_declaration')->value];
    $form['actions']['draft'] = ['#type' => 'submit', '#value' => $this->t('SAVE DRAFT'), '#submit' => ['::submitForm']]; $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('SUBMIT FINAL BID'), '#button_type' => 'primary', '#submit' => ['::submitForm']];
    $form['#attached']['library'][] = 'reg_core/supplier_portal'; $form['#cache']['max-age'] = 0; return $form;
  }
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $tender = $this->regEntityTypeManager->getStorage('node')->load($form_state->get('tender_id')); $final = $form_state->getTriggeringElement()['#value'] === (string) $this->t('SUBMIT FINAL BID');
    if (!$this->supplierManager->canPrepareBid($tender)) $form_state->setErrorByName('actions', $this->t('This bid can no longer be prepared.'));
    if ($final && !$this->supplierManager->canSubmitBid($tender)) $form_state->setErrorByName('actions', $this->t('Final submission requires a verified organization and a Supplier Administrator or Bid Manager role.'));
    if ($final && !$form_state->getValue('declaration')) $form_state->setErrorByName('declaration', $this->t('The authorized declaration is required.'));
    if ($final && (!$form_state->getValue('technical') || !$form_state->getValue('financial'))) $form_state->setErrorByName('technical', $this->t('Technical and financial documents are required.'));
    $allowed_mimes = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']; foreach (['technical', 'financial'] as $key) foreach (array_filter($form_state->getValue($key) ?: []) as $fid) { $file = $this->regEntityTypeManager->getStorage('file')->load($fid); if ($file instanceof FileInterface && !in_array($file->getMimeType(), $allowed_mimes, TRUE)) $form_state->setErrorByName($key, $this->t('A document has an unsupported MIME type. Upload only a genuine PDF, DOC, DOCX, XLS, or XLSX file.')); }
    $org = $this->supplierManager->organization(); $bid = $this->existingBid($tender, $org); $files_changed = !$bid;
    if ($bid) foreach (['technical', 'financial'] as $key) if (array_values(array_map('intval', $form_state->getValue($key) ?: [])) !== array_values(array_map('intval', array_column($bid->get('field_reg_' . $key . '_documents')->getValue(), 'target_id')))) $files_changed = TRUE;
    if ($final && ($files_changed || (string) $bid?->get('field_reg_bid_scan_status')->value !== 'clean')) $form_state->setErrorByName('actions', $this->t('Final submission is blocked until every uploaded file has passed the configured malware scanner. Save the bid and return after scanning completes.'));
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $tender = $this->regEntityTypeManager->getStorage('node')->load($form_state->get('tender_id')); $org = $this->supplierManager->organization(); $bid = $this->existingBid($tender, $org); $new_bid = !$bid; $bid ??= Node::create(['type' => 'reg_bid', 'uid' => $this->regCurrentUser->id(), 'status' => 0, 'title' => 'Bid ' . $org->id() . ':' . $tender->id(), 'field_reg_bid_tender' => $tender->id(), 'field_reg_supplier_org' => $org->id(), 'field_reg_submission_ref' => 'REG-BID-' . gmdate('Ymd-His') . '-' . $org->id()]);
    $final = $form_state->getTriggeringElement()['#value'] === (string) $this->t('SUBMIT FINAL BID'); $changed_files = FALSE;
    foreach (['technical', 'financial'] as $key) { $fids = array_values(array_filter(array_map('intval', $form_state->getValue($key) ?: []))); $old = array_column($bid->get('field_reg_' . $key . '_documents')->getValue(), 'target_id'); if ($fids !== array_map('intval', $old)) $changed_files = TRUE; foreach ($fids as $fid) if (($file = $this->regEntityTypeManager->getStorage('file')->load($fid)) instanceof FileInterface) { $file->setPermanent(); $file->save(); } $bid->set('field_reg_' . $key . '_documents', $fids); }
    $bid->set('field_reg_bid_information', $form_state->getValue('information'))->set('field_reg_bid_declaration', (bool) $form_state->getValue('declaration'))->set('field_reg_submission_status', $final ? 'submitted' : 'draft')->set('field_reg_submitted_at', $final ? gmdate('Y-m-d\TH:i:s') : NULL); if ($changed_files) $bid->set('field_reg_bid_scan_status', 'pending'); $bid->setNewRevision(TRUE); $bid->save();
    if ($new_bid) reg_core_supplier_audit('bid_started', (int) $org->id(), (int) $this->regCurrentUser->id(), (string) $bid->get('field_reg_submission_ref')->value); reg_core_supplier_audit($final ? 'bid_submitted' : 'bid_saved', (int) $org->id(), (int) $this->regCurrentUser->id(), (string) $bid->get('field_reg_submission_ref')->value); $this->messenger()->addStatus($final ? $this->t('Your final bid was submitted.') : $this->t('Your bid draft was saved.')); $form_state->setRedirect('reg_core.supplier_bids');
  }
  private function existingBid(NodeInterface $tender, NodeInterface $org): ?NodeInterface { $s = $this->regEntityTypeManager->getStorage('node'); $ids = $s->getQuery()->accessCheck(FALSE)->condition('type', 'reg_bid')->condition('field_reg_bid_tender.target_id', $tender->id())->condition('field_reg_supplier_org.target_id', $org->id())->condition('field_reg_submission_status', ['draft', 'submitted'], 'IN')->range(0, 1)->execute(); $bid = $ids ? $s->load(reset($ids)) : NULL; return $bid instanceof NodeInterface ? $bid : NULL; }
}
