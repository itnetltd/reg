<?php

namespace Drupal\reg_core\Dashboard;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Translation\TranslationStatusRepository;

/**
 * Cached aggregate dashboard queries; never calls a remote integration.
 */
final class DashboardRepository {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly TranslationStatusRepository $translations,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
    private readonly CacheBackendInterface $cache,
    private readonly CacheBackendInterface $defaultCache,
    private readonly TimeInterface $time,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * Returns cards and accessible data tables for one authorized section.
   */
  public function section(string $section, array $filters = []): array {
    $filters = self::normalizeFilters($filters);
    $cid = 'reg_core:dashboard:' . hash('sha256', serialize([$section, $filters]));
    if ($cached = $this->cache->get($cid)) {
      return is_array($cached->data) ? $cached->data : [];
    }
    $data = match ($section) {
      'customer-services' => $this->customerServices($filters),
      'content' => $this->content($filters),
      'procurement' => $this->procurement($filters),
      'recruitment' => $this->recruitment($filters),
      'publications' => $this->publications($filters),
      'sports' => $this->sports($filters),
      'operations' => $this->operations(),
      default => $this->overview($filters),
    };
    $lifetime = max(60, min(3600, (int) ($this->configFactory->get('reg_core.settings')->get('analytics.dashboard_cache_seconds') ?: 300)));
    $this->cache->set($cid, $data, $this->time->getRequestTime() + $lifetime, [
      'reg_core:analytics',
      'node_list',
      'config:reg_core.settings',
      'reg_core:dms_outages',
    ]);
    return $data;
  }

  /**
   * Returns aggregate-only CSV rows for an approved report.
   */
  public function export(string $report, array $filters = []): array {
    $filters = self::normalizeFilters($filters);
    if (in_array($report, ['branch-usage', 'customer-services', 'sports'], TRUE)) {
      $events = match ($report) {
        'branch-usage' => ['branch_search', 'branch_filter', 'branch_view', 'branch_phone_click', 'branch_email_click', 'branch_directions_click'],
        'sports' => ['sports_team_view', 'sports_player_view', 'sports_fixture_view', 'sports_result_view', 'sports_gallery_view', 'sports_video_click'],
        default => ['service_click', 'outage_search', 'outage_view', 'bill_estimator_start', 'bill_estimator_complete', 'faq_search', 'faq_no_result', 'branch_search', 'branch_view', 'complaint_handoff'],
      };
      $rows = [];
      foreach ($this->eventRows($events, $filters) as $row) {
        $rows[] = [$row['date'], $row['event'], $row['dimension'], $row['entity_id'], $row['language'], $row['count']];
      }
      return [['Date', 'Event', 'Aggregate dimension', 'Content ID', 'Language', 'Count'], $rows];
    }
    if (in_array($report, ['tender-downloads', 'publication-downloads'], TRUE)) {
      $type = $report === 'tender-downloads' ? 'tender' : 'publication';
      $rows = [];
      $query = $this->database->select('reg_core_document_download', 'd')
        ->fields('d', ['document_label', 'nid', 'media_id', 'downloads', 'first_downloaded', 'last_downloaded'])
        ->condition('content_type', $type)
        ->orderBy('downloads', 'DESC');
      foreach ($query->execute() as $record) {
        $rows[] = [$record->document_label, $record->nid, $record->media_id, $record->downloads, gmdate(DATE_ATOM, $record->first_downloaded), gmdate(DATE_ATOM, $record->last_downloaded)];
      }
      return [['Document', 'Content ID', 'Media ID', 'Downloads', 'First download', 'Last download'], $rows];
    }
    if ($report === 'faq-unanswered') {
      $rows = [];
      foreach ($this->database->select('reg_core_faq_unanswered', 'f')->fields('f', ['phrase', 'langcode', 'occurrences', 'first_searched', 'last_searched'])->orderBy('occurrences', 'DESC')->execute() as $record) {
        $rows[] = [$record->phrase, $record->langcode, $record->occurrences, gmdate(DATE_ATOM, $record->first_searched), gmdate(DATE_ATOM, $record->last_searched)];
      }
      return [['Sanitized phrase', 'Language', 'Occurrences', 'First searched', 'Last searched'], $rows];
    }
    if ($report === 'translations') {
      $rows = [];
      foreach ($this->translations->report([]) as $row) {
        $rows[] = [$row['title'], $row['bundle'], $row['source_language'], $row['english_status'], $row['kinyarwanda_status'], $row['last_updated'], $row['owner'], $row['review_date']];
      }
      return [['Content', 'Content type', 'Source language', 'English status', 'Kinyarwanda status', 'Last updated', 'Owner', 'Review date'], $rows];
    }
    return [[], []];
  }

