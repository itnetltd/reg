<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Footer\FooterConfiguration;

/** Translatable footer copy; menus, social accounts and blocks retain core UI. */
final class FooterSettingsForm extends ConfigFormBase {

  public function getFormId(): string {
    return 'reg_core_footer_settings';
  }

  protected function getEditableConfigNames(): array {
    return ['reg_core.footer'];
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reg_core.footer');
    $labels = [
      'organization_name' => $this->t('Organization name'),
      'organization_description' => $this->t('Short organization description'),
      'quick_links_heading' => $this->t('Quick links heading'),
      'support_heading' => $this->t('Customer support heading'),
      'support_description' => $this->t('Customer support description'),
      'call_label' => $this->t('Call label'),
      'social_heading' => $this->t('Social media heading'),
      'language_heading' => $this->t('Language heading'),
      'copyright' => $this->t('Copyright wording'),
    ];
    $form['intro'] = ['#markup' => '<p>' . $this->t('Use the Translate tab for Kinyarwanda copy. Placed footer blocks override the corresponding settings and menus. Manage login visibility using the existing User account menu block and its visibility settings in Footer block layout.') . '</p>'];
    foreach ($labels as $key => $label) {
      $form[$key] = [
        '#type' => str_ends_with($key, 'description') ? 'textarea' : 'textfield',
        '#title' => $label,
        '#default_value' => $config->get($key) ?? FooterConfiguration::DEFAULTS[$key],
        '#maxlength' => 512,
      ];
    }
    $form['copyright']['#description'] = $this->t('Use @year for the automatically updated current year. Plain text only.');
    $form['call_center_settings'] = ['#type' => 'link', '#title' => $this->t('Manage the shared call center number in REG website settings'), '#url' => Url::fromRoute('reg_core.settings')];
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('reg_core.footer');
    foreach (array_keys(FooterConfiguration::DEFAULTS) as $key) {
      $config->set($key, trim((string) $form_state->getValue($key)));
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
