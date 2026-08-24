<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/** Configures supplier-registration procurement notifications. */
final class ProcurementSettingsForm extends ConfigFormBase {

  /** {@inheritdoc} */
  public function getFormId(): string {
    return 'reg_core_procurement_settings_form';
  }

  /** {@inheritdoc} */
  protected function getEditableConfigNames(): array {
    return ['reg_core.procurement_settings'];
  }

  /** {@inheritdoc} */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reg_core.procurement_settings');

    $form['supplier_registration_notification_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Supplier Registration Notification Email'),
      '#default_value' => $config->get('supplier_registration_notification_email'),
      '#description' => $this->t('Primary Procurement recipient for new supplier-registration notifications.'),
    ];
    $form['secondary_notification_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Secondary Notification Email'),
      '#default_value' => $config->get('secondary_notification_email'),
      '#description' => $this->t('Optional second Procurement recipient.'),
    ];
    $form['notification_sender_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Notification Sender Name'),
      '#default_value' => $config->get('notification_sender_name') ?: 'REG Procurement Portal',
      '#maxlength' => 128,
      '#required' => TRUE,
    ];
    $form['notification_sender_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Notification Sender Email'),
      '#default_value' => $config->get('notification_sender_email'),
      '#description' => $this->t('Leave empty to use the Drupal site email configured under Basic site settings.'),
    ];
    $form['send_procurement_notification'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send Procurement Notification'),
      '#default_value' => (bool) $config->get('send_procurement_notification'),
    ];
    $form['send_supplier_confirmation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send Supplier Confirmation'),
      '#default_value' => $config->get('send_supplier_confirmation') !== FALSE,
    ];
    $form['require_supplier_approval'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Require Supplier Approval'),
      '#default_value' => $config->get('require_supplier_approval') !== FALSE,
      '#description' => $this->t('When enabled, verified suppliers remain pending until an authorized Procurement reviewer approves them.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /** {@inheritdoc} */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if ($form_state->getValue('send_procurement_notification') && trim((string) $form_state->getValue('supplier_registration_notification_email')) === '') {
      $form_state->setErrorByName('supplier_registration_notification_email', $this->t('Enter a valid primary notification email before enabling Procurement notifications.'));
    }
  }

  /** {@inheritdoc} */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('reg_core.procurement_settings')
      ->set('supplier_registration_notification_email', mb_strtolower(trim((string) $form_state->getValue('supplier_registration_notification_email'))))
      ->set('secondary_notification_email', mb_strtolower(trim((string) $form_state->getValue('secondary_notification_email'))))
      ->set('notification_sender_name', trim((string) $form_state->getValue('notification_sender_name')))
      ->set('notification_sender_email', mb_strtolower(trim((string) $form_state->getValue('notification_sender_email'))))
      ->set('send_procurement_notification', (bool) $form_state->getValue('send_procurement_notification'))
      ->set('send_supplier_confirmation', (bool) $form_state->getValue('send_supplier_confirmation'))
      ->set('require_supplier_approval', (bool) $form_state->getValue('require_supplier_approval'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
