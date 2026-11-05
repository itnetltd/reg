<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Existing procurement admin endpoints, with GET filters and core entity forms.
 */
final class ProcurementPlanAdminForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_procurement_plan_management';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $bundle = 'reg_procurement_plan'): array {
    if (!in_array($bundle, ['reg_procurement_plan', 'reg_procurement_plan_item'], TRUE)) {
      throw new \InvalidArgumentException('Unsupported plan bundle.');
    }
    $is_item = $bundle === 'reg_procurement_plan_item';
    $filters = [];
    foreach (['year', 'entity', 'department', 'method', 'review'] as $key) {
      $filters[$key] = trim((string) $this->getRequest()->query->get($key, ''));
    }
    $plans = $this->editable('reg_procurement_plan');
    $items = $this->editable('reg_procurement_plan_item');
    $years = [];
    foreach ($plans as $plan) {
      if ($year = (string) $plan->get('field_reg_financial_year')->value) {
        $years[$year] = $year;
      }
    }
    krsort($years);
    $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions('node', 'reg_procurement_plan_item');
    $methods = $definitions['field_reg_procurement_method']->getSetting('allowed_values') ?: [];
    $reviews = $definitions['field_reg_review_status']->getSetting('allowed_values') ?: [];
    $reviews = ['needs_review' => $this->t('Needs review'), 'verified' => $this->t('Verified / current')] + $reviews;
    $form['#method'] = 'get';
    $form['#token'] = FALSE;
    $form['filters'] = ['#type' => 'details', '#title' => $this->t('Filter plans and activities'), '#open' => TRUE];
    foreach (['year' => [$this->t('Financial year'), $years], 'entity' => [$this->t('Owning organization'), ['reg' => 'REG', 'eucl' => 'EUCL', 'edcl' => 'EDCL']], 'method' => [$this->t('Procurement method'), $methods], 'review' => [$this->t('Item review status'), $reviews]] as $key => [$label, $options]) {
      $form['filters'][$key] = ['#type' => 'select', '#title' => $label, '#options' => ['' => $this->t('- Any -')] + $options, '#default_value' => $filters[$key]];
    }
    $form['filters']['department'] = ['#type' => 'textfield', '#title' => $this->t('Department contains'), '#default_value' => $filters['department'], '#maxlength' => 255];
    $form['filters']['apply'] = ['#type' => 'submit', '#value' => $this->t('Apply filters')];
    $form['filters']['reset'] = Link::fromTextAndUrl($this->t('Reset'), Url::fromRoute($is_item ? 'reg_core.procurement_plan_items_admin' : 'reg_core.procurement_plans_admin'))->toRenderable();
    $add = Url::fromRoute('node.add', ['node_type' => $bundle]);
    if ($add->access()) {
      $form['add'] = Link::fromTextAndUrl($is_item ? $this->t('Add Plan Item') : $this->t('Add Plan'), $add)->toRenderable();
    }
    $matches_item = static function (NodeInterface $item) use ($filters): bool {
      return (!$filters['department'] || str_contains(mb_strtolower((string) $item->get('field_reg_department')->value), mb_strtolower($filters['department'])))
        && (!$filters['method'] || $item->get('field_reg_procurement_method')->value === $filters['method'])
        && (!$filters['review'] || $item->get('field_reg_review_status')->value === $filters['review']);
    };
    $matching_plans = [];
    foreach ($items as $item) {
      if ($matches_item($item)) {
        $matching_plans[(int) $item->get('field_reg_procurement_plan_ref')->target_id] = TRUE;
      }
    }
    $records = [];
    foreach ($is_item ? $items : $plans as $node) {
      $plan = $is_item ? ($plans[(int) $node->get('field_reg_procurement_plan_ref')->target_id] ?? $node->get('field_reg_procurement_plan_ref')->entity) : $node;
      $year = $plan ? (string) $plan->get('field_reg_financial_year')->value : '';
      if (($filters['year'] && $filters['year'] !== $year) || ($filters['entity'] && $filters['entity'] !== $node->get('field_reg_entity')->value)) {
        continue;
      }
      if ($is_item && !$matches_item($node)) {
        continue;
      }
      if (!$is_item && ($filters['department'] || $filters['method'] || $filters['review']) && !isset($matching_plans[$node->id()])) {
        continue;
      }
      $records[] = ['node' => $node, 'year' => $year];
    }
    $pager = \Drupal::service('pager.manager')->createPager(count($records), 25);
    $rows = [];
    foreach (array_slice($records, $pager->getCurrentPage() * 25, 25) as $record) {
      $node = $record['node'];
      $ops = ['edit' => ['title' => $this->t('Edit'), 'url' => $node->toUrl('edit-form')]];
      if (!$is_item && $this->currentUser()->hasPermission('approve reg procurement plans') && !in_array($node->get('field_reg_proc_plan_status')->value, ['approved', 'published'], TRUE)) {
        $ops['approve'] = ['title' => $this->t('Approve Plan'), 'url' => Url::fromRoute('reg_core.procurement_plan_approve', ['node' => $node->id()])];
      }
      $status = $is_item ? $node->get('field_reg_review_status')->value : $node->get('field_reg_proc_plan_status')->value;
      $definition = $node->getFieldDefinition($is_item ? 'field_reg_review_status' : 'field_reg_proc_plan_status');
      $status_label = ($is_item ? $reviews : ($definition->getSetting('allowed_values') ?: []))[$status] ?? $status;
      $rows[] = [
      ['data' => Link::fromTextAndUrl($node->label(), $node->toUrl('edit-form'))->toRenderable()],
        $record['year'], strtoupper((string) $node->get('field_reg_entity')->value),
        $is_item ? (string) $node->get('field_reg_plan_reference')->value : (string) $node->get('field_reg_plan_version')->value,
        $status_label, $node->isPublished() ? $this->t('Published') : $this->t('Unpublished'),
        \Drupal::service('date.formatter')->format($node->getChangedTime(), 'short'),
      ['data' => ['#type' => 'operations', '#links' => $ops]],
      ];
    }
    $form['records'] = ['#type' => 'table', '#header' => [$this->t('Title'), ['data' => $this->t('Financial year'), 'class' => ['priority-medium']], ['data' => $this->t('Organization'), 'class' => ['priority-low']], ['data' => $is_item ? $this->t('Activity number') : $this->t('Version'), 'class' => ['priority-low']], ['data' => $this->t('Approval / review'), 'class' => ['priority-medium']], ['data' => $this->t('Publication'), 'class' => ['priority-medium']], ['data' => $this->t('Last updated'), 'class' => ['priority-low']], $this->t('Operations')], '#rows' => $rows, '#empty' => $this->t('No matching records.')];
    $form['pager'] = ['#type' => 'pager'];
    $form['#cache'] = ['max-age' => 0, 'contexts' => ['user', 'url.query_args'], 'tags' => ['node_list:reg_procurement_plan', 'node_list:reg_procurement_plan_item']];
    return $form;
  }

  /**
   * Access-filter latest revisions before pagination, including unpublished drafts.
   */
  private function editable(string $bundle): array {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->sort('changed', 'DESC')->execute();
    $result = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $latest = $storage->getLatestRevisionId($node->id());
      if ($latest && $latest != $node->getRevisionId()) {
        $node = $storage->loadRevision($latest);
      }
      if ($node->access('update', $this->currentUser())) {
        $result[$node->id()] = $node;
      }
    }
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];
    foreach (['year', 'entity', 'department', 'method', 'review'] as $key) {
      if ($value = trim((string) $form_state->getValue($key, ''))) {
        $query[$key] = $value;
      }
    }
    $form_state->setRedirect(\Drupal::routeMatch()->getRouteName(), [], ['query' => $query]);
  }

}
