<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Crypt;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Url;
use Drupal\reg_core\PublicInformation\PublicInformationRepositoryInterface;
use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Consent-based supplier tender notification form.
 */
final class TenderSubscriptionForm extends FormBase {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly FloodInterface $flood,
    private readonly ConfigFactoryInterface $regConfigFactory,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly MailManagerInterface $regMailManager,
    private readonly PublicInformationRepositoryInterface $repository,
    private readonly AnalyticsEventTrackerInterface $analytics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
      $container->get('flood'),
      $container->get('config.factory'),
      $container->get('language_manager'),
      $container->get('plugin.manager.mail'),
      $container->get('reg_core.public_information_repository'),
      $container->get(AnalyticsEventTrackerInterface::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_core_tender_subscription_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->regConfigFactory->get('reg_core.settings');
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Receive email notifications when approved REG Group tender opportunities are published. You can unsubscribe at any time.') . '</p>',
    ];
    $form['email'] = [
      '#type' => 'email',
      '#title' => $this->t('Email address'),
      '#required' => TRUE,
      '#maxlength' => 254,
      '#autocomplete' => 'email',
    ];
    if ($config->get('public_information.enable_tender_sms')) {
      $form['phone'] = [
        '#type' => 'tel',
        '#title' => $this->t('Phone number for approved SMS notifications'),
        '#maxlength' => 32,
        '#description' => $this->t('Optional. SMS is used only when the approved REG gateway is enabled.'),
      ];
    }
    $form['categories'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Procurement categories'),
      '#options' => $this->repository->taxonomyOptions('reg_procurement_category'),
      '#description' => $this->t('Leave all categories unchecked to receive every published category.'),
    ];
    $form['entities'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('REG companies'),
      '#options' => [
        'reg' => $this->t('Rwanda Energy Group (REG)'),
        'eucl' => $this->t('Energy Utility Corporation Limited (EUCL)'),
        'edcl' => $this->t('Energy Development Corporation Limited (EDCL)'),
      ],
      '#description' => $this->t('Leave all companies unchecked to receive opportunities from the full REG Group.'),
    ];
    $form['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Notification language'),
      '#options' => [
        'en' => $this->t('English'),
        'rw' => $this->t('Kinyarwanda'),
      ],
      '#default_value' => $this->regLanguageManager->getCurrentLanguage()->getId() === 'rw' ? 'rw' : 'en',
    ];
    $form['consent'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I consent to REG storing these preferences and contacting me about published tender opportunities.'),
      '#required' => TRUE,
    ];
    $form['website'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Leave this field empty'),
      '#attributes' => ['tabindex' => '-1', 'autocomplete' => 'off'],
      '#wrapper_attributes' => ['class' => ['visually-hidden']],
    ];
    $form['privacy'] = [
      '#markup' => '<p class="reg-pi-form-note">' . $this->t('This subscription does not create a supplier account and cannot be used to submit a bid. REG stores only the contact details, preferences, consent time, and subscription state needed for notifications.') . '</p>',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Subscribe to tender alerts'),
      '#button_type' => 'primary',
    ];
    $form['#attached']['library'][] = 'reg_core/public_information';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (trim((string) $form_state->getValue('website')) !== '') {
      $form_state->setErrorByName('email', $this->t('The subscription could not be accepted.'));
      return;
    }
    $identifier = hash('sha256', (string) $this->getRequest()->getClientIp());
    if (!$this->flood->isAllowed('reg_core.tender_subscription', 3, 3600, $identifier)) {
      $form_state->setErrorByName('email', $this->t('Too many subscription attempts were received. Please try again later.'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $identifier = hash('sha256', (string) $this->getRequest()->getClientIp());
    $this->flood->register('reg_core.tender_subscription', 3600, $identifier);

    $email = mb_strtolower(trim((string) $form_state->getValue('email')));
    $email_hash = hash('sha256', $email);
    $token = Crypt::randomBytesBase64(32);
    $now = $this->time->getRequestTime();
    $categories = array_values(array_filter($form_state->getValue('categories') ?: []));
    $entities = array_values(array_filter($form_state->getValue('entities') ?: []));
    $fields = [
      'email' => $email,
      'phone' => $this->regConfigFactory->get('reg_core.settings')->get('public_information.enable_tender_sms')
        ? trim((string) $form_state->getValue('phone'))
        : NULL,
      'categories' => json_encode($categories, JSON_THROW_ON_ERROR),
      'entities' => json_encode($entities, JSON_THROW_ON_ERROR),
      'langcode' => (string) $form_state->getValue('language'),
      'token_hash' => hash('sha256', $token),
      'active' => 1,
      'consent_at' => $now,
      'changed' => $now,
    ];
    $this->database->merge('reg_core_tender_subscription')
      ->key('email_hash', $email_hash)
      ->insertFields($fields + ['email_hash' => $email_hash, 'created' => $now])
      ->updateFields($fields)
      ->execute();
    $this->analytics->record('tender_subscription', [
      'dimension_type' => 'language',
      'dimension_value' => (string) $form_state->getValue('language'),
    ]);

    $unsubscribe_url = Url::fromRoute('reg_core.tender_unsubscribe', ['token' => $token], ['absolute' => TRUE])->toString();
    $this->regMailManager->mail(
      'reg_core',
      'tender_subscription_confirmation',
      $email,
      (string) $form_state->getValue('language'),
      ['unsubscribe_url' => $unsubscribe_url],
    );

    $this->messenger()->addStatus($this->t('Your tender alert preferences were saved. A confirmation with an unsubscribe link has been sent if site email is available.'));
    $form_state->setRedirect('reg_core.tenders_current');
  }

}
