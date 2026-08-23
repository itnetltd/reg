<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Editor-friendly management page for manually maintained energy facts.
 */
final class HomepageStatisticsAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('request_stack'),
    );
  }

  /**
   * Lists and filters current and historical energy facts.
   */
  public function overview(): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $request = $this->requestStack->getCurrentRequest();
    $category = (string) $request->query->get('category', '');
    $period = (string) $request->query->get('period', '');
    $published = (string) $request->query->get('published', '');
    $featured = (string) $request->query->get('featured', '');

    $all_ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_fact')
      ->sort('field_reg_reporting_period', 'DESC')
      ->sort('changed', 'DESC')
      ->execute();
    $all_facts = $storage->loadMultiple($all_ids);

    $periods = [];
    foreach ($all_facts as $fact) {
      $value = trim((string) $fact->get('field_reg_reporting_period')->value);
      if ($value !== '') {
        $periods[$value] = $value;
      }
    }
    ksort($periods, SORT_NATURAL | SORT_FLAG_CASE);

    $query = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_fact')
      ->sort('field_reg_reporting_period', 'DESC')
      ->sort('changed', 'DESC');
    if ($category !== '') {
      $query->condition('field_reg_fact_category', $category);
    }
    if ($period !== '') {
      $query->condition('field_reg_reporting_period', $period);
    }
    if ($published !== '') {
      $query->condition('status', (int) $published);
    }
    if ($featured !== '') {
      $query->condition('field_reg_show_homepage', (int) $featured);
    }

    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $node) {
      $value = trim((string) $node->get('field_reg_fact_value')->value);
      $unit = trim((string) $node->get('field_reg_fact_unit')->value);
      $rows[] = [
        Link::fromTextAndUrl($node->label(), $node->toUrl('edit-form'))->toRenderable(),
        ucfirst((string) $node->get('field_reg_fact_category')->value),
        $value,
        $unit,
        (string) $node->get('field_reg_reporting_period')->value,
        (bool) $node->get('field_reg_show_homepage')->value ? $this->t('Yes') : $this->t('No'),
        $node->isPublished() ? $this->t('Published') : $this->t('Unpublished'),
        $this->dateFormatter->format((int) $node->getChangedTime(), 'short'),
        Link::fromTextAndUrl($this->t('Edit'), $node->toUrl('edit-form'))->toRenderable(),
      ];
    }

    $actions = [
      Link::fromTextAndUrl($this->t('Add energy fact'), Url::fromRoute('node.add', ['node_type' => 'reg_fact'], ['attributes' => ['class' => ['button', 'button--primary']]]))->toRenderable(),
    ];
    if ($this->currentUser()->hasPermission('administer blocks')) {
      $actions[] = Link::fromTextAndUrl($this->t('Configure REG At a Glance'), Url::fromRoute('entity.block.edit_form', ['block' => 'reg_homepage_statistics'], ['attributes' => ['class' => ['button']]]))->toRenderable();
    }

    $options = ['' => $this->t('- Any -')];
    $yes_no = ['' => $this->t('- Any -'), '1' => $this->t('Yes'), '0' => $this->t('No')];

    return [
      'intro' => [
        '#type' => 'container',
        'copy' => ['#markup' => '<p>' . $this->t('Maintain approved generation, transmission and electricity-access facts manually. Publishing a newer featured headline preserves older reporting periods as history.') . '</p>'],
        'actions' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['action-links']],
          'links' => $actions,
        ],
      ],
      'filters' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['views-exposed-form', 'form--inline', 'clearfix']],
        '#prefix' => '<form method="get">',
        '#suffix' => '</form>',
        'category' => [
          '#type' => 'select',
          '#title' => $this->t('Category'),
          '#name' => 'category',
          '#value' => $category,
          '#options' => $options + ['generation' => $this->t('Generation'), 'transmission' => $this->t('Transmission'), 'access' => $this->t('Electricity access'), 'other' => $this->t('Other')],
        ],
        'period' => [
          '#type' => 'select',
          '#title' => $this->t('Reporting period'),
          '#name' => 'period',
          '#value' => $period,
          '#options' => $options + $periods,
        ],
        'published' => [
          '#type' => 'select',
          '#title' => $this->t('Published'),
          '#name' => 'published',
          '#value' => $published,
          '#options' => $yes_no,
        ],
        'featured' => [
          '#type' => 'select',
          '#title' => $this->t('Homepage featured'),
          '#name' => 'featured',
          '#value' => $featured,
          '#options' => $yes_no,
        ],
        'submit' => [
          '#type' => 'html_tag',
          '#tag' => 'button',
          '#value' => $this->t('Filter'),
          '#attributes' => ['type' => 'submit', 'class' => ['button']],
        ],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Metric'),
          $this->t('Category'),
          $this->t('Value'),
          $this->t('Unit'),
          $this->t('Reporting period'),
          $this->t('Homepage featured'),
          $this->t('Status'),
          $this->t('Updated'),
          $this->t('Operations'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No energy facts match these filters.'),
      ],
      '#cache' => [
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['node_list:reg_fact'],
      ],
    ];
  }

}
