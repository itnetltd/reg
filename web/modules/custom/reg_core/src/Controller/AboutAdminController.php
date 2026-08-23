<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Dedicated editorial listings for About REG content.
 */
final class AboutAdminController extends ControllerBase {

  private const BUNDLES = [
    'reg_about_page' => ['About page', 'About pages'],
    'reg_leader' => ['Leader', 'Leadership'],
    'reg_partner' => ['Partner', 'Partners'],
    'reg_employee_recognition' => ['Employee recognition', 'Employee recognition'],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly DateFormatterInterface $regDateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds one controlled content overview.
   */
  public function overview(string $bundle): array {
    if (!isset(self::BUNDLES[$bundle])) {
      throw new NotFoundHttpException();
    }
    [$singular, $plural] = self::BUNDLES[$bundle];
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', $bundle)
      ->sort('changed', 'DESC')
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $review = $this->value($node, 'field_reg_review_status');
      $group = $this->value($node, 'field_reg_leadership_group')
        ?: $this->value($node, 'field_reg_partner_type')
        ?: $this->value($node, 'field_reg_about_key')
        ?: $this->value($node, 'field_reg_recognition_period');
      $state = $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
        ? (string) $node->get('moderation_state')->value
        : ($node->isPublished() ? 'published' : 'draft');
      $rows[] = [
        'title' => ['data' => Link::fromTextAndUrl($node->label(), $node->toUrl())->toRenderable()],
        'group' => ucwords(str_replace('_', ' ', $group ?: '—')),
        'language' => strtoupper($node->language()->getId()),
        'review' => ucwords(str_replace('_', ' ', $review ?: 'not set')),
        'status' => ucwords(str_replace('_', ' ', $state)),
        'updated' => $this->regDateFormatter->format($node->getChangedTime(), 'short'),
        'edit' => ['data' => Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('entity.node.edit_form', ['node' => $node->id()], ['query' => ['destination' => Url::fromRoute('<current>')->toString()]]))->toRenderable()],
      ];
    }
    return [
      'actions' => [
        '#type' => 'link',
        '#title' => $this->t('Add @type', ['@type' => $singular]),
        '#url' => Url::fromRoute('node.add', ['node_type' => $bundle]),
        '#attributes' => ['class' => ['button', 'button--primary', 'button--action']],
      ],
      'summary' => ['#markup' => '<p>' . $this->formatPlural(count($rows), '1 record', '@count records') . '</p>'],
      'table' => [
        '#type' => 'table',
        '#caption' => $plural,
        '#header' => [$this->t('Title'), $this->t('Section / group'), $this->t('Language'), $this->t('Source review'), $this->t('Editorial status'), $this->t('Updated'), $this->t('Operations')],
        '#rows' => $rows,
        '#empty' => $this->t('No records have been created yet.'),
        '#sticky' => TRUE,
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Returns one plain field value.
   */
  private function value(NodeInterface $node, string $field): string {
    return $node->hasField($field) && !$node->get($field)->isEmpty()
      ? trim((string) $node->get($field)->value)
      : '';
  }

}
