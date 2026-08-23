<?php

namespace Drupal\reg_core\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Plan listings, operator overviews, and authenticated portal foundations. */
final class PlanPortalController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly AccountProxyInterface $regCurrentUser,
    private readonly TimeInterface $time,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('current_user'), $container->get('datetime.time'));
  }

  public function procurementPlan(Request $request): array { return $this->publicPlan('procurement', $request); }
  public function recruitmentPlan(Request $request): array { return $this->publicPlan('recruitment', $request); }
  public function procurementPlansAdmin(Request $request): array { return $this->adminOverview('reg_procurement_plan', $request); }
  public function procurementItemsAdmin(Request $request): array { return $this->adminOverview('reg_procurement_plan_item', $request); }
  public function recruitmentPlansAdmin(Request $request): array { return $this->adminOverview('reg_recruitment_plan', $request); }
  public function recruitmentItemsAdmin(Request $request): array { return $this->adminOverview('reg_recruitment_plan_item', $request); }
  public function jobsAdmin(Request $request): array { return $this->adminOverview('reg_job', $request); }

  private function publicPlan(string $kind, Request $request): array {
    $bundle = $kind === 'procurement' ? 'reg_procurement_plan_item' : 'reg_recruitment_plan_item';
    $plan_field = $kind === 'procurement' ? 'field_reg_procurement_plan_ref' : 'field_reg_recruitment_plan_ref';
    $related_field = $kind === 'procurement' ? 'field_reg_related_tender' : 'field_reg_related_job';
    $filters = ['year' => trim((string) $request->query->get('year', '')), 'entity' => trim((string) $request->query->get('entity', '')), 'category' => trim((string) $request->query->get('category', '')), 'status' => trim((string) $request->query->get('status', '')), 'department' => trim((string) $request->query->get('department', '')), 'q' => trim((string) $request->query->get('q', ''))];
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', $bundle)->condition('status', 1)->condition('field_reg_public_visibility', 1)->sort('field_reg_planned_publication', 'ASC')->execute();
    $items = []; $years = []; $departments = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) continue;
      $plan = $node->get($plan_field)->entity;
      $plan_status_field = $kind === 'procurement' ? 'field_reg_proc_plan_status' : 'field_reg_recruit_plan_status';
      if (!$plan instanceof NodeInterface || !$plan->isPublished() || !in_array((string) $plan->get($plan_status_field)->value, ['approved', 'published'], TRUE)) continue;
      $year = (string) $plan->get('field_reg_financial_year')->value;
      $entity = (string) $node->get('field_reg_entity')->value;
      $category = $kind === 'procurement' ? (string) $node->get('field_reg_proc_category_key')->value : '';
      $department = (string) $node->get('field_reg_department')->value;
      $item_status_field = $kind === 'procurement' ? 'field_reg_proc_item_status' : 'field_reg_recruit_item_status';
      $status = (string) $node->get($item_status_field)->value;
      $years[$year] = $year; if ($department !== '') $departments[$department] = $department;
      $haystack = mb_strtolower(implode(' ', [$node->label(), $node->get('field_reg_plan_reference')->value, $node->hasField('field_reg_description') ? $node->get('field_reg_description')->value : '', $department]));
      if (($filters['year'] && $filters['year'] !== $year) || ($filters['entity'] && $filters['entity'] !== $entity) || ($filters['category'] && $filters['category'] !== $category) || ($filters['status'] && $filters['status'] !== $status) || ($filters['department'] && $filters['department'] !== $department) || ($filters['q'] && !str_contains($haystack, mb_strtolower($filters['q'])))) continue;
      $related = $node->get($related_field)->entity;
      if (!$related instanceof NodeInterface) {
        $origin_field = $kind === 'procurement' ? 'field_reg_proc_plan_item' : 'field_reg_recruit_plan_item';
        $candidate_ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', $kind === 'procurement' ? 'reg_tender' : 'reg_job')->condition('status', 1)->condition($origin_field . '.target_id', $node->id())->range(0, 1)->execute();
        if ($candidate_ids) $related = $storage->load(reset($candidate_ids));
      }
      $items[] = [
        'title' => (string) $node->label(), 'reference' => (string) $node->get('field_reg_plan_reference')->value,
        'entity' => strtoupper($entity), 'category' => $kind === 'procurement' ? $this->label($node, 'field_reg_proc_category_key') : '',
        'method' => $kind === 'procurement' ? $this->label($node, 'field_reg_procurement_method') : '', 'department' => $department,
        'positions' => $kind === 'recruitment' ? (int) $node->get('field_reg_vacancies')->value : 0,
        'quarter' => $this->label($node, 'field_reg_planned_quarter'), 'status' => $this->label($node, $item_status_field),
        'related_url' => $related instanceof NodeInterface && $related->isPublished() ? Url::fromRoute($kind === 'procurement' ? 'reg_core.tender_detail' : 'reg_core.job_detail', [$kind === 'procurement' ? 'tender' : 'job' => $related->id()])->toString() : '',
      ];
    }
    krsort($years); ksort($departments);
    return ['#theme' => 'reg_plan_listing', '#kind' => $kind, '#heading' => $kind === 'procurement' ? $this->t('Annual Procurement Plan') : $this->t('Recruitment Plan'), '#description' => $kind === 'procurement' ? $this->t('Approved public procurement activities and their current publishing status.') : $this->t('Approved publicly visible recruitment positions and their current status.'), '#items' => $items, '#filters' => $filters, '#years' => $years, '#departments' => $departments, '#entity_options' => ['reg' => 'REG', 'edcl' => 'EDCL', 'eucl' => 'EUCL'], '#category_options' => ['goods' => 'Goods', 'works' => 'Works', 'consultancy' => 'Consultancy Services', 'non_consultancy' => 'Non-Consultancy Services', 'other' => 'Other'], '#status_options' => $kind === 'procurement' ? ['planned' => 'Planned', 'preparing' => 'Preparing Tender', 'published' => 'Published', 'closed' => 'Closed', 'evaluation' => 'Evaluation', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled', 'deferred' => 'Deferred'] : ['planned' => 'Planned', 'preparing' => 'Preparing', 'published' => 'Published', 'recruiting' => 'Recruiting', 'selection' => 'Selection', 'filled' => 'Filled', 'cancelled' => 'Cancelled', 'deferred' => 'Deferred'], '#attached' => ['library' => ['reg_core/plans']], '#cache' => ['contexts' => ['url.query_args', 'languages:language_interface'], 'tags' => ['node_list:' . $bundle], 'max-age' => 300]];
  }

  private function adminOverview(string $bundle, Request $request): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', $bundle)->sort('changed', 'DESC')->range(0, 500)->execute();
    $is_item = str_ends_with($bundle, '_item'); $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('update')) continue;
      $status_field = match ($bundle) {'reg_procurement_plan' => 'field_reg_proc_plan_status', 'reg_procurement_plan_item' => 'field_reg_proc_item_status', 'reg_recruitment_plan' => 'field_reg_recruit_plan_status', 'reg_recruitment_plan_item' => 'field_reg_recruit_item_status', default => 'field_reg_job_status'};
      $rows[] = [Link::fromTextAndUrl($node->label(), $node->toUrl('edit-form'))->toRenderable(), $node->hasField('field_reg_financial_year') ? (string) $node->get('field_reg_financial_year')->value : '', $node->hasField('field_reg_plan_reference') ? (string) $node->get('field_reg_plan_reference')->value : '', $node->hasField('field_reg_entity') ? strtoupper((string) $node->get('field_reg_entity')->value) : '', $this->label($node, $status_field), $node->isPublished() ? $this->t('Yes') : $this->t('No'), Link::fromTextAndUrl($this->t('Edit'), $node->toUrl('edit-form'))->toRenderable()];
    }
    $labels = ['reg_procurement_plan' => ['Annual Procurement Plans', 'ADD ANNUAL PROCUREMENT PLAN'], 'reg_procurement_plan_item' => ['Procurement Plan Items', 'ADD PLAN ITEM'], 'reg_recruitment_plan' => ['Annual Recruitment Plans', 'ADD ANNUAL RECRUITMENT PLAN'], 'reg_recruitment_plan_item' => ['Recruitment Plan Items', 'ADD PLAN ITEM'], 'reg_job' => ['Job Vacancies', 'ADD JOB VACANCY']][$bundle];
    return ['heading' => ['#markup' => '<h1>' . $labels[0] . '</h1>'], 'add' => Link::fromTextAndUrl($labels[1], Url::fromRoute('node.add', ['node_type' => $bundle], ['attributes' => ['class' => ['button', 'button--primary']]]))->toRenderable(), 'table' => ['#type' => 'table', '#header' => [$this->t('Title'), $this->t('Financial Year'), $this->t('Plan Reference'), $this->t('Entity'), $this->t('Status'), $this->t('Published'), $this->t('Edit')], '#rows' => $rows, '#empty' => $this->t('No records found.')], '#cache' => ['contexts' => ['user.permissions'], 'tags' => ['node_list:' . $bundle]]];
  }

  public function supplierTenders(): array { return $this->portal('supplier_tenders'); }
  public function supplierBids(): array { return $this->portal('supplier_bids'); }
  public function myApplications(): array { return $this->portal('applications'); }

  private function portal(string $mode): array {
    $map = ['supplier_bids' => ['reg_bid', 'field_reg_bid_tender', 'Supplier bids'], 'applications' => ['reg_job_application', 'field_reg_application_job', 'My applications']];
    $items = [];
    if (isset($map[$mode])) {
      [$bundle, $reference, $heading] = $map[$mode];
      $ids = $this->regEntityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('type', $bundle)->condition('uid', $this->regCurrentUser->id())->sort('changed', 'DESC')->execute();
      foreach ($this->regEntityTypeManager->getStorage('node')->loadMultiple($ids) as $node) $items[] = ['title' => (string) ($node->get($reference)->entity?->label() ?: $node->label()), 'reference' => (string) $node->get($bundle === 'reg_bid' ? 'field_reg_submission_ref' : 'field_reg_application_ref')->value, 'status' => $this->label($node, $bundle === 'reg_bid' ? 'field_reg_submission_status' : 'field_reg_application_status'), 'updated' => \Drupal::service('date.formatter')->format($node->getChangedTime(), 'short')];
    }
    else {
      $heading = 'Open REG-platform tenders';
      $ids = $this->regEntityTypeManager->getStorage('node')->getQuery()->accessCheck(TRUE)->condition('type', 'reg_tender')->condition('status', 1)->condition('field_reg_tender_status', 'active')->condition('field_reg_submission_method', 'reg_online')->condition('field_reg_closing_date', gmdate('Y-m-d\\TH:i:s', $this->time->getRequestTime()), '>')->sort('field_reg_closing_date', 'ASC')->execute();
      foreach ($this->regEntityTypeManager->getStorage('node')->loadMultiple($ids) as $node) $items[] = ['title' => (string) $node->label(), 'reference' => (string) $node->get('field_reg_reference')->value, 'status' => 'Open for authenticated REG submission', 'updated' => \Drupal::service('date.formatter')->format(strtotime((string) $node->get('field_reg_closing_date')->value . ' UTC'), 'short')];
    }
    return ['#theme' => 'reg_private_portal', '#heading' => $this->t($heading), '#items' => $items, '#notice' => $this->t('Secure online submission is a controlled foundation and is not production ready until security accreditation, malware scanning, sealed-bid controls, and operational approval are complete.'), '#cache' => ['contexts' => ['user'], 'max-age' => 0]];
  }

  public function bidStart(int $tender): array { return $this->startSubmission('reg_tender', $tender, 'field_reg_submission_method', 'reg_online', 'field_reg_tender_status', 'active', 'Bid submission'); }
  public function applicationStart(int $job): array { return $this->startSubmission('reg_job', $job, 'field_reg_application_method', 'reg_online', 'field_reg_job_status', 'active', 'Job application'); }

  private function startSubmission(string $bundle, int $id, string $method_field, string $method, string $status_field, string $status, string $heading): array {
    $node = $this->regEntityTypeManager->getStorage('node')->load($id);
    if (!$node instanceof NodeInterface || $node->bundle() !== $bundle || !$node->isPublished()) throw new NotFoundHttpException();
    $deadline = strtotime((string) $node->get('field_reg_closing_date')->value . ' UTC') ?: 0;
    if ((string) $node->get($method_field)->value !== $method || (string) $node->get($status_field)->value !== $status || !$deadline || $deadline <= $this->time->getRequestTime()) throw new AccessDeniedHttpException('This submission channel is not open.');
    return ['#theme' => 'reg_private_portal', '#heading' => $this->t($heading), '#items' => [], '#notice' => $this->t('This authenticated workflow is gated pending production security accreditation. No bid or application files are accepted by this screen.'), '#cache' => ['contexts' => ['user'], 'tags' => $node->getCacheTags(), 'max-age' => 0]];
  }

  private function label(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) return '';
    $allowed = $node->getFieldDefinition($field)->getFieldStorageDefinition()->getSetting('allowed_values') ?: [];
    $value = (string) $node->get($field)->value;
    return (string) ($allowed[$value] ?? $value);
  }
}
