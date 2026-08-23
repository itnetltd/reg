<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configures official service links and approved calculator assumptions.
 */
final class RegSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_core_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['reg_core.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('reg_core.settings');

    $form['support'] = [
      '#type' => 'details',
      '#title' => $this->t('Customer support'),
      '#open' => TRUE,
    ];
    $form['support']['call_center'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Call center number'),
      '#default_value' => $config->get('support.call_center') ?: '2727',
      '#required' => TRUE,
    ];
    $form['support']['complaint_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Official complaint URL'),
      '#default_value' => $config->get('support.complaint_url'),
    ];
    $form['support']['complaint_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Complaint routing mode'),
      '#options' => [
        'external' => $this->t('External approved service'),
        'api' => $this->t('Approved source-system API'),
        'cms' => $this->t('Private Drupal storage (policy approval required)'),
        'disabled' => $this->t('Contact channels only'),
      ],
      '#default_value' => $config->get('support.complaint_mode') ?: 'external',
      '#description' => $this->t('External is the safe default. API and CMS modes remain inactive until explicitly approved and enabled.'),
    ];
    $form['support']['fault_reporting_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Official electricity fault-reporting URL'),
      '#default_value' => $config->get('support.fault_reporting_url'),
    ];
    $form['support']['complaint_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Complaint support email'),
      '#default_value' => $config->get('support.complaint_email'),
    ];
    $form['support']['complaint_api_endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Future approved complaint API endpoint'),
      '#default_value' => $config->get('support.complaint_api_endpoint'),
      '#description' => $this->t('Placeholder only. No public form transmits data until the integration is approved and enabled.'),
    ];
    $form['support']['complaint_integration_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable an approved complaint integration'),
      '#default_value' => (bool) $config->get('support.complaint_integration_enabled'),
    ];
    $form['support']['general_email'] = [
      '#type' => 'email',
      '#title' => $this->t('General contact email'),
      '#default_value' => $config->get('support.general_email'),
    ];
    $form['support']['general_telephone'] = [
      '#type' => 'textfield',
      '#title' => $this->t('General contact telephone'),
      '#default_value' => $config->get('support.general_telephone'),
    ];
    $form['support']['organization_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Contact organization name'),
      '#default_value' => $config->get('support.organization_name'),
    ];
    $form['support']['address_lines'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Head office address'),
      '#default_value' => implode("\n", (array) $config->get('support.address_lines')),
      '#description' => $this->t('Enter one approved address line per row.'),
    ];
    $form['support']['postal_address'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Postal address'),
      '#default_value' => $config->get('support.postal_address'),
    ];
    foreach ([
      'reg_emails' => $this->t('REG email addresses'),
      'eucl_emails' => $this->t('EUCL email addresses'),
      'edcl_emails' => $this->t('EDCL email addresses'),
    ] as $key => $label) {
      $form['support'][$key] = [
        '#type' => 'textarea',
        '#title' => $label,
        '#default_value' => implode("\n", (array) $config->get('support.' . $key)),
        '#description' => $this->t('Enter one approved email address per row.'),
        '#rows' => 2,
      ];
    }
    $form['support']['contact_source_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Official contact source URL'),
      '#default_value' => $config->get('support.contact_source_url'),
    ];

    $form['branches'] = [
      '#type' => 'details',
      '#title' => $this->t('Branch Locator'),
      '#open' => FALSE,
    ];
    $form['branches']['branch_map_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Map provider adapter'),
      '#options' => [
        'none' => $this->t('Provider-neutral map data only'),
        'leaflet' => $this->t('Leaflet when an approved tile source is configured'),
      ],
      '#default_value' => $config->get('branches.map_provider') ?: 'none',
      '#description' => $this->t('No commercial map provider is loaded by this setting alone.'),
    ];
    $form['branches']['branch_results_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Branches per page'),
      '#default_value' => $config->get('branches.results_per_page') ?: 12,
      '#min' => 6,
      '#max' => 48,
      '#required' => TRUE,
    ];

    $form['links'] = [
      '#type' => 'details',
      '#title' => $this->t('Official service links'),
      '#open' => TRUE,
      '#description' => $this->t('Use approved REG/EUCL source-system URLs. The Drupal website should not duplicate transactional systems.'),
    ];
    foreach ([
      'online_services' => $this->t('Online services URL'),
      'new_connection' => $this->t('New connection URL'),
      'payments' => $this->t('Payments URL'),
      'gis' => $this->t('GIS portal URL'),
      'outage_source' => $this->t('Official outage source URL'),
      'recruitment' => $this->t('Recruitment system URL'),
      'procurement' => $this->t('Procurement system URL'),
      'branch_contacts' => $this->t('Branch contacts URL'),
    ] as $key => $label) {
      $form['links'][$key] = [
        '#type' => 'url',
        '#title' => $label,
        '#default_value' => $config->get('links.' . $key),
      ];
    }

    $form['dms'] = [
      '#type' => 'details',
      '#title' => $this->t('Future GE Distribution Management System adapter'),
      '#open' => FALSE,
      '#description' => $this->t('Inactive future integration settings. Published outage information currently comes only from manually approved Drupal announcements.'),
    ];

    $form['outages'] = [
      '#type' => 'details',
      '#title' => $this->t('Outage announcement defaults'),
      '#open' => TRUE,
    ];
    $form['outages']['outage_default_safety_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default safety message'),
      '#default_value' => $config->get('outages.default_safety_message'),
      '#required' => TRUE,
    ];
    $form['outages']['outage_default_customer_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Default customer-support message'),
      '#default_value' => $config->get('outages.default_customer_message'),
      '#required' => TRUE,
    ];
    $form['dms']['driver'] = [
      '#type' => 'select',
      '#title' => $this->t('Data source'),
      '#options' => [
        'mock' => $this->t('Development mock data'),
        'ge' => $this->t('GE DMS API'),
      ],
      '#default_value' => $config->get('dms.driver') ?: 'mock',
      '#description' => $this->t('Keep mock data selected until the GE endpoint and server-side authentication are approved.'),
    ];
    $form['dms']['api_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API base URL'),
      '#default_value' => $config->get('dms.api_base_url'),
      '#description' => $this->t('The approved server-side outage endpoint. The public browser never calls this URL directly.'),
    ];
    $form['dms']['outages_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Outages endpoint path'),
      '#default_value' => $config->get('dms.outages_path') ?: '/outages',
      '#description' => $this->t('Path appended to the API base URL, for example /outages.'),
    ];
    $form['dms']['authentication_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Authentication method'),
      '#options' => [
        'none' => $this->t('None'),
        'api_key' => $this->t('X-API-Key header'),
        'bearer' => $this->t('Bearer token'),
        'client_id' => $this->t('X-Client-Id header'),
      ],
      '#default_value' => $config->get('dms.authentication_method') ?: 'none',
    ];
    $form['dms']['client_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client ID placeholder'),
      '#default_value' => $config->get('dms.client_id'),
      '#description' => $this->t('Leave empty in exported configuration. Prefer a settings.php or environment override in production.'),
    ];
    $form['dms']['credential_environment_variable'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Credential environment variable'),
      '#default_value' => $config->get('dms.credential_environment_variable') ?: 'REG_GE_DMS_CREDENTIAL',
      '#pattern' => '[A-Z_][A-Z0-9_]*',
      '#required' => TRUE,
      '#description' => $this->t('Name only. Store the API key or bearer token in this server environment variable; Drupal configuration never stores the credential value.'),
    ];
    foreach ([
      'request_timeout' => [$this->t('Request timeout (seconds)'), 1, 120, 10],
      'synchronization_interval' => [$this->t('Synchronization interval (seconds)'), 60, 86400, 300],
      'cache_lifetime' => [$this->t('Cache lifetime (seconds)'), 30, 86400, 300],
    ] as $key => [$label, $min, $max, $default]) {
      $form['dms'][$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $config->get('dms.' . $key) ?? $default,
        '#min' => $min,
        '#max' => $max,
        '#required' => TRUE,
      ];
    }

    $form['public_information'] = [
      '#type' => 'details',
      '#title' => $this->t('Public Information Hub'),
      '#open' => TRUE,
      '#description' => $this->t('Drupal publishes approved notices and files. Bid submission and job applications remain in their authoritative external systems unless an approved mode is configured.'),
    ];
    $form['public_information']['enable_tender_submission'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show the approved procurement-system submission handoff'),
      '#default_value' => (bool) $config->get('public_information.enable_tender_submission'),
      '#description' => $this->t('Disabled by default. Drupal never accepts or stores bids.'),
    ];
    $form['public_information']['job_application_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Job application mode'),
      '#options' => [
        'external' => $this->t('External recruitment system'),
        'cms' => $this->t('CMS handoff (no applicant database)'),
        'disabled' => $this->t('Applications disabled'),
      ],
      '#default_value' => $config->get('public_information.job_application_mode') ?: 'external',
    ];
    $form['public_information']['enable_tender_sms'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Collect supplier phone numbers for an approved SMS gateway'),
      '#default_value' => (bool) $config->get('public_information.enable_tender_sms'),
      '#description' => $this->t('Keep disabled until REG approves and configures an SMS integration.'),
    ];
    $form['public_information']['allow_zip_uploads'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow ZIP files in official document Media'),
      '#default_value' => (bool) $config->get('public_information.allow_zip_uploads'),
      '#description' => $this->t('Enable only when the document security policy permits ZIP archives.'),
    ];
    $form['public_information']['results_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Results per listing page'),
      '#default_value' => $config->get('public_information.results_per_page') ?: 12,
      '#min' => 6,
      '#max' => 48,
      '#required' => TRUE,
    ];

    $form['sports'] = [
      '#type' => 'details',
      '#title' => $this->t('REG Sports Portal'),
      '#open' => FALSE,
      '#description' => $this->t('Sports results and standings are CMS-managed until REG approves a trusted league or statistics API. Live status is never inferred automatically.'),
    ];
    $form['sports']['sports_data_source'] = [
      '#type' => 'select',
      '#title' => $this->t('Sports data source'),
      '#options' => ['cms' => $this->t('Sports Editor managed (Phase One)')],
      '#default_value' => $config->get('sports.data_source') ?: 'cms',
      '#disabled' => TRUE,
    ];
    $form['sports']['statistics_api_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Future approved statistics API base URL'),
      '#default_value' => $config->get('sports.statistics_api_base_url'),
      '#description' => $this->t('Placeholder only. Leave empty until an authoritative API and integration contract are approved.'),
    ];
    $form['sports']['social_embed_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable approved sports social embeds'),
      '#default_value' => (bool) $config->get('sports.social_embed_enabled'),
      '#description' => $this->t('Disabled by default. Manual official links continue to work without third-party scripts.'),
    ];
    $form['sports']['sports_results_per_page'] = [
      '#type' => 'number',
      '#title' => $this->t('Sports results per page'),
      '#default_value' => $config->get('sports.results_per_page') ?: 12,
      '#min' => 6,
      '#max' => 48,
      '#required' => TRUE,
    ];

    $form['analytics'] = [
      '#type' => 'details',
      '#title' => $this->t('Analytics and aggregate dashboards'),
      '#open' => FALSE,
      '#description' => $this->t('Local application counters contain no visitor identifiers. External provider scripts remain disabled until REG approves a provider, privacy policy, consent behavior, and production identifier.'),
    ];
    $form['analytics']['analytics_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable an approved external analytics adapter'),
      '#default_value' => (bool) $config->get('analytics.enabled'),
    ];
    $form['analytics']['analytics_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Analytics provider'),
      '#options' => [
        'none' => $this->t('None'),
        'ga4' => $this->t('Google Analytics 4'),
        'matomo' => $this->t('Matomo'),
        'other' => $this->t('Other REG-approved adapter'),
      ],
      '#default_value' => $config->get('analytics.provider') ?: 'none',
    ];
    $form['analytics']['analytics_measurement_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Measurement or site identifier placeholder'),
      '#default_value' => $config->get('analytics.measurement_id'),
      '#maxlength' => 80,
      '#pattern' => '[A-Za-z0-9._:-]{0,80}',
      '#description' => $this->t('No production tracking identifier is exported with the codebase. Configure it through protected environment-specific configuration.'),
    ];
    $form['analytics']['analytics_privacy_mode'] = [
      '#type' => 'select',
      '#title' => $this->t('Privacy mode'),
      '#options' => [
        'strict' => $this->t('Strict aggregate mode'),
        'consent_required' => $this->t('External provider requires consent'),
      ],
      '#default_value' => $config->get('analytics.privacy_mode') ?: 'strict',
    ];
    $form['analytics']['local_aggregate_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable privacy-preserving local application aggregates'),
      '#default_value' => $config->get('analytics.local_aggregate_enabled') !== FALSE,
      '#description' => $this->t('Stores only daily event totals, controlled slugs, optional content IDs, and language. It never stores visitor IDs, IP addresses, names, contact details, account numbers, or complaint text.'),
    ];
    $form['analytics']['dashboard_cache_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('Dashboard cache lifetime (seconds)'),
      '#default_value' => $config->get('analytics.dashboard_cache_seconds') ?: 300,
      '#min' => 60,
      '#max' => 3600,
      '#required' => TRUE,
    ];

    $form['governance'] = [
      '#type' => 'details',
      '#title' => $this->t('Content review periods'),
      '#open' => FALSE,
      '#description' => $this->t('These configurable thresholds support governance reporting; they do not unpublish content automatically.'),
    ];
    foreach ([
      'branch_review_days' => [$this->t('Branch review period (days)'), 180],
      'faq_review_days' => [$this->t('FAQ review period (days)'), 180],
      'service_review_days' => [$this->t('Service-page review period (days)'), 180],
    ] as $key => [$label, $default]) {
      $form['governance'][$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $config->get('governance.' . $key) ?: $default,
        '#min' => 1,
        '#max' => 1825,
        '#required' => TRUE,
      ];
    }

    $form['tariffs'] = [
      '#type' => 'details',
      '#title' => $this->t('Approved bill-estimator assumptions'),
      '#open' => TRUE,
      '#description' => $this->t('Enter only REG/RURA-approved indicative rates. Leave rates at zero until validated.'),
    ];
    foreach ([
      'residential' => $this->t('Residential rate per kWh (RWF)'),
      'commercial' => $this->t('Commercial rate per kWh (RWF)'),
      'industrial' => $this->t('Industrial rate per kWh (RWF)'),
      'fixed_charge' => $this->t('Indicative fixed charge (RWF)'),
    ] as $key => $label) {
      $form['tariffs'][$key] = [
        '#type' => 'number',
        '#title' => $label,
        '#default_value' => $config->get('tariffs.' . $key) ?? 0,
        '#min' => 0,
        '#step' => '0.01',
      ];
    }
    $form['tariffs']['tariffs_updated'] = [
      '#type' => 'date',
      '#title' => $this->t('Tariff assumptions last validated'),
      '#default_value' => $config->get('tariffs.updated'),
    ];

    $form['carbon'] = [
      '#type' => 'details',
      '#title' => $this->t('Approved carbon-calculator assumptions'),
      '#open' => TRUE,
    ];
    $form['carbon']['emission_factor'] = [
      '#type' => 'number',
      '#title' => $this->t('Electricity emission factor (kg CO₂e per kWh)'),
      '#default_value' => $config->get('carbon.emission_factor') ?? 0,
      '#min' => 0,
      '#step' => '0.0001',
      '#description' => $this->t('Leave at zero until REG validates the factor and sustainability message.'),
    ];
    $form['carbon']['carbon_updated'] = [
      '#type' => 'date',
      '#title' => $this->t('Emission factor last validated'),
      '#default_value' => $config->get('carbon.updated'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $config = $this->config('reg_core.settings');
    $config
      ->set('support.call_center', $form_state->getValue('call_center'))
      ->set('support.complaint_mode', $form_state->getValue('complaint_mode'))
      ->set('support.complaint_url', $form_state->getValue('complaint_url'))
      ->set('support.fault_reporting_url', $form_state->getValue('fault_reporting_url'))
      ->set('support.complaint_email', $form_state->getValue('complaint_email'))
      ->set('support.complaint_api_endpoint', $form_state->getValue('complaint_api_endpoint'))
      ->set('support.complaint_integration_enabled', (bool) $form_state->getValue('complaint_integration_enabled'))
      ->set('support.general_email', $form_state->getValue('general_email'))
      ->set('support.general_telephone', $form_state->getValue('general_telephone'))
      ->set('support.organization_name', trim((string) $form_state->getValue('organization_name')))
      ->set('support.address_lines', $this->lines($form_state->getValue('address_lines')))
      ->set('support.postal_address', trim((string) $form_state->getValue('postal_address')))
      ->set('support.reg_emails', $this->lines($form_state->getValue('reg_emails')))
      ->set('support.eucl_emails', $this->lines($form_state->getValue('eucl_emails')))
      ->set('support.edcl_emails', $this->lines($form_state->getValue('edcl_emails')))
      ->set('support.contact_source_url', trim((string) $form_state->getValue('contact_source_url')))
      ->set('branches.map_provider', $form_state->getValue('branch_map_provider'))
      ->set('branches.results_per_page', (int) $form_state->getValue('branch_results_per_page'))
      ->set('links.online_services', $form_state->getValue('online_services'))
      ->set('links.new_connection', $form_state->getValue('new_connection'))
      ->set('links.payments', $form_state->getValue('payments'))
      ->set('links.gis', $form_state->getValue('gis'))
      ->set('links.outage_source', $form_state->getValue('outage_source'))
      ->set('links.recruitment', $form_state->getValue('recruitment'))
      ->set('links.procurement', $form_state->getValue('procurement'))
      ->set('links.branch_contacts', $form_state->getValue('branch_contacts'))
      ->set('dms.driver', $form_state->getValue('driver'))
      ->set('dms.api_base_url', $form_state->getValue('api_base_url'))
      ->set('dms.outages_path', $form_state->getValue('outages_path'))
      ->set('dms.authentication_method', $form_state->getValue('authentication_method'))
      ->set('dms.client_id', $form_state->getValue('client_id'))
      ->set('dms.credential_environment_variable', $form_state->getValue('credential_environment_variable'))
      ->set('dms.request_timeout', (int) $form_state->getValue('request_timeout'))
      ->set('dms.synchronization_interval', (int) $form_state->getValue('synchronization_interval'))
      ->set('dms.cache_lifetime', (int) $form_state->getValue('cache_lifetime'))
      ->set('outages.source', 'manual')
      ->set('outages.default_safety_message', trim((string) $form_state->getValue('outage_default_safety_message')))
      ->set('outages.default_customer_message', trim((string) $form_state->getValue('outage_default_customer_message')))
      ->set('public_information.enable_tender_submission', (bool) $form_state->getValue('enable_tender_submission'))
      ->set('public_information.job_application_mode', $form_state->getValue('job_application_mode'))
      ->set('public_information.enable_tender_sms', (bool) $form_state->getValue('enable_tender_sms'))
      ->set('public_information.allow_zip_uploads', (bool) $form_state->getValue('allow_zip_uploads'))
      ->set('public_information.results_per_page', (int) $form_state->getValue('results_per_page'))
      ->set('sports.data_source', 'cms')
      ->set('sports.statistics_api_base_url', $form_state->getValue('statistics_api_base_url'))
      ->set('sports.social_embed_enabled', (bool) $form_state->getValue('social_embed_enabled'))
      ->set('sports.results_per_page', (int) $form_state->getValue('sports_results_per_page'))
      ->set('analytics.enabled', (bool) $form_state->getValue('analytics_enabled'))
      ->set('analytics.provider', $form_state->getValue('analytics_provider'))
      ->set('analytics.measurement_id', trim((string) $form_state->getValue('analytics_measurement_id')))
      ->set('analytics.privacy_mode', $form_state->getValue('analytics_privacy_mode'))
      ->set('analytics.local_aggregate_enabled', (bool) $form_state->getValue('local_aggregate_enabled'))
      ->set('analytics.dashboard_cache_seconds', (int) $form_state->getValue('dashboard_cache_seconds'))
      ->set('governance.branch_review_days', (int) $form_state->getValue('branch_review_days'))
      ->set('governance.faq_review_days', (int) $form_state->getValue('faq_review_days'))
      ->set('governance.service_review_days', (int) $form_state->getValue('service_review_days'))
      ->set('tariffs.residential', (float) $form_state->getValue('residential'))
      ->set('tariffs.commercial', (float) $form_state->getValue('commercial'))
      ->set('tariffs.industrial', (float) $form_state->getValue('industrial'))
      ->set('tariffs.fixed_charge', (float) $form_state->getValue('fixed_charge'))
      ->set('tariffs.updated', $form_state->getValue('tariffs_updated'))
      ->set('carbon.emission_factor', (float) $form_state->getValue('emission_factor'))
      ->set('carbon.updated', $form_state->getValue('carbon_updated'));

    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Normalizes one-value-per-line settings.
   */
  private function lines(mixed $value): array {
    return array_values(array_filter(array_map(
      static fn(string $line): string => trim($line),
      preg_split('/\R+/', trim((string) $value)) ?: [],
    )));
  }

}
