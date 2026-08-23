<?php

namespace Drupal\reg_core\Analytics;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Language\LanguageManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Database-backed daily aggregates with a deliberately narrow data model.
 */
final class AnalyticsEventTracker implements AnalyticsEventTrackerInterface {

  private const EVENT_ALIASES = [
    'complaint_external_handoff' => 'complaint_handoff',
    'fault_report_handoff' => 'complaint_handoff',
    'team_page_view' => 'sports_team_view',
    'player_profile_view' => 'sports_player_view',
    'fixture_view' => 'sports_fixture_view',
    'result_view' => 'sports_result_view',
    'video_click' => 'sports_video_click',
    'gallery_view' => 'sports_gallery_view',
    'tender_document_download' => 'tender_download',
  ];

  private const EVENTS = [
    'service_click',
    'outage_search',
    'outage_view',
    'outage_subscription',
    'bill_estimator_start',
    'bill_estimator_complete',
    'faq_search',
    'faq_no_result',
    'faq_answer_view',
    'branch_search',
    'branch_filter',
    'branch_view',
    'branch_phone_click',
    'branch_email_click',
    'branch_directions_click',
    'complaint_start',
    'complaint_handoff',
    'tender_view',
    'tender_download',
    'tender_subscription',
    'job_view',
    'job_apply_click',
    'publication_view',
    'publication_download',
    'sports_team_view',
    'sports_player_view',
    'sports_fixture_view',
    'sports_result_view',
    'sports_gallery_view',
    'sports_video_click',
    'sports_article_view',
    'sports_search_result',
    'video_impression',
    'video_play',
    'video_complete',
    'video_next',
    'video_previous',
    'view_all_videos',
    'language_switch',
    'energy_tool_view',
    'bill_estimator_click',
    'carbon_calculator_click',
    'safety_guidance_click',
    'news_view',
    'news_category_filter',
    'news_search',
    'news_share',
    'news_related_click',
  ];

  private const DIMENSIONS = [
    'none',
    'bundle',
    'category',
    'entity',
    'language',
    'section',
    'status',
  ];

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly LanguageManagerInterface $languageManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(string $event, array $context = []): bool {
    if (!$this->configFactory->get('reg_core.settings')->get('analytics.local_aggregate_enabled')) {
      return FALSE;
    }
    $event = self::normalizeEvent($event);
    $context = self::normalizeContext($context);
    if ($event === NULL || $context === NULL) {
      return FALSE;
    }

    $now = $this->time->getRequestTime();
    $date = gmdate('Y-m-d', $now);
    $langcode = preg_replace('/[^a-z0-9-]/i', '', mb_substr(
      (string) ($context['langcode'] ?: $this->languageManager->getCurrentLanguage()->getId()),
      0,
      12,
    )) ?: 'en';
    $key = hash('sha256', implode(':', [
      $event,
      $date,
      $context['dimension_type'],
      $context['dimension_value'],
      $context['entity_id'],
      $langcode,
    ]));

    try {
      $this->database->merge('reg_core_analytics_event')
        ->key('aggregate_key', $key)
        ->insertFields([
          'aggregate_key' => $key,
          'event_name' => $event,
          'event_date' => $date,
          'dimension_type' => $context['dimension_type'],
          'dimension_value' => $context['dimension_value'],
          'entity_id' => $context['entity_id'],
          'langcode' => $langcode,
          'occurrences' => 1,
          'first_recorded' => $now,
          'last_recorded' => $now,
        ])
        ->updateFields(['last_recorded' => $now])
        ->expression('occurrences', 'occurrences + 1')
        ->execute();
      $this->cacheTagsInvalidator->invalidateTags(['reg_core:analytics']);
      return TRUE;
    }
    catch (\Throwable $exception) {
      $this->logger->warning('Could not aggregate an approved analytics event: @message', [
        '@message' => $exception->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Returns an approved canonical event name.
   */
  public static function normalizeEvent(string $event): ?string {
    $event = mb_strtolower(trim($event));
    $event = self::EVENT_ALIASES[$event] ?? $event;
    return in_array($event, self::EVENTS, TRUE) ? $event : NULL;
  }

  /**
   * Reduces arbitrary input to numeric IDs and controlled slugs only.
   */
  public static function normalizeContext(array $context): ?array {
    $dimension_type = mb_strtolower(trim((string) ($context['dimension_type'] ?? 'none')));
    if (!in_array($dimension_type, self::DIMENSIONS, TRUE)) {
      return NULL;
    }
    $dimension_value = mb_strtolower(trim((string) ($context['dimension_value'] ?? 'all')));
    if (!preg_match('/^[a-z0-9_-]{1,64}$/', $dimension_value)) {
      return NULL;
    }
    $entity_id = max(0, (int) ($context['entity_id'] ?? 0));
    $langcode = preg_replace('/[^a-z0-9-]/i', '', mb_substr((string) ($context['langcode'] ?? ''), 0, 12)) ?: '';
    return compact('dimension_type', 'dimension_value', 'entity_id', 'langcode');
  }

}