  private function overview(array $filters): array {
    $events = $this->eventCounts([], $filters);
    $published = $this->nodeCount([], TRUE);
    $translations = $this->translations->summary($this->translations->report([]));
    return [
      'title' => 'REG analytics overview',
      'notice' => 'External visits, users, devices, and top-page data appear only after an approved provider/API is configured. Drupal displays local application aggregates without visitor identifiers.',
      'cards' => [
        ['label' => 'Application events', 'value' => array_sum($events)],
        ['label' => 'Published content', 'value' => $published],
        ['label' => 'FAQ no-result events', 'value' => $events['faq_no_result'] ?? 0],
        ['label' => 'Document downloads', 'value' => $this->downloadTotal()],
        ['label' => 'Missing translations', 'value' => $translations['missing_translation'] ?? 0],
        ['label' => 'External provider', 'value' => $this->providerLabel()],
      ],
      'tables' => [$this->eventTable($this->eventRows([], $filters), 'Top local application events')],
    ];
  }

  private function customerServices(array $filters): array {
    $events = $this->eventCounts([
      'service_click', 'outage_search', 'outage_view', 'bill_estimator_start',
      'bill_estimator_complete', 'faq_search', 'faq_no_result', 'branch_search',
      'branch_view', 'complaint_handoff',
    ], $filters);
    $unanswered_query = $this->database->select('reg_core_faq_unanswered', 'f');
    $unanswered_query->addExpression('COALESCE(SUM(occurrences), 0)', 'total');
    $unanswered = (int) $unanswered_query->execute()->fetchField();
    return [
      'title' => 'Customer services',
      'notice' => 'Counts are aggregate service interactions. Search phrases and complaint details are not stored here.',
      'cards' => array_merge($this->eventCards($events), [['label' => 'Sanitized unanswered FAQ searches', 'value' => $unanswered]]),
      'tables' => [$this->eventTable($this->eventRows(array_keys($events), $filters), 'Customer-service event totals')],
    ];
  }

  private function content(array $filters): array {
    $bundles = $filters['content_type'] ? [$filters['content_type']] : [];
    $published = $this->filteredNodeCount($bundles, TRUE, $filters);
    $unpublished = $this->filteredNodeCount($bundles, FALSE, $filters);
    $translation_rows = $this->translations->report([]);
    $translation_summary = $this->translations->summary($translation_rows);
    $review = $this->reviewMetrics();
    $branchGovernance = $this->branchGovernanceMetrics();
    return [
      'title' => 'Content governance',
      'notice' => 'Review thresholds are configured in REG website settings. The table is a text alternative to the KPI cards.',
      'cards' => [
        ['label' => 'Published content', 'value' => $published],
        ['label' => 'Draft or unpublished', 'value' => $unpublished],
        ['label' => 'Content awaiting review', 'value' => $this->moderationCount('needs_review')],
        ['label' => 'Missing translations', 'value' => $translation_summary['missing_translation'] ?? 0],
        ['label' => 'Outdated translations', 'value' => $translation_summary['outdated_translation'] ?? 0],
        ['label' => 'Branches needing verification', 'value' => $branchGovernance['needing_verification']],
        ['label' => 'Branches missing coordinates', 'value' => $branchGovernance['missing_coordinates']],
        ['label' => 'Branches missing email', 'value' => $branchGovernance['missing_email']],
        ['label' => 'Branches past verification interval', 'value' => $branchGovernance['verification_overdue']],
        ['label' => 'FAQ answers past review', 'value' => $review['faqs']],
        ['label' => 'Service pages needing review', 'value' => $review['services']],
        ['label' => 'Expired tenders still active', 'value' => $this->expiredActive('reg_tender', 'field_reg_tender_status')],
        ['label' => 'Closed jobs still open', 'value' => $this->expiredActive('reg_job', 'field_reg_job_status')],
        ['label' => 'Missing or invalid service links', 'value' => $this->invalidServiceLinks()],
      ],
      'tables' => [
        [
          'caption' => 'Content status totals',
          'headers' => ['Status', 'Count'],
          'rows' => [['Published', $published], ['Draft or unpublished', $unpublished], ['Needs review', $this->moderationCount('needs_review')]],
        ],
        [
          'caption' => 'Published content by responsible department',
          'headers' => ['Department', 'Count'],
          'rows' => $this->departmentRows(),
        ],
        [
          'caption' => 'Branch verification status',
          'headers' => ['Check', 'Count'],
          'rows' => [
            ['Requires REG verification', $branchGovernance['needing_verification']],
            ['Missing approved coordinates', $branchGovernance['missing_coordinates']],
            ['Missing email', $branchGovernance['missing_email']],
            ['Last verified before configured interval', $branchGovernance['verification_overdue']],
          ],
        ],
      ],
    ];
  }

