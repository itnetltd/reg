<?php

namespace Drupal\reg_core\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * CSRF-protected tender notification unsubscribe confirmation.
 */
final class TenderUnsubscribeForm extends ConfirmFormBase {

  private string $token = '';

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_core_tender_unsubscribe_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): string {
    return (string) $this->t('Unsubscribe from REG tender notifications?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription(): string {
    return (string) $this->t('Your subscription will be deactivated. You can subscribe again later with new preferences.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): string {
    return (string) $this->t('Unsubscribe');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('reg_core.tenders');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $token = NULL): array {
    $this->token = (string) $token;
    if ($this->token === '' || !preg_match('/^[A-Za-z0-9_-]{32,128}$/', $this->token)) {
      throw new NotFoundHttpException();
    }
    $exists = $this->database->select('reg_core_tender_subscription', 's')
      ->condition('token_hash', hash('sha256', $this->token))
      ->condition('active', 1)
      ->countQuery()
      ->execute()
      ->fetchField();
    if (!$exists) {
      throw new NotFoundHttpException();
    }
    $form = parent::buildForm($form, $form_state);
    $form['#attached']['library'][] = 'reg_core/public_information';
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->database->update('reg_core_tender_subscription')
      ->fields([
        'active' => 0,
        'changed' => $this->time->getRequestTime(),
      ])
      ->condition('token_hash', hash('sha256', $this->token))
      ->execute();
    $this->messenger()->addStatus($this->t('You have been unsubscribed from REG tender notifications.'));
    $form_state->setRedirect('reg_core.tenders');
  }

}
