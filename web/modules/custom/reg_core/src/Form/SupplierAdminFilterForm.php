<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/** Procurement supplier-list filters. */
final class SupplierAdminFilterForm extends FormBase {
  public function getFormId(): string { return 'reg_supplier_admin_filter'; }
  public function buildForm(array $form, FormStateInterface $form_state, string $q = '', string $status = '', string $country = '', int $category = 0, array $category_options = []): array {
    $form['q'] = ['#type' => 'textfield', '#title' => $this->t('Company name or TIN'), '#default_value' => $q]; $form['status'] = ['#type' => 'select', '#title' => $this->t('Status'), '#empty_option' => $this->t('- All -'), '#default_value' => $status, '#options' => ['draft' => 'Draft', 'pending' => 'Pending Verification', 'verified' => 'Verified', 'correction' => 'Correction Requested', 'rejected' => 'Rejected', 'suspended' => 'Suspended', 'expired' => 'Expired']]; $form['country'] = ['#type' => 'select', '#title' => $this->t('Country'), '#empty_option' => $this->t('- All -'), '#default_value' => $country, '#options' => ['RW' => 'Rwanda', 'BI' => 'Burundi', 'CD' => 'DR Congo', 'KE' => 'Kenya', 'TZ' => 'Tanzania', 'UG' => 'Uganda', 'OTHER' => $this->t('Other')]]; $form['category'] = ['#type' => 'select', '#title' => $this->t('Category'), '#empty_option' => $this->t('- All -'), '#default_value' => $category, '#options' => $category_options]; $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('FILTER')]; return $form;
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void { $form_state->setRedirect('reg_core.supplier_admin', [], ['query' => array_filter(['q' => trim((string) $form_state->getValue('q')), 'status' => $form_state->getValue('status'), 'country' => $form_state->getValue('country'), 'category' => $form_state->getValue('category')])]); }
}