  private function procurement(array $filters): array {
    $events = $this->eventCounts(['tender_view', 'tender_download', 'tender_subscription'], $filters);
    return [
      'title' => 'Procurement',
      'notice' => 'Supplier subscriptions are shown only as a total. Email addresses and phone numbers are never included in dashboards or exports.',
      'cards' => [
        ['label' => 'Plan items', 'value' => $this->nodeCount(['reg_procurement_plan_item'])],
        ['label' => 'Published tenders', 'value' => $this->nodeCount(['reg_tender'], TRUE)],
        ['label' => 'Plan items not yet published', 'value' => $this->fieldValueCount('reg_procurement_plan_item', 'field_reg_proc_item_status', ['planned', 'preparing'])],
        ['label' => 'Active tenders', 'value' => $this->statusCount('reg_tender', 'field_reg_tender_status', 'active')],
        ['label' => 'Closed tenders', 'value' => $this->statusCount('reg_tender', 'field_reg_tender_status', 'closed')],
        ['label' => 'Awarded tenders', 'value' => $this->statusCount('reg_tender', 'field_reg_tender_status', 'awarded')],
        ['label' => 'Cancelled tenders', 'value' => $this->statusCount('reg_tender', 'field_reg_tender_status', 'cancelled')],
        ['label' => 'UMUCYO tenders', 'value' => $this->fieldValueCount('reg_tender', 'field_reg_publication_channel', ['umucyo', 'both'], TRUE)],
        ['label' => 'REG-platform tenders', 'value' => $this->fieldValueCount('reg_tender', 'field_reg_publication_channel', ['reg', 'both'], TRUE)],
        ['label' => 'Unplanned procurements', 'value' => $this->fieldValueCount('reg_tender', 'field_reg_unplanned', [1])],
        ['label' => 'Closing within 14 days', 'value' => $this->closingSoon('reg_tender')],
        ['label' => 'Tender downloads', 'value' => $this->downloadTotal('tender')],
        ['label' => 'Active supplier subscriptions', 'value' => $this->subscriptionCount()],
        ['label' => 'Tender views', 'value' => $events['tender_view'] ?? 0],
      ],
      'tables' => [$this->downloadTable('tender', 'Most downloaded tender documents')],
    ];
  }

