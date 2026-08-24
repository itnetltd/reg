<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\BareHtmlPageRendererInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\reg_core\Supplier\SupplierManager;
use Drupal\user\Form\UserLoginForm;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserFloodControlInterface;
use Drupal\user\UserStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Supplier-facing wrapper around Drupal core user authentication. */
final class SupplierLoginForm extends UserLoginForm {

  protected SupplierManager $supplierManager;

  public function __construct(
    UserFloodControlInterface $user_flood_control,
    UserStorageInterface $user_storage,
    UserAuthInterface|UserAuthenticationInterface $user_auth,
    RendererInterface $renderer,
    BareHtmlPageRendererInterface $bare_html_renderer,
    SupplierManager $supplier_manager,
  ) {
    parent::__construct($user_flood_control, $user_storage, $user_auth, $renderer, $bare_html_renderer);
    $this->supplierManager = $supplier_manager;
  }

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.flood_control'),
      $container->get('entity_type.manager')->getStorage('user'),
      $container->get('user.auth'),
      $container->get('renderer'),
      $container->get('bare_html_page_renderer'),
      $container->get('reg_core.supplier_manager'),
    );
  }

  public function getFormId(): string {
    return 'reg_supplier_login_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $destination = $this->safeDestination((string) $this->getRequest()->query->get('destination')) ?: '/supplier/dashboard';

    $form['#attributes']['class'][] = 'reg-supplier-form';
    $form['name']['#type'] = 'email';
    $form['name']['#title'] = $this->t('Email address');
    $form['name']['#attributes']['autocomplete'] = 'username';
    $form['destination'] = [
      '#type' => 'hidden',
      '#value' => $destination,
    ];
    $form['actions']['submit']['#value'] = $this->t('LOG IN');
    $form['actions']['submit']['#button_type'] = 'primary';

    // Normalize the email before core authentication, then limit successful
    // credentials to active supplier memberships before core finalization.
    $form['#validate'] = [
      '::validateSupplierIdentifier',
      '::validateAuthentication',
      '::validateSupplierAccount',
      '::validateFinal',
    ];
    $form['#attached']['library'][] = 'reg_core/supplier_portal';

    return $form;
  }

  /** Resolves the supplier-facing email identifier to Drupal's username. */
  public function validateSupplierIdentifier(array &$form, FormStateInterface $form_state): void {
    $email = mb_strtolower(trim((string) $form_state->getValue('name')));
    $form_state->setValue('name', $email);
    $accounts = $this->userStorage->loadByProperties(['mail' => $email]);
    if (count($accounts) === 1) {
      $account = reset($accounts);
      $form_state->setValue('name', $account->getAccountName());
    }
  }

  /** Prevents a valid non-supplier account from entering the supplier portal. */
  public function validateSupplierAccount(array &$form, FormStateInterface $form_state): void {
    $uid = (int) $form_state->get('uid');
    if ($uid < 1) {
      return;
    }
    $account = $this->userStorage->load($uid);
    $supplier_role = $account && ($account->hasRole('reg_supplier_pending') || $account->hasRole('reg_supplier'));
    if (!$supplier_role || !$this->supplierManager->membership($uid) || !$this->supplierManager->organization($uid)) {
      // Core's final validator records this as a failed attempt and applies the
      // normal IP/account flood protection without exposing account details.
      $form_state->set('uid', FALSE);
    }
  }

  /** Keeps core flood handling while rendering errors inside the embedded form. */
  public function validateFinal(array &$form, FormStateInterface $form_state): void {
    $flood_config = $this->config('user.flood');
    if (!$form_state->get('uid')) {
      $this->userFloodControl->register('user.failed_login_ip', $flood_config->get('ip_window'));
      if ($identifier = $form_state->get('flood_control_user_identifier')) {
        $this->userFloodControl->register('user.failed_login_user', $flood_config->get('user_window'), $identifier);
      }

      if ($form_state->get('flood_control_triggered')) {
        // Core's standalone login form returns a special 403 response here.
        // This form is embedded by a controller, so keep the same block but
        // render it as an accessible inline error instead of losing it.
        $form_state->clearErrors();
        $message = $this->t('Too many failed login attempts. Try again later or use Forgot Password.');
        $form_state->setErrorByName('name', $message);
        $this->messenger()->addError($message);
      }
      else {
        $form_state->clearErrors();
        $message = $this->t('Incorrect email address or password.');
        $form_state->setErrorByName('name', $message);
        $this->messenger()->addError($message);
        $accounts = $this->userStorage->loadByProperties(['name' => $form_state->getValue('name')]);
        if ($accounts) {
          $this->logger('user')->notice('Login attempt failed for %user.', ['%user' => $form_state->getValue('name')]);
        }
        else {
          $this->logger('user')->notice('Login attempt failed from %ip.', ['%ip' => $this->getRequest()->getClientIp()]);
        }
      }
    }
    elseif (!$form_state->get('flood_control_skip_clear') && $identifier = $form_state->get('flood_control_user_identifier')) {
      $this->userFloodControl->clear('user.failed_login_user', $identifier);
    }
  }

  /** Form API validation callback retained explicitly for this login form. */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue('destination', $this->safeDestination((string) $form_state->getValue('destination')) ?: '/supplier/dashboard');
  }

  /** Establishes the normal Drupal session and honors a safe local return URL. */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $destination = $this->safeDestination((string) $form_state->getValue('destination')) ?: '/supplier/dashboard';
    $this->getRequest()->request->set('destination', $destination);
    parent::submitForm($form, $form_state);
  }

  private function safeDestination(string $destination): string {
    $destination = trim($destination);
    return $destination !== ''
      && !UrlHelper::isExternal($destination)
      && str_starts_with($destination, '/')
      && !str_starts_with($destination, '//')
      && !str_contains($destination, '\\')
      && !preg_match('/[\x00-\x1F\x7F]/', $destination)
        ? $destination
        : '';
  }

}
