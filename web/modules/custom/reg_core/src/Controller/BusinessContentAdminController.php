<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Provides permission-scoped content lists for each REG business area. */
final class BusinessContentAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /** Builds an update-access-filtered administration table. */
  public function listing(string $area): array {
    $areas = self::areas();
    if (!isset($areas[$area])) {
      throw new NotFoundHttpException();
    }
    [$label, $bundles] = $areas[$area];
    $node_storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundles, 'IN')
      ->sort('changed', 'DESC')
      ->range(0, 100)
      ->execute();
    $rows = [];
    foreach ($node_storage->loadMultiple($ids) as $node) {
      if (!$node->access('update', $this->currentUser())) {
        continue;
      }
      $state = $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
        ? (string) $node->get('moderation_state')->value
        : ($node->isPublished() ? 'published' : 'unpublished');
      $rows[] = [
        ['data' => $node->label()],
        ['data' => $node->type->entity?->label() ?? $node->bundle()],
        ['data' => ucfirst(str_replace('_', ' ', $state))],
        ['data' => $this->dateFormatter->format($node->getChangedTime(), 'short')],
        ['data' => ['#type' => 'link', '#title' => $this->t('Edit'), '#url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])]],
      ];
    }
    $actions = [];
    foreach ($bundles as $bundle) {
      if ($this->currentUser()->hasPermission("create $bundle content")) {
        $type = $this->regEntityTypeManager->getStorage('node_type')->load($bundle);
        $actions[] = [
          '#type' => 'link',
          '#title' => $this->t('Add @type', ['@type' => $type?->label() ?? $bundle]),
          '#url' => Url::fromRoute('node.add', ['node_type' => $bundle]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ];
      }
    }
    return [
      '#title' => $this->t('@area content', ['@area' => $label]),
      'intro' => ['#markup' => '<p>' . $this->t('Only records you are authorized to update are shown. Publishing and archiving require an approver role.') . '</p>'],
      'actions' => ['#type' => 'container', '#attributes' => ['class' => ['action-links']], 'links' => $actions],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Title'), $this->t('Type'), $this->t('Editorial state'), $this->t('Updated'), $this->t('Operations')],
        '#rows' => $rows,
        '#empty' => $this->t('No content is available in this business area.'),
      ],
      '#cache' => ['contexts' => ['user.permissions'], 'tags' => ['node_list']],
    ];
  }

  /** Returns labels and governed bundles by route argument. */
  private static function areas(): array {
    return [
      'sports' => ['Sports', ['reg_sports_update', 'reg_sports_team', 'reg_sports_player', 'reg_sports_membership', 'reg_sports_staff', 'reg_sports_fixture', 'reg_sports_standing', 'reg_sports_gallery', 'reg_sports_video']],
      'communications' => ['Communications', ['reg_news', 'reg_social_post', 'reg_publication', 'reg_public_alert', 'reg_homepage_hero', 'reg_section_hero', 'reg_video', 'reg_about_page', 'reg_value', 'reg_leader', 'reg_partner', 'reg_employee_recognition']],
      'procurement' => ['Procurement', ['reg_tender', 'reg_procurement_plan', 'reg_procurement_plan_item']],
      'hr' => ['Human resources', ['reg_job', 'reg_recruitment_plan', 'reg_recruitment_plan_item']],
      'outages' => ['Outages', ['reg_outage']],
      'customer-service' => ['Customer service', ['reg_service', 'reg_faq', 'reg_branch', 'reg_energy_tool']],
      'energy' => ['Energy', ['reg_what_we_do_page', 'reg_project', 'reg_power_plant', 'reg_fact', 'reg_access_statistic']],
    ];
  }

}