  private function recruitment(array $filters): array {
    $events = $this->eventCounts(['job_view', 'job_apply_click'], $filters);
    return [
      'title' => 'Recruitment',
      'notice' => 'Drupal reports published vacancy content and external handoffs only; it does not store applicant profiles.',
      'cards' => [
        ['label' => 'Planned positions', 'value' => $this->nodeCount(['reg_recruitment_plan_item'])],
        ['label' => 'Vacancies published', 'value' => $this->nodeCount(['reg_job'], TRUE)],
        ['label' => 'Applications received', 'value' => $this->fieldValueCount('reg_job_application', 'field_reg_application_status', ['submitted', 'under_review', 'shortlisted', 'interview', 'selected', 'closed'])],
        ['label' => 'Positions filled', 'value' => $this->fieldValueCount('reg_recruitment_plan_item', 'field_reg_recruit_item_status', ['filled'])],
        ['label' => 'Recruitments in progress', 'value' => $this->fieldValueCount('reg_recruitment_plan_item', 'field_reg_recruit_item_status', ['preparing', 'published', 'recruiting', 'selection'])],
        ['label' => 'Unplanned recruitment', 'value' => $this->fieldValueCount('reg_job', 'field_reg_unplanned', [1])],
        ['label' => 'Active jobs', 'value' => $this->statusCount('reg_job', 'field_reg_job_status', 'active')],
        ['label' => 'Published recruitment results', 'value' => $this->statusCount('reg_job', 'field_reg_job_status', 'results')],
        ['label' => 'Vacancy views', 'value' => $events['job_view'] ?? 0],
        ['label' => 'Application handoffs', 'value' => $events['job_apply_click'] ?? 0],
        ['label' => 'Closed jobs still marked open', 'value' => $this->expiredActive('reg_job', 'field_reg_job_status')],
      ],
      'tables' => [$this->eventTable($this->eventRows(array_keys($events), $filters), 'Recruitment event totals')],
    ];
  }

  private function publications(array $filters): array {
    $events = $this->eventCounts(['publication_view', 'publication_download'], $filters);
    return [
      'title' => 'Publications',
      'notice' => 'Download counts are document-level aggregates and contain no visitor history.',
      'cards' => [
        ['label' => 'Published publications', 'value' => $this->nodeCount(['reg_publication'], TRUE)],
        ['label' => 'Publication views', 'value' => $events['publication_view'] ?? 0],
        ['label' => 'Publication downloads', 'value' => $this->downloadTotal('publication')],
      ],
      'tables' => [$this->downloadTable('publication', 'Most downloaded publications')],
    ];
  }

  private function sports(array $filters): array {
    $names = ['sports_team_view', 'sports_player_view', 'sports_fixture_view', 'sports_result_view', 'sports_gallery_view', 'sports_video_click'];
    $events = $this->eventCounts($names, $filters);
    return [
      'title' => 'Sports analytics',
      'notice' => 'Sports engagement is aggregated by event and optional content ID. Scores and player names are never used as analytics dimensions.',
      'cards' => $this->eventCards($events),
      'tables' => [$this->eventTable($this->eventRows($names, $filters), 'Sports event totals')],
    ];
  }

  private function operations(): array {
    $now = $this->time->getRequestTime();
    $cached = $this->defaultCache->get('reg_core:dms:outages', TRUE);
    $outage_count = $cached && is_array($cached->data) ? count($cached->data) : 0;
    $attempt = (int) $this->state->get('reg_core.dms_last_sync_attempt', 0);
    $success = (int) $this->state->get('reg_core.dms_last_sync_success', 0);
    $failure = (int) $this->state->get('reg_core.dms_last_sync_failure', 0);
    $queue_count = $this->database->schema()->tableExists('queue')
      ? (int) $this->database->select('queue', 'q')->countQuery()->execute()->fetchField()
      : 0;
    return [
      'title' => 'Operations',
      'notice' => 'This view intentionally omits endpoint URLs, client IDs, environment-variable values, credentials, and upstream response bodies.',
      'cards' => [
        ['label' => 'DMS adapter', 'value' => strtoupper((string) ($this->configFactory->get('reg_core.settings')->get('dms.driver') ?: 'mock'))],
        ['label' => 'DMS connection status', 'value' => $failure > $success ? 'Last attempt failed' : ($success ? 'Last sync succeeded' : 'Awaiting scheduled sync')],
        ['label' => 'Last successful outage sync', 'value' => $this->date($success)],
        ['label' => 'Last failed outage sync', 'value' => $this->date($failure)],
        ['label' => 'DMS cache age', 'value' => $cached ? max(0, $now - (int) $cached->created) . ' seconds' : 'No cache'],
        ['label' => 'Outages currently cached', 'value' => $outage_count],
        ['label' => 'Drupal cron last run', 'value' => $this->date((int) $this->state->get('system.cron_last', 0))],
        ['label' => 'Queued items', 'value' => $queue_count],
        ['label' => 'Cache status', 'value' => 'Available'],
        ['label' => 'Email gateway', 'value' => 'Configuration placeholder'],
        ['label' => 'SMS gateway', 'value' => $this->configFactory->get('reg_core.settings')->get('public_information.enable_tender_sms') ? 'Enabled; gateway approval required' : 'Disabled'],
      ],
      'tables' => [[
        'caption' => 'Operational integration timeline',
        'headers' => ['Checkpoint', 'Timestamp'],
        'rows' => [
          ['Last DMS attempt', $this->date($attempt)],
          ['Last DMS success', $this->date($success)],
          ['Last DMS failure', $this->date($failure)],
          ['Last Drupal cron run', $this->date((int) $this->state->get('system.cron_last', 0))],
        ],
      ]],
    ];
  }

