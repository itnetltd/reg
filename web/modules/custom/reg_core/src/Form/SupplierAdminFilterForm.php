<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;

/** Procurement supplier-list filters. */
final class SupplierAdminFilterForm extends FormBase {
  public function getFormId(): string { return 'reg_supplier_admin_filter'; }
  public function buildForm(array $form, FormStateInterface $form_state, string $q = '', string $status = '', string $country = '', int $category = 0, array $category_options = [], string $supplier_type = '', string $registered = ''): array {
    $form['q'] = ['#type' => 'textfield', '#title' => $this->t('Company, TIN, or registration number'), '#default_value' => $q];
    $form['supplier_type'] = ['#type' => 'select', '#title' => $this->t('Supplier type'), '#empty_option' => $this->t('- All -'), '#default_value' => $supplier_type, '#options' => ['company' => 'Company', 'consultancy_firm' => 'Consultancy Firm', 'individual_consultant' => 'Individual Consultant', 'joint_venture' => 'Joint Venture', 'other' => 'Other']];
    $form['status'] = ['#type' => 'select', '#title' => $this->t('Status'), '#empty_option' => $this->t('- All -'), '#default_value' => $status, '#options' => ['email_unverified' => 'Email Not Verified', 'pending' => 'Pending Approval', 'approved' => 'Approved', 'request_update' => 'Update Requested', 'rejected' => 'Rejected', 'suspended' => 'Suspended', 'archived' => 'Archived']];
    $form['country'] = ['#type' => 'select', '#title' => $this->t('Country'), '#empty_option' => $this->t('- All -'), '#default_value' => $country, '#options' => ['RW' => 'Rwanda', 'BI' => 'Burundi', 'CD' => 'DR Congo', 'KE' => 'Kenya', 'TZ' => 'Tanzania', 'UG' => 'Uganda', 'OTHER' => $this->t('Other')]];
    $form['category'] = ['#type' => 'select', '#title' => $this->t('Category'), '#empty_option' => $this->t('- All -'), '#default_value' => $category, '#options' => $category_options];
    $form['registered'] = ['#type' => 'date', '#title' => $this->t('Registered on or after'), '#default_value' => $registered];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('FILTER')];
    return $form;
  }
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('reg_core.supplier_admin', [], ['query' => array_filter(['q' => trim((string) $form_state->getValue('q')), 'status' => $form_state->getValue('status'), 'country' => $form_state->getValue('country'), 'category' => $form_state->getValue('category'), 'supplier_type' => $form_state->getValue('supplier_type'), 'registered' => $form_state->getValue('registered')])]);
  }
}
