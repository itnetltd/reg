<?php

namespace Drupal\reg_core\Form;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Explicit approval of the latest revision; never implicitly publishes a plan.
 */
final class ProcurementPlanApproveForm extends ConfirmFormBase {
  private NodeInterface $plan;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_procurement_plan_approve';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Approve plan @title?', ['@title' => $this->plan->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Approve Plan');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('reg_core.procurement_plans_admin');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('Approves the latest revision and records an audit revision. Publication remains a separate editorial action.');
  }

  /**
   * Checks the plan bundle, approval permission and entity edit access.
   */
  public static function access(NodeInterface $node, AccountInterface $account): AccessResult {
    return AccessResult::allowedIf($node->bundle() === 'reg_procurement_plan' && $account->hasPermission('approve reg procurement plans'))->cachePerPermissions()->andIf($node->access('update', $account, TRUE));
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $this->plan = $storage->loadRevision($storage->getLatestRevisionId($node->id()));
    $form_state->set('plan_id', $node->id());
    $form_state->set('plan_revision', $this->plan->getRevisionId());
    $form = parent::buildForm($form, $form_state);
    $form['approval_date'] = ['#type' => 'date', '#title' => $this->t('Approval date (optional)'), '#default_value' => $this->plan->get('field_reg_approval_date')->value];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $id = $form_state->get('plan_id');
    if ($storage->getLatestRevisionId($id) != $form_state->get('plan_revision')) {
      $this->messenger()->addError($this->t('The plan changed. Review its latest revision before approving.'));
      $form_state->setRedirect('reg_core.procurement_plans_admin');
      return;
    }
    $plan = $storage->loadRevision($form_state->get('plan_revision'));
    if (!self::access($plan, $this->currentUser())->isAllowed()) {
      throw new AccessDeniedHttpException();
    }
    $plan->set('field_reg_proc_plan_status', 'approved');
    $plan->set('field_reg_approval_date', $form_state->getValue('approval_date') ?: NULL);
    $plan->setNewRevision(TRUE);
    $plan->setRevisionUserId($this->currentUser()->id());
    $plan->setRevisionLogMessage('Annual procurement plan approved; publication unchanged.');
    $plan->save();
    $this->messenger()->addStatus($this->t('Plan approved. Its publication status was not changed.'));
    $form_state->setRedirect('reg_core.procurement_plans_admin');
  }

}