  private function eventCounts(array $events, array $filters): array {
    $query = $this->database->select('reg_core_analytics_event', 'a');
    $query->addField('a', 'event_name');
    $query->addExpression('SUM(occurrences)', 'total');
    if ($events) {
      $query->condition('event_name', $events, 'IN');
    }
    $this->applyEventFilters($query, $filters);
    $query->groupBy('event_name')->orderBy('total', 'DESC');
    $counts = [];
    foreach ($query->execute() as $record) {
      $counts[$record->event_name] = (int) $record->total;
    }
    foreach ($events as $event) {
      $counts[$event] ??= 0;
    }
    return $counts;
  }

  private function eventRows(array $events, array $filters): array {
    $query = $this->database->select('reg_core_analytics_event', 'a')
      ->fields('a', ['event_date', 'event_name', 'dimension_type', 'dimension_value', 'entity_id', 'langcode']);
    $query->addExpression('SUM(occurrences)', 'total');
    if ($events) {
      $query->condition('event_name', $events, 'IN');
    }
    $this->applyEventFilters($query, $filters);
    foreach (['event_date', 'event_name', 'dimension_type', 'dimension_value', 'entity_id', 'langcode'] as $field) {
      $query->groupBy($field);
    }
    $query->orderBy('event_date', 'DESC')->orderBy('total', 'DESC')->range(0, 100);
    $rows = [];
    foreach ($query->execute() as $record) {
      $rows[] = [
        'date' => $record->event_date,
        'event' => $record->event_name,
        'dimension' => $record->dimension_type . ':' . $record->dimension_value,
        'entity_id' => (int) $record->entity_id ?: '',
        'language' => $record->langcode,
        'count' => (int) $record->total,
      ];
    }
    return $rows;
  }

  private function applyEventFilters(object $query, array $filters): void {
    if ($filters['date_from']) {
      $query->condition('event_date', $filters['date_from'], '>=');
    }
    if ($filters['date_to']) {
      $query->condition('event_date', $filters['date_to'], '<=');
    }
    if ($filters['language']) {
      $query->condition('langcode', $filters['language']);
    }
    if ($filters['entity']) {
      $query->condition('dimension_type', 'entity')->condition('dimension_value', $filters['entity']);
    }
  }

  private function eventCards(array $events): array {
    $cards = [];
    foreach ($events as $event => $count) {
      $cards[] = ['label' => ucwords(str_replace('_', ' ', $event)), 'value' => $count];
    }
    return $cards;
  }

  private function eventTable(array $rows, string $caption): array {
    return [
      'caption' => $caption,
      'headers' => ['Date', 'Event', 'Aggregate dimension', 'Content ID', 'Language', 'Count'],
      'rows' => array_map('array_values', $rows),
    ];
  }

