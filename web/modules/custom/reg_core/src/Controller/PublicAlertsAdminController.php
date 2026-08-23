<?php

namespace Drupal\reg_core\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative Public Alert overview with lifecycle counts.
 */
final class PublicAlertsAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
    );
  }

  /**
   * Lists all alerts and their current scheduling state.
   */
  public function overview(): array {
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_public_alert')
      ->sort('field_reg_alert_priority', 'DESC')
      ->sort('created', 'DESC')
      ->execute();
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $now = $this->time->getRequestTime();
    $counts = ['active' => 0, 'scheduled' => 0, 'expired' => 0, 'draft' => 0];
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      $display = $node->hasTranslation($langcode) ? $node->getTranslation($langcode) : $node;
      $start = $this->timestamp($node, 'field_reg_alert_start');
      $end = $this->timestamp($node, 'field_reg_alert_end');
      $state = $this->lifecycle($node, $start, $end, $now);
      if (isset($counts[$state])) {
        $counts[$state]++;
      }
      $edit = $node->access('update')
        ? Link::fromTextAndUrl($this->t('Edit'), $node->toUrl('edit-form'))->toString()
        : $this->t('No access');
      $rows[] = [
        Link::fromTextAndUrl($display->label(), $node->toUrl())->toString(),
        ucfirst(str_replace('_', ' ', (string) $node->get('field_reg_alert_type')->value)),
        ucfirst((string) $node->get('field_reg_alert_severity')->value),
        $this->date($start),
        $end > 0 ? $this->date($end) : $this->t('No expiry'),
        ucfirst((string) $node->get('field_reg_alert_location')->value),
        ucfirst(str_replace('_', ' ', $state)),
        $edit,
      ];
    }

    $summary = [];
    foreach ([
      'active' => $this->t('Active alerts'),
      'scheduled' => $this->t('Scheduled alerts'),
      'expired' => $this->t('Expired alerts'),
      'draft' => $this->t('Draft alerts'),
    ] as $key => $label) {
      $summary[$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-public-alert-admin__summary']],
        'value' => ['#markup' => '<strong>' . $counts[$key] . '</strong>'],
        'label' => ['#markup' => '<span>' . $label . '</span>'],
      ];
    }

    return [
      'intro' => [
        '#type' => 'container',
        'copy' => ['#markup' => '<p>' . $this->t('Manage translated public notices, scheduling, priority, severity, display scope, calls to action, and publication state. Times are entered in the configured site timezone.') . '</p>'],
        'add' => Link::fromTextAndUrl($this->t('Add Public Alert'), Url::fromRoute('node.add', ['node_type' => 'reg_public_alert'], ['attributes' => ['class' => ['button', 'button--primary']]]))->toRenderable(),
      ],
      'summary' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-public-alert-admin__summaries']],
      ] + $summary,
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Title'), $this->t('Type'), $this->t('Severity'), $this->t('Start'), $this->t('End'), $this->t('Display'), $this->t('Status'), $this->t('Edit')],
        '#rows' => $rows,
        '#empty' => $this->t('No Public Alerts have been created yet.'),
      ],
      '#attached' => ['library' => ['reg_core/public_alert_admin']],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'user.permissions'],
        'tags' => ['node_list:reg_public_alert'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Classifies one alert for the administrative summary.
   */
  private function lifecycle(NodeInterface $node, int $start, int $end, int $now): string {
    if (!$node->isPublished()) {
      return 'draft';
    }
    if ($end > 0 && $end < $now) {
      return 'expired';
    }
    if ($start > $now) {
      return 'scheduled';
    }
    return (bool) $node->get('field_reg_active')->value ? 'active' : 'inactive';
  }

  /**
   * Formats a UTC timestamp in the site/user timezone.
   */
  private function date(int $timestamp): string {
    return $timestamp > 0 ? $this->dateFormatter->format($timestamp, 'short') : (string) $this->t('Not set');
  }

  /**
   * Converts a Drupal UTC datetime field to a timestamp.
   */
  private function timestamp(NodeInterface $node, string $fieldName): int {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return 0;
    }
    $value = (string) $node->get($fieldName)->value;
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === FALSE ? 0 : $timestamp;
  }

}
