<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\reg_core\Service\ComplaintRouter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Public customer-support, contact, and complaint-routing pages.
 */
final class SupportController extends ControllerBase {

  public function __construct(
    private readonly ConfigFactoryInterface $regConfigFactory,
    private readonly ComplaintRouter $complaintRouter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('reg_core.complaint_router'),
    );
  }

  /**
   * Builds the main customer-support landing page.
   */
  public function contact(): array {
    $config = $this->regConfigFactory->get('reg_core.settings');
    $telephone = trim((string) $config->get('support.general_telephone'));
    return [
      '#theme' => 'reg_contact',
      '#call_center' => (string) ($config->get('support.call_center') ?: '2727'),
      '#general_phone' => $telephone,
      '#general_email' => trim((string) $config->get('support.general_email')),
      '#contact' => [
        'organization' => trim((string) ($config->get('support.organization_name') ?: 'Rwanda Energy Group')),
        'address_lines' => array_values(array_filter((array) $config->get('support.address_lines'))),
        'postal_address' => trim((string) $config->get('support.postal_address')),
        'telephone' => $telephone,
        'telephone_uri' => preg_replace('/[^0-9+]/', '', str_replace('(0)', '', $telephone)) ?: '',
        'reg_emails' => array_values(array_filter((array) $config->get('support.reg_emails'))),
        'eucl_emails' => array_values(array_filter((array) $config->get('support.eucl_emails'))),
        'edcl_emails' => array_values(array_filter((array) $config->get('support.edcl_emails'))),
      ],
      '#complaint' => $this->complaintRouter->resolve(),
      '#links' => $this->links(),
      '#attached' => ['library' => ['reg_core/customer_support']],
      '#cache' => ['tags' => ['config:reg_core.settings'], 'contexts' => ['languages:language_interface']],
    ];
  }

  /**
   * Builds a utility-dashboard view of customer services.
   */
  public function customerServices(): array {
    $links = $this->links();
    return [
      '#theme' => 'reg_customer_services',
      '#services' => [
        ['title' => $this->t('Power Outages'), 'summary' => $this->t('Check current and planned interruptions.'), 'url' => Url::fromRoute('reg_core.outages')->toString(), 'event' => 'service_click'],
        ['title' => $this->t('New Connection'), 'summary' => $this->t('Start or follow an approved connection process.'), 'url' => $links['new_connection'], 'event' => 'service_click'],
        ['title' => $this->t('Online Services'), 'summary' => $this->t('Open REG and EUCL digital services.'), 'url' => $links['online_services'], 'event' => 'service_click'],
        ['title' => $this->t('Tariffs'), 'summary' => $this->t('Find approved electricity tariff information.'), 'url' => Url::fromRoute('reg_core.faq', [], ['query' => ['category' => 'tariffs']])->toString(), 'event' => 'service_click'],
        ['title' => $this->t('Bill Estimator'), 'summary' => $this->t('Estimate costs using approved configured rates.'), 'url' => Url::fromRoute('reg_core.bill_estimator')->toString(), 'event' => 'service_click'],
        ['title' => $this->t('Report Fault / Complaint'), 'summary' => $this->t('Use an approved fault or complaint channel.'), 'url' => Url::fromRoute('reg_core.complaints')->toString(), 'event' => 'complaint_start'],
        ['title' => $this->t('Branch Locator'), 'summary' => $this->t('Find REG/EUCL service contacts across Rwanda.'), 'url' => Url::fromRoute('reg_core.branches')->toString(), 'event' => 'service_click'],
        ['title' => $this->t('FAQ Assistant'), 'summary' => $this->t('Search approved customer-support answers.'), 'url' => Url::fromRoute('reg_core.faq')->toString(), 'event' => 'service_click'],
        ['title' => $this->t('Safety'), 'summary' => $this->t('Read public electricity safety guidance.'), 'url' => '/safety', 'event' => 'service_click'],
      ],
      '#attached' => ['library' => ['reg_core/customer_support']],
      '#cache' => ['tags' => ['config:reg_core.settings'], 'contexts' => ['languages:language_interface']],
    ];
  }

  /**
   * Explains and routes faults and complaints according to configuration.
   */
  public function complaints(): array {
    return [
      '#theme' => 'reg_complaints',
      '#routing' => $this->complaintRouter->resolve(),
      '#categories' => [
        ['id' => 'electricity_fault', 'label' => $this->t('Report electricity fault')],
        ['id' => 'billing', 'label' => $this->t('Billing complaint')],
        ['id' => 'connection', 'label' => $this->t('Connection complaint')],
        ['id' => 'meter', 'label' => $this->t('Meter issue')],
        ['id' => 'service', 'label' => $this->t('Service complaint')],
        ['id' => 'other', 'label' => $this->t('Other customer-service issue')],
      ],
      '#branches_url' => Url::fromRoute('reg_core.branches')->toString(),
      '#attached' => ['library' => ['reg_core/customer_support']],
      '#cache' => ['tags' => ['config:reg_core.settings'], 'contexts' => ['languages:language_interface']],
    ];
  }

  /**
   * Returns configured and internal customer-service links.
   */
  private function links(): array {
    $config = $this->regConfigFactory->get('reg_core.settings');
    return [
      'branches' => Url::fromRoute('reg_core.branches')->toString(),
      'complaints' => Url::fromRoute('reg_core.complaints')->toString(),
      'faq' => Url::fromRoute('reg_core.faq')->toString(),
      'outages' => Url::fromRoute('reg_core.outages')->toString(),
      'customer_services' => Url::fromRoute('reg_core.customer_services')->toString(),
      'online_services' => (string) ($config->get('links.online_services') ?: Url::fromRoute('reg_core.services')->toString()),
      'new_connection' => (string) ($config->get('links.new_connection') ?: Url::fromRoute('reg_core.services')->toString()),
    ];
  }

}