  private function nodeCount(array $bundles = [], ?bool $published = NULL): int {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE);
    if ($bundles) {
      $query->condition('type', $bundles, 'IN');
    }
    if ($published !== NULL) {
      $query->condition('status', $published ? NodeInterface::PUBLISHED : NodeInterface::NOT_PUBLISHED);
    }
    return (int) $query->count()->execute();
  }

  private function filteredNodeCount(array $bundles, bool $published, array $filters): int {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', $published ? NodeInterface::PUBLISHED : NodeInterface::NOT_PUBLISHED);
    if ($bundles) {
      $query->condition('type', $bundles, 'IN');
    }
    if ($filters['language']) {
      $query->condition('langcode', $filters['language']);
    }
    if ($filters['department']) {
      $query->condition('field_reg_content_department', $filters['department'], 'CONTAINS');
    }
    if ($filters['entity']) {
      $query->condition('field_reg_entity', $filters['entity']);
    }
    if ($filters['status'] === 'published' && !$published) {
      return 0;
    }
    if ($filters['status'] === 'unpublished' && $published) {
      return 0;
    }
    try {
      return (int) $query->count()->execute();
    }
    catch (\Exception) {
      return 0;
    }
  }

  private function moderationCount(string $state): int {
    try {
      return (int) $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('moderation_state', $state)
        ->count()
        ->execute();
    }
    catch (\Exception) {
      return 0;
    }
  }

  private function departmentRows(): array {
    $rows = [];
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', NodeInterface::PUBLISHED)
      ->range(0, 2000)
      ->execute();
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      $department = $node->hasField('field_reg_content_department') && !$node->get('field_reg_content_department')->isEmpty()
        ? trim(strip_tags((string) $node->get('field_reg_content_department')->value))
        : 'Not assigned';
      $department = mb_substr($department, 0, 64);
      $rows[$department] = ($rows[$department] ?? 0) + 1;
    }
    arsort($rows);
    return array_map(static fn(string $department, int $count): array => [$department, $count], array_keys($rows), array_values($rows));
  }

  private function invalidServiceLinks(): int {
    $invalid = 0;
    $ids = $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'reg_service')
      ->condition('status', NodeInterface::PUBLISHED)
      ->execute();
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      $url = $node->hasField('field_reg_url') && !$node->get('field_reg_url')->isEmpty()
        ? trim((string) $node->get('field_reg_url')->uri)
        : '';
      if ($url === '' || (!str_starts_with($url, 'internal:') && !UrlHelper::isValid($url, TRUE))) {
        $invalid++;
      }
    }
    return $invalid;
  }

  private function statusCount(string $bundle, string $field, string $status): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition($field, $status)
      ->count()
      ->execute();
  }

  /** Counts controlled field values without exposing private record details. */
  private function fieldValueCount(string $bundle, string $field, array $values, bool $published = FALSE): int {
    $query = $this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->condition($field, $values, 'IN');
    if ($published) $query->condition('status', NodeInterface::PUBLISHED);
    return (int) $query->count()->execute();
  }

  private function closingSoon(string $bundle): int {
    $now = gmdate('Y-m-d\TH:i:s', $this->time->getRequestTime());
    $soon = gmdate('Y-m-d\TH:i:s', $this->time->getRequestTime() + (14 * 86400));
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition('field_reg_closing_date', $now, '>=')
      ->condition('field_reg_closing_date', $soon, '<=')
      ->count()
      ->execute();
  }

  private function expiredActive(string $bundle, string $status_field): int {
    return (int) $this->entityTypeManager->getStorage('node')->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', $bundle)
      ->condition('status', NodeInterface::PUBLISHED)
      ->condition($status_field, 'active')
      ->condition('field_reg_closing_date', gmdate('Y-m-d\TH:i:s', $this->time->getRequestTime()), '<')
      ->count()
      ->execute();
  }

  private function reviewMetrics(): array {
    $config = $this->configFactory->get('reg_core.settings');
    $metrics = ['branches' => 0, 'faqs' => 0, 'services' => 0];
    foreach ([
      'reg_branch' => ['branches', 'field_reg_review_date', (int) ($config->get('governance.branch_review_days') ?: 180)],
      'reg_faq' => ['faqs', 'field_reg_review_date', (int) ($config->get('governance.faq_review_days') ?: 180)],
      'reg_service' => ['services', 'field_reg_translation_review', (int) ($config->get('governance.service_review_days') ?: 180)],
    ] as $bundle => [$key, $field, $days]) {
      $ids = $this->entityTypeManager->getStorage('node')->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $bundle)
        ->condition('status', NodeInterface::PUBLISHED)
        ->execute();
      foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
        $date = $node->hasField($field) && !$node->get($field)->isEmpty() ? (string) $node->get($field)->value : '';
        $due = $date ? strtotime($date . ' UTC') : $node->getChangedTime() + ($days * 86400);
        if ($due !== FALSE && $due < $this->time->getRequestTime()) {
          $metrics[$key]++;
        }
      }
    }
    return $metrics;
  }

  /**
   * Returns branch-quality metrics using the configured verification interval.
   */
  private function branchGovernanceMetrics(): array {
    $days = max(1, (int) ($this->configFactory->get('reg_core.settings')->get('governance.branch_review_days') ?: 180));
    $cutoff = $this->time->getRequestTime() - ($days * 86400);
    $metrics = [
      'needing_verification' => 0,
      'missing_coordinates' => 0,
      'missing_email' => 0,
      'verification_overdue' => 0,
    ];
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'reg_branch')
      ->condition('status', NodeInterface::PUBLISHED)
      ->execute();
    foreach ($storage->loadMultiple($ids) as $node) {
      $value = static function (object $node, string $field): string {
        return !$node->hasField($field) || $node->get($field)->isEmpty()
          ? ''
          : trim((string) $node->get($field)->value);
      };
      if ($value($node, 'field_reg_branch_review_status') !== 'verified') {
        $metrics['needing_verification']++;
      }
      if ($value($node, 'field_reg_latitude') === '' || $value($node, 'field_reg_longitude') === '') {
        $metrics['missing_coordinates']++;
      }
      if ($value($node, 'field_reg_email') === '') {
        $metrics['missing_email']++;
      }
      $lastVerified = $value($node, 'field_reg_last_verified');
      $timestamp = $lastVerified !== '' ? strtotime($lastVerified . ' UTC') : FALSE;
      if ($timestamp !== FALSE && $timestamp < $cutoff) {
        $metrics['verification_overdue']++;
      }
    }
    return $metrics;
  }

  private function downloadTotal(string $type = ''): int {
    $query = $this->database->select('reg_core_document_download', 'd');
    $query->addExpression('COALESCE(SUM(downloads), 0)', 'total');
    if ($type) {
      $query->condition('content_type', $type);
    }
    return (int) $query->execute()->fetchField();
  }

  private function downloadTable(string $type, string $caption): array {
    $query = $this->database->select('reg_core_document_download', 'd')
      ->fields('d', ['document_label', 'nid', 'downloads', 'last_downloaded'])
      ->condition('content_type', $type)
      ->orderBy('downloads', 'DESC')
      ->range(0, 20);
    $rows = [];
    foreach ($query->execute() as $record) {
      $rows[] = [$record->document_label, (int) $record->nid, (int) $record->downloads, $this->date((int) $record->last_downloaded)];
    }
    return ['caption' => $caption, 'headers' => ['Document', 'Content ID', 'Downloads', 'Last download'], 'rows' => $rows];
  }

  private function subscriptionCount(): int {
    return (int) $this->database->select('reg_core_tender_subscription', 's')->condition('active', 1)->countQuery()->execute()->fetchField();
  }

  private function providerLabel(): string {
    $config = $this->configFactory->get('reg_core.settings');
    if (!$config->get('analytics.enabled')) {
      return 'Disabled';
    }
    $provider = (string) $config->get('analytics.provider');
    return in_array($provider, ['ga4', 'matomo', 'other'], TRUE) ? strtoupper($provider) : 'Not configured';
  }

  private function date(int $timestamp): string {
    return $timestamp > 0 ? $this->dateFormatter->format($timestamp, 'short') : 'Not recorded';
  }

  public static function normalizeFilters(array $filters): array {
    $date = static fn(mixed $value): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? (string) $value : '';
    $slug = static fn(mixed $value, array $allowed = []): string => in_array((string) $value, $allowed, TRUE) ? (string) $value : '';
    return [
      'date_from' => $date($filters['date_from'] ?? ''),
      'date_to' => $date($filters['date_to'] ?? ''),
      'entity' => $slug($filters['entity'] ?? '', ['reg', 'eucl', 'edcl']),
      'content_type' => preg_match('/^[a-z0-9_]{1,40}$/', (string) ($filters['content_type'] ?? '')) ? (string) $filters['content_type'] : '',
      'language' => $slug($filters['language'] ?? '', ['en', 'rw']),
      'department' => preg_match('/^[a-z0-9 _-]{1,64}$/i', (string) ($filters['department'] ?? '')) ? mb_substr((string) $filters['department'], 0, 64) : '',
      'status' => preg_match('/^[a-z0-9_-]{1,32}$/', (string) ($filters['status'] ?? '')) ? (string) $filters['status'] : '',
    ];
  }

}
