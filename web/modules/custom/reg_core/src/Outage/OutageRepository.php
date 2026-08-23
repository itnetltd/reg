<?php

namespace Drupal\reg_core\Outage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;

/**
 * Normalized public repository for manually managed Drupal outages.
 *
 * The normalized contract remains source-neutral so a future DMS importer can
 * write into the same model without changing controllers or templates.
 */
final class OutageRepository implements OutageRepositoryInterface {

  private const CACHE_ID = 'reg_core:manual:outages';
  private const CACHE_TAG = 'reg_core:outages';

  private ?OutageRepositoryResult $requestResult = NULL;

  public function __construct(
    private readonly CacheBackendInterface $cache,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getOutages(array $filters = []): OutageRepositoryResult {
    if ($this->requestResult === NULL) {
      $cached = $this->cache->get(self::CACHE_ID);
      $this->requestResult = $cached && is_array($cached->data)
        ? new OutageRepositoryResult($cached->data, 'manual_drupal', '')
        : $this->refresh();
    }
    return $this->withFilters($this->requestResult, $filters);
  }

  /**
   * {@inheritdoc}
   */
  public function getOutage(string $outage_id): ?array {
    foreach ($this->getOutages()->outages as $outage) {
      if (hash_equals((string) $outage['outage_id'], $outage_id)) {
        return $outage;
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function refresh(): OutageRepositoryResult {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->condition('type', 'reg_outage')
        ->condition('status', NodeInterface::PUBLISHED)
        ->sort('field_reg_priority', 'DESC')
        ->sort('changed', 'DESC')
        ->range(0, 500)
        ->execute();
      $outages = [];
      foreach ($storage->loadMultiple($ids) as $node) {
        if ($node instanceof NodeInterface && $node->access('view')) {
          $outages[] = $this->normalize($node);
        }
      }
      usort($outages, [self::class, 'sortOutages']);
      $this->cacheTagsInvalidator->invalidateTags([self::CACHE_TAG]);
      $this->cache->set(self::CACHE_ID, $outages, $this->time->getRequestTime() + 60, [self::CACHE_TAG, 'node_list:reg_outage', 'media_list', 'file_list', 'taxonomy_term_list']);
      return $this->requestResult = new OutageRepositoryResult($outages, 'manual_drupal', '');
    }
    catch (\Throwable $exception) {
      $this->logger->error('Manual outage records could not be loaded: @message', ['@message' => $exception->getMessage()]);
      return $this->requestResult = new OutageRepositoryResult([], 'manual_drupal', '');
    }
  }

  /**
   * Orders ongoing first, then future soonest, then latest historical record.
   */
  public static function sortOutages(array $left, array $right): int {
    $rank = ['ongoing' => 0, 'awaiting_confirmation' => 1, 'scheduled' => 2, 'postponed' => 3, 'cancelled' => 4, 'restored' => 5, 'archived' => 6];
    $state = ($rank[$left['status']] ?? 9) <=> ($rank[$right['status']] ?? 9);
    if ($state !== 0) {
      return $state;
    }
    if ($left['status'] === 'scheduled') {
      return strcmp((string) $left['start_time'], (string) $right['start_time']);
    }
    return strcmp((string) $right['last_updated'], (string) $left['last_updated']);
  }

  /**
   * Converts one published, translated node to the public normalized model.
   */
  private function normalize(NodeInterface $node): array {
    $langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    if ($node->hasTranslation($langcode) && $node->getTranslation($langcode)->isPublished()) {
      $node = $node->getTranslation($langcode);
    }
    $segments = [];
    foreach ($node->get('field_reg_outage_segments') as $delta => $item) {
      $districts = $this->termLabels((string) $item->districts);
      $sectors = $this->termLabels((string) $item->sectors);
      $segments[] = [
        'order' => $delta,
        'start_time' => $this->iso((string) $item->start),
        'end_time' => $this->iso((string) $item->end),
        'feeder' => trim((string) $item->feeder),
        'substation' => trim((string) $item->substation),
        'districts' => $districts,
        'sectors' => $sectors,
        'affected_area' => trim(strip_tags((string) $item->affected_area)),
        'notes' => trim(strip_tags((string) $item->notes)),
        'restoration_status' => trim((string) $item->restoration_status),
        'actual_restoration' => $this->iso((string) $item->actual_restoration),
        'restored_early' => (bool) $item->restored_early,
      ];
    }
    usort($segments, static fn(array $a, array $b): int => strcmp((string) $a['start_time'], (string) $b['start_time']));
    $start = $segments[0]['start_time'] ?? NULL;
    $end = $segments ? ($segments[array_key_last($segments)]['end_time'] ?? NULL) : NULL;
    $operational = $this->value($node, 'field_reg_outage_status') ?: 'scheduled';
    $status = $this->displayStatus($operational, $segments);
    $districts = [];
    $sectors = [];
    foreach ($segments as $segment) {
      $districts = array_merge($districts, $segment['districts']);
      $sectors = array_merge($sectors, $segment['sectors']);
    }
    $districts = array_values(array_unique($districts));
    $sectors = array_values(array_unique($sectors));
    $actual = $this->iso($this->value($node, 'field_reg_actual_restoration'));
    return [
      'outage_id' => 'notice-' . $node->id(),
      'node_id' => (int) $node->id(),
      'title' => (string) $node->label(),
      'reference' => $this->value($node, 'field_reg_announcement_ref'),
      'announcement_date' => $this->value($node, 'field_reg_announcement_date'),
      'outage_type' => $this->value($node, 'field_reg_outage_type'),
      'operational_status' => $operational,
      'status' => $status,
      'start_time' => $start,
      'expected_restoration' => $end,
      'actual_restoration' => $actual,
      'restored_early' => (bool) $this->value($node, 'field_reg_restored_early'),
      'network_element' => $this->value($node, 'field_reg_network_element'),
      'districts' => $districts,
      'sectors' => $sectors,
      'district' => implode(', ', $districts),
      'sector' => implode(', ', $sectors),
      'affected_area' => implode('; ', array_values(array_filter(array_column($segments, 'affected_area')))),
      'public_reason' => $this->value($node, 'field_reg_reason'),
      'summary' => $this->value($node, 'field_reg_public_summary'),
      'safety_message' => $this->value($node, 'field_reg_safety_message') ?: (string) $this->configFactory->get('reg_core.settings')->get('outages.default_safety_message'),
      'customer_message' => $this->value($node, 'field_reg_customer_message') ?: (string) $this->configFactory->get('reg_core.settings')->get('outages.default_customer_message'),
      'status_note' => $this->value($node, 'field_reg_status_note'),
      'postponed_date' => $this->value($node, 'field_reg_postponed_date'),
      'segments' => $segments,
      'segment_count' => count($segments),
      'documents' => $this->documents($node),
      'featured' => (bool) $this->value($node, 'field_reg_featured'),
      'show_public_alert' => (bool) $this->value($node, 'field_reg_show_public_alert'),
      'priority' => (int) $this->value($node, 'field_reg_priority'),
      'last_updated' => gmdate(DATE_ATOM, $node->getChangedTime()),
    ];
  }

  /**
   * Derives time-aware display state without claiming restoration.
   */
  private function displayStatus(string $operational, array $segments): string {
    if (in_array($operational, ['restored', 'cancelled', 'postponed', 'archived'], TRUE) || $operational === 'ongoing') {
      return $operational;
    }
    $now = $this->time->getRequestTime();
    foreach ($segments as $segment) {
      $start = $segment['start_time'] ? strtotime($segment['start_time']) : 0;
      $end = $segment['end_time'] ? strtotime($segment['end_time']) : 0;
      if ($start <= $now && ($end === 0 || $end >= $now)) {
        return 'ongoing';
      }
    }
    $last = $segments ? end($segments) : [];
    return !empty($last['end_time']) && strtotime($last['end_time']) < $now ? 'awaiting_confirmation' : 'scheduled';
  }

  private function filter(array $outages, array $filters): array {
    $district = mb_strtolower(trim((string) ($filters['district'] ?? '')));
    $sector = mb_strtolower(trim((string) ($filters['sector'] ?? '')));
    $search = mb_strtolower(trim((string) ($filters['search'] ?? '')));
    $mode = mb_strtolower(trim((string) ($filters['mode'] ?? '')));
    $status_filter = mb_strtolower(trim((string) ($filters['status'] ?? '')));
    $date = trim((string) ($filters['date'] ?? ''));
    $type_filter = trim((string) ($filters['type'] ?? ''));
    return array_values(array_filter($outages, static function (array $outage) use ($district, $sector, $search, $mode, $status_filter, $date, $type_filter): bool {
      if ($mode === 'current' && !in_array($outage['status'], ['ongoing', 'awaiting_confirmation'], TRUE)) return FALSE;
      if ($mode === 'planned' && $outage['status'] !== 'scheduled') return FALSE;
      if (in_array($mode, ['history', 'resolved'], TRUE) && !in_array($outage['status'], ['restored', 'archived'], TRUE)) return FALSE;
      if ($status_filter !== '' && $outage['status'] !== $status_filter && $outage['operational_status'] !== $status_filter) return FALSE;
      if ($type_filter !== '' && $outage['outage_type'] !== $type_filter) return FALSE;
      if ($district !== '' && !in_array($district, array_map('mb_strtolower', $outage['districts']), TRUE)) return FALSE;
      if ($sector !== '' && !in_array($sector, array_map('mb_strtolower', $outage['sectors']), TRUE)) return FALSE;
      if ($date !== '' && !array_filter($outage['segments'], static fn(array $segment): bool => str_starts_with((string) $segment['start_time'], $date))) return FALSE;
      if ($search === '') return TRUE;
      $parts = [$outage['title'], $outage['network_element'], $outage['public_reason'], $outage['affected_area'], implode(' ', $outage['districts']), implode(' ', $outage['sectors'])];
      foreach ($outage['segments'] as $segment) $parts[] = implode(' ', [$segment['feeder'], $segment['substation'], $segment['affected_area']]);
      return str_contains(mb_strtolower(implode(' ', $parts)), $search);
    }));
  }

  private function withFilters(OutageRepositoryResult $result, array $filters): OutageRepositoryResult {
    return $filters === [] ? $result : new OutageRepositoryResult($this->filter($result->outages, $filters), $result->source, $result->message);
  }

  private function termLabels(string $ids): array {
    $ids = array_values(array_filter(array_map('intval', explode(',', $ids))));
    if (!$ids) return [];
    $labels = array_map(static fn($term): string => (string) $term->label(), $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($ids));
    natcasesort($labels);
    return array_values($labels);
  }

  private function documents(NodeInterface $node): array {
    $documents = [];
    foreach ($node->get('field_reg_official_documents')->referencedEntities() as $media) {
      if (!$media instanceof MediaInterface || !$media->isPublished()) continue;
      $source = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
      $file = $source !== '' ? $media->get($source)->entity : NULL;
      if ($file) $documents[] = ['label' => (string) $media->label(), 'url' => $this->fileUrlGenerator->generateString($file->getFileUri())];
    }
    return $documents;
  }

  private function value(NodeInterface $node, string $field): string {
    return !$node->hasField($field) || $node->get($field)->isEmpty() ? '' : trim(strip_tags((string) ($node->get($field)->value ?? '')));
  }

  private function iso(string $value): ?string {
    if ($value === '') return NULL;
    try { return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format(DATE_ATOM); }
    catch (\Exception) { return NULL; }
  }

}
