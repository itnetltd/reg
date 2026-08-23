<?php

namespace Drupal\reg_core\Alert;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Cached, translated and time-aware public alert selector.
 */
final class PublicAlertRepository implements PublicAlertRepositoryInterface {

  private const SEVERITY_RANK = [
    'information' => 1,
    'warning' => 2,
    'high' => 3,
    'critical' => 4,
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly CacheBackendInterface $cache,
    private readonly TimeInterface $time,
    private readonly TranslationInterface $translation,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function current(bool $homepage): array {
    $langcode = $this->regLanguageManager->getCurrentLanguage()->getId();
    $scope = $homepage ? 'homepage' : 'sitewide';
    $cid = 'reg_core:public_alert:v2:' . $scope . ':' . $langcode;
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : $this->emptyResult();
    }

    $now = $this->time->getRequestTime();
    $candidates = [];
    $boundaries = [];
    try {
      $storage = $this->regEntityTypeManager->getStorage('node');
      $locationGroup = $storage->getQuery()->orConditionGroup()
        ->condition('field_reg_alert_location', 'sitewide');
      if ($homepage) {
        $locationGroup->condition('field_reg_alert_location', 'homepage');
      }
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'reg_public_alert')
        ->condition('status', NodeInterface::PUBLISHED)
        ->condition('field_reg_active', 1)
        ->condition($locationGroup)
        ->sort('created', 'DESC')
        ->range(0, 100)
        ->execute();

      foreach ($storage->loadMultiple($ids) as $node) {
        if (!$node instanceof NodeInterface || !$node->access('view')) {
          continue;
        }
        if ($node->language()->getId() !== $langcode) {
          if (!$node->hasTranslation($langcode)) {
            continue;
          }
          $node = $node->getTranslation($langcode);
          if (!$node->isPublished() || !$node->access('view')) {
            continue;
          }
        }
        $candidate = $this->normalize($node);
        if ($candidate['start_timestamp'] > $now) {
          $boundaries[] = $candidate['start_timestamp'];
        }
        if ($candidate['end_timestamp'] >= $now) {
          $boundaries[] = $candidate['end_timestamp'] + 1;
        }
        if (self::isEligible($candidate, $now, $homepage)) {
          $candidates[] = $candidate;
        }
      }

      // Outage promotions are normalized directly from their source record so
      // Communications never has to duplicate outage details in an alert.
      $outage_ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'reg_outage')
        ->condition('status', NodeInterface::PUBLISHED)
        ->condition('field_reg_show_public_alert', 1)
        ->sort('field_reg_priority', 'DESC')
        ->range(0, 20)
        ->execute();
      foreach ($storage->loadMultiple($outage_ids) as $outage) {
        if (!$outage instanceof NodeInterface || !$outage->access('view')) continue;
        if ($outage->hasTranslation($langcode) && $outage->getTranslation($langcode)->isPublished()) $outage = $outage->getTranslation($langcode);
        $status = $this->value($outage, 'field_reg_outage_status');
        if (in_array($status, ['restored', 'cancelled', 'archived'], TRUE)) continue;
        $segments = $outage->get('field_reg_outage_segments');
        $first = $segments->first();
        $last = $segments->count() ? $segments->get($segments->count() - 1) : NULL;
        $start = $outage->getCreatedTime();
        $end = $last && $last->end ? (strtotime((string) $last->end . ' UTC') ?: 0) : 0;
        $network = $this->value($outage, 'field_reg_network_element') ?: (string) $outage->label();
        $window = $first && $first->start ? \Drupal::service('date.formatter')->format(strtotime((string) $first->start . ' UTC'), 'custom', 'd M · H:i') : '';
        $candidate = [
          'id' => 'outage-' . $outage->id(),
          'title' => (string) $this->translation->translate('Planned power outage'),
          'message' => trim($network . ($window !== '' ? ' · ' . $window : '')),
          'type' => 'outage', 'severity' => 'warning', 'severity_label' => $this->severityLabel('warning'),
          'cta_label' => (string) $this->translation->translate('View outage'),
          'cta_url' => Url::fromRoute('reg_core.outage_detail', ['outage_id' => 'notice-' . $outage->id()])->toString(),
          'cta_external' => FALSE, 'start_timestamp' => $start, 'end_timestamp' => $end,
          'priority' => (int) $this->value($outage, 'field_reg_priority'), 'display_location' => 'homepage',
          'active' => TRUE, 'published' => TRUE, 'created' => $outage->getCreatedTime(), 'role' => 'status',
        ];
        if (self::isEligible($candidate, $now, $homepage)) $candidates[] = $candidate;
      }
    }
    catch (\Throwable) {
      // Keep public pages renderable while a database update is in progress.
      $candidates = [];
    }

    self::sortCandidates($candidates);
    $expires = $now + 300;
    if ($boundaries) {
      $expires = min($expires, min($boundaries));
    }
    $result = [
      'alerts' => $candidates,
      'alert' => $candidates[0] ?? NULL,
      'max_age' => max(0, $expires - $now),
    ];
    $this->cache->set($cid, $result, $expires, [
      'node_list',
      'node_list:reg_public_alert',
      'node_list:reg_outage',
    ]);
    return $result;
  }

  /**
   * Tests public eligibility using UTC timestamps and display scope.
   */
  public static function isEligible(array $candidate, int $now, bool $homepage): bool {
    $location = (string) ($candidate['display_location'] ?? '');
    $locationEligible = $location === 'sitewide' || ($homepage && $location === 'homepage');
    $start = (int) ($candidate['start_timestamp'] ?? 0);
    $end = (int) ($candidate['end_timestamp'] ?? 0);
    return !empty($candidate['published'])
      && !empty($candidate['active'])
      && $locationEligible
      && $start > 0
      && $start <= $now
      && ($end === 0 || $end >= $now);
  }

  /**
   * Sorts by severity, editor priority, then newest start time.
   */
  public static function sortCandidates(array &$candidates): void {
    usort($candidates, static function (array $left, array $right): int {
      $severity = (self::SEVERITY_RANK[$right['severity'] ?? 'information'] ?? 0)
        <=> (self::SEVERITY_RANK[$left['severity'] ?? 'information'] ?? 0);
      if ($severity !== 0) {
        return $severity;
      }
      $priority = ((int) ($right['priority'] ?? 0)) <=> ((int) ($left['priority'] ?? 0));
      return $priority !== 0
        ? $priority
        : ((int) ($right['start_timestamp'] ?? 0)) <=> ((int) ($left['start_timestamp'] ?? 0));
    });
  }

  /**
   * Converts a public-alert node into safe template data.
   */
  private function normalize(NodeInterface $node): array {
    $severity = $this->value($node, 'field_reg_alert_severity');
    if (!isset(self::SEVERITY_RANK[$severity])) {
      $severity = 'information';
    }
    $type = $this->value($node, 'field_reg_alert_type');
    $link = $this->link($node);
    return [
      'id' => (int) $node->id(),
      'title' => (string) $node->label(),
      'message' => $this->value($node, 'field_reg_alert_message'),
      'type' => preg_match('/^[a-z_]+$/', $type) ? $type : 'general',
      'severity' => $severity,
      'severity_label' => $this->severityLabel($severity),
      'cta_label' => $this->value($node, 'field_reg_alert_cta_label'),
      'cta_url' => $link['url'],
      'cta_external' => $link['external'],
      'start_timestamp' => $this->timestamp($node, 'field_reg_alert_start'),
      'end_timestamp' => $this->timestamp($node, 'field_reg_alert_end'),
      'priority' => (int) $this->value($node, 'field_reg_alert_priority'),
      'display_location' => $this->value($node, 'field_reg_alert_location'),
      'active' => (bool) $this->value($node, 'field_reg_active'),
      'published' => $node->isPublished(),
      'created' => $node->getCreatedTime(),
      'role' => $severity === 'critical' ? 'alert' : 'status',
    ];
  }

  /**
   * Returns an optional CTA as a generated Drupal URL.
   */
  private function link(NodeInterface $node): array {
    if (!$node->hasField('field_reg_alert_cta_url') || $node->get('field_reg_alert_cta_url')->isEmpty()) {
      return ['url' => '', 'external' => FALSE];
    }
    try {
      $url = $node->get('field_reg_alert_cta_url')->first()->getUrl();
      return ['url' => $url->toString(), 'external' => $url->isExternal()];
    }
    catch (\Exception) {
      return ['url' => '', 'external' => FALSE];
    }
  }

  /**
   * Returns a stored Drupal datetime as a UTC timestamp.
   */
  private function timestamp(NodeInterface $node, string $fieldName): int {
    $value = $this->value($node, $fieldName);
    if ($value === '') {
      return 0;
    }
    $timestamp = strtotime($value . ' UTC');
    return $timestamp === FALSE ? 0 : $timestamp;
  }

  /**
   * Returns a translated, visible severity label.
   */
  private function severityLabel(string $severity): string {
    return (string) match ($severity) {
      'critical' => $this->translation->translate('Critical'),
      'high' => $this->translation->translate('High'),
      'warning' => $this->translation->translate('Warning'),
      default => $this->translation->translate('Information'),
    };
  }

  /**
   * Returns a field's sanitized plain value.
   */
  private function value(NodeInterface $node, string $fieldName): string {
    if (!$node->hasField($fieldName) || $node->get($fieldName)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) ($node->get($fieldName)->value ?? '')));
  }

  /**
   * Provides a stable no-alert response during updates.
   */
  private function emptyResult(): array {
    return ['alerts' => [], 'alert' => NULL, 'max_age' => 0];
  }

}
