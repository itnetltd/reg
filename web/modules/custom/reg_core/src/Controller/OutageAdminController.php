<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filterable operator overview for manual outage announcements.
 */
final class OutageAdminController extends ControllerBase {

  public function __construct(private readonly EntityTypeManagerInterface $regEntityTypeManager, private readonly DateFormatterInterface $dateFormatter) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'), $container->get('date.formatter'));
  }

  public function overview(Request $request): array {
    $filters = ['date' => (string) $request->query->get('date', ''), 'district' => (string) $request->query->get('district', ''), 'status' => (string) $request->query->get('status', ''), 'type' => (string) $request->query->get('type', ''), 'published' => (string) $request->query->get('published', '')];
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'reg_outage')->sort('field_reg_announcement_date', 'DESC')->sort('changed', 'DESC')->range(0, 500)->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('update')) continue;
      $segments = $node->get('field_reg_outage_segments');
      $first = $segments->first();
      $last = $segments->count() ? $segments->get($segments->count() - 1) : NULL;
      $districts = [];
      foreach ($segments as $segment) $districts = array_merge($districts, array_filter(explode(',', (string) $segment->districts)));
      $district_labels = array_map(static fn($term): string => (string) $term->label(), $this->regEntityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_unique(array_map('intval', $districts))));
      $status = (string) $node->get('field_reg_outage_status')->value;
      $type = (string) $node->get('field_reg_outage_type')->value;
      $date = (string) $node->get('field_reg_announcement_date')->value;
      if (($filters['date'] !== '' && $date !== $filters['date']) || ($filters['district'] !== '' && !in_array($filters['district'], $district_labels, TRUE)) || ($filters['status'] !== '' && $status !== $filters['status']) || ($filters['type'] !== '' && $type !== $filters['type']) || ($filters['published'] !== '' && (int) $node->isPublished() !== (int) $filters['published'])) {
        continue;
      }
      $rows[] = [Link::fromTextAndUrl($node->label(), $node->toUrl('edit-form'))->toRenderable(), $date, (string) $node->get('field_reg_network_element')->value, implode(', ', $district_labels), $first?->start ?: '', $last?->end ?: '', $segments->count(), ucfirst($status), (bool) $node->get('field_reg_featured')->value ? $this->t('Yes') : $this->t('No'), $node->isPublished() ? $this->t('Yes') : $this->t('No'), $this->dateFormatter->format($node->getChangedTime(), 'short'), Link::fromTextAndUrl($this->t('Edit'), $node->toUrl('edit-form'))->toRenderable()];
    }
    $any = ['' => $this->t('- Any -')];
    $district_options = $any;
    foreach ($this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree('reg_district') as $district) {
      $district_options[$district->name] = $district->name;
    }
    return [
      'intro' => ['#type' => 'container', 'add' => Link::fromTextAndUrl($this->t('Add outage announcement'), Url::fromRoute('node.add', ['node_type' => 'reg_outage'], ['attributes' => ['class' => ['button', 'button--primary']]]))->toRenderable()],
      'filters' => ['#type' => 'container', '#prefix' => '<form method="get" class="views-exposed-form form--inline">', '#suffix' => '</form>', 'date' => ['#type' => 'date', '#title' => $this->t('Date'), '#name' => 'date', '#value' => $filters['date']], 'district' => ['#type' => 'select', '#title' => $this->t('District'), '#name' => 'district', '#value' => $filters['district'], '#options' => $district_options], 'status' => ['#type' => 'select', '#title' => $this->t('Status'), '#name' => 'status', '#value' => $filters['status'], '#options' => $any + ['scheduled' => 'Scheduled', 'ongoing' => 'Ongoing', 'restored' => 'Restored', 'postponed' => 'Postponed', 'cancelled' => 'Cancelled', 'archived' => 'Archived']], 'type' => ['#type' => 'select', '#title' => $this->t('Type'), '#name' => 'type', '#value' => $filters['type'], '#options' => $any + ['planned_maintenance' => 'Planned Maintenance', 'planned_upgrade' => 'Network Upgrade / Extension', 'emergency' => 'Emergency / Unplanned', 'network_repair' => 'Network Repair', 'other' => 'Other']], 'published' => ['#type' => 'select', '#title' => $this->t('Published'), '#name' => 'published', '#value' => $filters['published'], '#options' => $any + ['1' => 'Yes', '0' => 'No']], 'submit' => ['#type' => 'html_tag', '#tag' => 'button', '#value' => $this->t('Filter'), '#attributes' => ['type' => 'submit', 'class' => ['button']]]],
      'table' => ['#type' => 'table', '#header' => [$this->t('Title'), $this->t('Date'), $this->t('Network Element'), $this->t('District(s)'), $this->t('Start'), $this->t('End'), $this->t('Segments'), $this->t('Operational Status'), $this->t('Homepage'), $this->t('Published'), $this->t('Updated'), $this->t('Edit')], '#rows' => $rows, '#empty' => $this->t('No outage announcements match these filters.')],
      '#cache' => ['contexts' => ['url.query_args', 'user.permissions'], 'tags' => ['node_list:reg_outage', 'taxonomy_term_list:reg_district']],
    ];
  }

}
