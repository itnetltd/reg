<?php

namespace Drupal\reg_core\Migration;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\MediaInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;

/** Controlled, idempotent importer for legacy REG News & Events. */
final class MediaCenterLegacyImporter {

  private const BASE = 'https://www.reg.rw';
  private const LISTING = self::BASE . '/media-center/news-events/';
  private const MAX_LISTING_PAGES = 500;

  /** @var array<string, string|null> */
  private array $responses = [];
  /** @var array<string, int> */
  private array $terms = [];

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly LoggerInterface $logger,
  ) {}

  /** Discovers, classifies, and optionally imports legacy News records. */
  public function run(array $options = []): array {
    $language = in_array(($options['language'] ?? ''), ['en', 'rw'], TRUE) ? $options['language'] : '';
    $dry_run = (bool) ($options['dry_run'] ?? FALSE);
    $update_existing = (bool) ($options['update_existing'] ?? FALSE);
    $limit = max(0, (int) ($options['limit'] ?? 0));
    $report = $this->emptyReport($dry_run, $language);
    $records = $this->discoverNews($report, $limit, $language);
    $report['failed'] += count($report['source_failures']);
    $report['discovered'] = count($records);
    $news_nodes = [];
    foreach ($records as $record) {
      $bucket = $this->bucket($record);
      $report['breakdown'][$bucket]['discovered']++;
      foreach ($record['issues'] as $issue) {
        $report['quality'][$issue]++;
      }
      if ($record['issues']) {
        $report['needs_review']++;
        $report['breakdown'][$bucket]['needs_review']++;
      }
      try {
        $outcome = $this->upsert($record, $dry_run, $update_existing, $report);
        $report[$outcome['status']]++;
        $report['breakdown'][$bucket][$outcome['status']]++;
        if (!$dry_run && $record['bundle'] === 'reg_news' && isset($outcome['node_id'])) {
          $news_nodes[] = ['node_id' => $outcome['node_id']] + $record;
        }
      }
      catch (\Throwable $exception) {
        $reason = $this->safeError($exception->getMessage());
        $report['failed']++;
        $report['breakdown'][$bucket]['failed']++;
        $report['failures'][] = ['source_url' => $record['source_url'], 'reason' => $reason];
        $this->logger->warning('Legacy REG News record failed: @url (@reason)', ['@url' => $record['source_url'], '@reason' => $reason]);
      }
    }
    $this->linkTranslationPairs($dry_run ? $records : $news_nodes, $report, !$dry_run);
    $report['completed_at'] = gmdate(DATE_ATOM, $this->time->getCurrentTime());
    return $report;
  }

  /** Discovers News detail URLs while following bounded listing pagination. */
  private function discoverNews(array &$report, int $limit, string $language): array {
    $queue = [self::LISTING];
    $seen_pages = [];
    $seen_details = [];
    $records = [];
    while ($queue && count($seen_pages) < self::MAX_LISTING_PAGES) {
      $url = array_shift($queue);
      $key = $this->discoveryKey($url);
      if (isset($seen_pages[$key])) {
        continue;
      }
      $seen_pages[$key] = TRUE;
      $html = $this->fetch($url, $report);
      if ($html === NULL) {
        continue;
      }
      [, $xpath] = $this->dom($html);
      foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
        $href = $this->resolve($anchor->getAttribute('href'));
        if (!$href) {
          continue;
        }
        $path = (string) parse_url($href, PHP_URL_PATH);
        if (str_starts_with($path, '/media-center/news-details/news/')) {
          $source = $this->canonical($href);
          if (isset($seen_details[$source])) {
            continue;
          }
          $seen_details[$source] = TRUE;
          $detail_html = $this->fetch($source, $report);
          $record = $detail_html === NULL ? NULL : $this->parseNews([
            'source_url' => $source,
            'listing_date' => $this->listingDate($anchor, $url),
          ], $detail_html);
          if ($record === NULL) {
            $report['skipped']++;
            continue;
          }
          if ($language !== '' && $record['langcode'] !== $language) {
            continue;
          }
          $records[] = $record;
          if ($limit > 0 && count($records) >= $limit) {
            break 2;
          }
        }
        elseif ($this->newsIndex($path)) {
          $queue[] = $href;
        }
      }
    }
    $report['source_pages_scanned'] += count($seen_pages);
    return $records;
  }

  private function parseNews(array $stub, string $html): ?array {
    [$dom, $xpath] = $this->dom($html);
    $article = $xpath->query('//div[contains(@class,\'article\')]')->item(0);
    if (!$article) {
      return NULL;
    }
    $title_node = $xpath->query('.//div[contains(@class,\'event_title\')]//h3', $article)->item(0);
    $title = $this->text($title_node);
    if ($title === '') {
      return NULL;
    }
    $parts = [];
    $body_nodes = $xpath->query('.//div[contains(@class,\'event_title\')]/following-sibling::*', $article);
    foreach ($body_nodes ?: [] as $node) {
      if (in_array($node->nodeName, ['p', 'blockquote', 'ul', 'ol', 'h2', 'h3'], TRUE)) {
        $parts[] = $dom->saveHTML($node);
      }
    }
    $body = trim(implode('', $parts));
    return $this->newsRecord($stub, $xpath, $title, $body, $this->plain($body));
  }

  private function newsRecord(array $stub, \DOMXPath $xpath, string $title, string $body, string $plain): array {
    $date = $this->articleDate($xpath, $title . ' ' . $plain, $stub['listing_date']);
    [$langcode, $language_certain] = $this->language($title . ' ' . $plain);
    [$section, $sport, $classification_certain] = $this->classifyNews($title . ' ' . $plain);
    $issues = [];
    if (!$date) $issues[] = 'needs_date';
    if (!$language_certain) $issues[] = 'needs_language';
    if (!$classification_certain) $issues[] = 'needs_classification';
    $images = [];
    $image = $xpath->query('//meta[@property=\'og:image\']/@content')->item(0)
      ?: $xpath->query('//div[contains(@class,\'news-single\')]//div[contains(@class,\'event_img\')]//img[1]')->item(0);
    if ($image) {
      $attribute = $image instanceof \DOMAttr ? $image->nodeValue : $image->getAttribute('src');
      $url = $this->resolve($attribute);
      if ($url && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/fileadmin/')) {
        $alt = $image instanceof \DOMElement ? trim($image->getAttribute('alt')) : '';
        $images[$this->canonical($url)] = $alt;
      }
    }
    $source_url = $this->canonical($stub['source_url']);
    $record = [
      'bundle' => 'reg_news', 'type' => $section === 'sports' ? 'sports' : 'news',
      'title' => $title, 'summary' => Unicode::truncate($plain, 280, TRUE, TRUE),
      'body' => $body, 'date' => $date, 'langcode' => $langcode,
      'section' => $section, 'sport' => $sport,
      'category' => $section === 'sports' ? 'Sports' : 'Corporate News',
      'source_url' => $source_url,
      'source_id' => basename(rtrim((string) parse_url($source_url, PHP_URL_PATH), '/')),
      'assets' => array_keys($images), 'asset_alts' => $images, 'issues' => $issues,
    ];
    $record['source_hash'] = $this->recordHash($record);
    return $record;
  }

  /** Performs duplicate-safe node creation or conservative enrichment. */
  private function upsert(array $record, bool $dry_run, bool $update_existing, array &$report): array {
    $existing = $this->findExisting($record);
    if ($dry_run) {
      return ['status' => $existing ? 'existing' : 'would_create'];
    }
    if ($existing && $this->value($existing, 'field_reg_source_hash') === $record['source_hash']) {
      return ['status' => 'existing', 'node_id' => (int) $existing->id()];
    }
    if ($existing && (!$update_existing || !$this->sourceManaged($existing, $record))) {
      $this->enrichExisting($existing, $record, $report);
      return ['status' => 'existing', 'node_id' => (int) $existing->id()];
    }

    $review = $this->primaryReviewStatus($record['issues']);
    if ($existing) {
      $node = $existing;
      $node->setTitle($record['title']);
      $node->set('field_reg_summary', $record['summary']);
      $node->set('body', ['value' => $record['body'], 'format' => 'basic_html']);
    }
    else {
      $created = $record['date'] ? strtotime($record['date'] . ' UTC') : $this->time->getCurrentTime();
      $node = Node::create([
        'type' => 'reg_news', 'title' => $record['title'],
        'langcode' => $record['langcode'], 'uid' => 1, 'created' => $created,
        'status' => $record['issues'] === [] ? NodeInterface::PUBLISHED : NodeInterface::NOT_PUBLISHED,
      ]);
      $node->set('field_reg_summary', $record['summary']);
      $node->set('body', ['value' => $record['body'], 'format' => 'basic_html']);
      $node->set('field_reg_news_category', 'news');
    }

    $this->setIfField($node, 'field_reg_publication_date', $record['date'] ? $record['date'] . 'T12:00:00' : NULL);
    $this->setIfField($node, 'field_reg_external_url', ['uri' => $record['source_url']]);
    $this->setIfField($node, 'field_reg_source_id', $record['source_id']);
    $this->setIfField($node, 'field_reg_source_hash', $record['source_hash']);
    if (!$existing || $node->get('field_reg_imported_date')->isEmpty()) {
      $this->setIfField($node, 'field_reg_imported_date', gmdate('Y-m-d', $this->time->getCurrentTime()));
    }
    $this->setIfField($node, 'field_reg_migration_status', $existing ? 'updated' : 'imported');
    $this->setIfField($node, 'field_reg_migration_review', $review);
    $this->setRecordFields($node, $record, $report);
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', $record['issues'] === [] ? 'published' : 'draft');
    }
    $node->save();
    return ['status' => $existing ? 'updated' : 'created', 'node_id' => (int) $node->id()];
  }

  private function setRecordFields(NodeInterface $node, array $record, array &$report): void {
    $this->setIfField($node, 'field_reg_news_section', $record['section']);
    $this->setIfField($node, 'field_reg_news_category_term', ['target_id' => $this->term('reg_news_category', $record['category'])]);
    if ($record['sport']) {
      $this->setIfField($node, 'field_reg_sports_sport', ['target_id' => $this->term('reg_sports_sport', $record['sport'])]);
    }
    if ($record['assets']) {
      $alt = $record['asset_alts'][$record['assets'][0]] ?: $record['title'];
      if ($media = $this->importImage($record['assets'][0], $alt, $report)) {
        $this->setIfField($node, 'field_reg_featured_image', ['target_id' => $media->id()]);
      }
    }
  }

  /** Locates duplicates in source URL, source ID, then title/date order. */
  private function findExisting(array $record): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ([['field_reg_external_url.uri', $record['source_url']], ['field_reg_source_id', $record['source_id']]] as [$field, $value]) {
      if ($value === '') {
        continue;
      }
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $record['bundle'])
        ->condition($field, $value)->range(0, 1)->execute();
      if ($ids && ($node = $storage->load(reset($ids))) instanceof NodeInterface) return $node;
    }
    if (!$record['date']) {
      return NULL;
    }
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', $record['bundle'])
      ->condition('langcode', $record['langcode'])
      ->condition('title', $record['title'])
      ->condition('field_reg_publication_date', $record['date'] . 'T00:00:00', '>=')
      ->condition('field_reg_publication_date', $record['date'] . 'T23:59:59', '<=');
    $ids = $query->range(0, 1)->execute();
    $node = $ids ? $storage->load(reset($ids)) : NULL;
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /** Whether an existing match carries this legacy record's stable identity. */
  private function sourceManaged(NodeInterface $node, array $record): bool {
    return $this->value($node, 'field_reg_external_url') === $record['source_url']
      || ($record['source_id'] !== '' && $this->value($node, 'field_reg_source_id') === $record['source_id']);
  }

  /** Enriches a manual duplicate without replacing editorial fields. */
  private function enrichExisting(NodeInterface $node, array $record, array &$report): void {
    $changed = FALSE;
    foreach ([
      'field_reg_external_url' => ['uri' => $record['source_url']],
      'field_reg_source_id' => $record['source_id'],
      'field_reg_source_hash' => $record['source_hash'],
      'field_reg_migration_status' => 'unchanged',
      'field_reg_migration_review' => $this->primaryReviewStatus($record['issues']),
    ] as $field => $value) {
      if ($node->hasField($field) && $node->get($field)->isEmpty()) {
        $node->set($field, $value);
        $changed = TRUE;
      }
    }
    if ($record['date'] && $node->hasField('field_reg_publication_date') && $node->get('field_reg_publication_date')->isEmpty()) {
      $node->set('field_reg_publication_date', $record['date'] . 'T12:00:00');
      $changed = TRUE;
    }
    if ($record['assets'] && $node->hasField('field_reg_featured_image') && $node->get('field_reg_featured_image')->isEmpty()) {
      $alt = $record['asset_alts'][$record['assets'][0]] ?: $record['title'];
      if ($media = $this->importImage($record['assets'][0], $alt, $report)) {
        $node->set('field_reg_featured_image', ['target_id' => $media->id()]);
        $changed = TRUE;
      }
    }
    if ($changed) $node->save();
  }

  /** Downloads an image and reuses File/Media entities by checksum. */
  private function importImage(string $url, string $alt, array &$report): ?MediaInterface {
    $contents = $this->fetchBinary($url, $report);
    if ($contents === NULL) {
      $report['media']['missing']++;
      return NULL;
    }
    $hash = hash('sha256', $contents);
    $image_info = @getimagesizefromstring($contents);
    $extensions = [
      'image/jpeg' => 'jpg',
      'image/png' => 'png',
      'image/gif' => 'gif',
      'image/webp' => 'webp',
    ];
    $extension = $extensions[$image_info['mime'] ?? ''] ?? NULL;
    if ($extension === NULL) {
      $report['media']['missing']++;
      return NULL;
    }
    $path = (string) parse_url($url, PHP_URL_PATH);
    $directory = 'public://legacy-reg/images';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $directory . '/' . $hash . '.' . $extension;
    $file_storage = $this->entityTypeManager->getStorage('file');
    $ids = $file_storage->getQuery()->accessCheck(FALSE)->condition('uri', $uri)->range(0, 1)->execute();
    $file = $ids ? $file_storage->load(reset($ids)) : NULL;
    if ($file) {
      $report['media']['duplicates_reused']++;
    }
    else {
      $file = $this->fileRepository->writeData($contents, $uri, FileExists::Replace);
      $file->setPermanent();
      $file->save();
      $report['media']['images_imported']++;
    }
    $source_field = 'field_media_image';
    $media_storage = $this->entityTypeManager->getStorage('media');
    $media_ids = $media_storage->getQuery()->accessCheck(FALSE)->condition('bundle', 'image')
      ->condition($source_field . '.target_id', $file->id())->range(0, 1)->execute();
    $media = $media_ids ? $media_storage->load(reset($media_ids)) : NULL;
    if (!$media instanceof MediaInterface) {
      $value = ['target_id' => $file->id()];
      $value['alt'] = Unicode::truncate(trim($alt), 512, TRUE, TRUE);
      $media = $media_storage->create([
        'bundle' => 'image', 'name' => Unicode::truncate(basename($path), 250, TRUE, TRUE),
        'uid' => 1, 'status' => 1, $source_field => $value,
      ]);
      $media->save();
    }
    return $media;
  }

  /** Classifies sports only from clear text and known REG team names. */
  public function classifyNews(string $text): array {
    $normal = mb_strtolower($this->plain($text));
    $basketball = (bool) preg_match('/\b(reg basketball(?: club)?|reg bbc|reg wbbc|basketball)\b/u', $normal);
    $volleyball = (bool) preg_match('/\b(reg volleyball(?: club)?|reg vc|volleyball)\b/u', $normal);
    $known_team = (bool) preg_match('/\b(reg basketball club|reg bbc|reg wbbc|reg volleyball club|reg vc)\b/u', $normal);
    $competition = (bool) preg_match('/\b(match(?:es)?|matchday|games?|league|champion(?:ship)?s?|tournaments?|sports? competitions?|fixtures?|finals?|semi-finals?|season|cup|imikino|imikino ngororamubiri|shampiyona|amarushanwa|irushanwa)\b/u', $normal);
    $people = (bool) preg_match('/\b(players?|coaches?|athletes?|abakinnyi|umukinnyi|abatoza|umutoza)\b/u', $normal);
    $result = (bool) preg_match('/\b(wins?|won|victory|beat|defeated?|scor(?:e|ed|ing)|bronze|silver|gold|runner-up|champions?|yatsinze|yegukanye|igikombe|amanota)\b/u', $normal);

    if ($known_team || $basketball || $volleyball || ($people && ($competition || $result)) || ($competition && $result)) {
      $sport = $volleyball ? 'Volleyball' : ($basketball ? 'Basketball' : '');
      return ['sports', $sport, TRUE];
    }

    // A lone generic sports word is not enough to override corporate. Flag it
    // for a person to review instead of trusting a legacy category.
    $ambiguous = (bool) preg_match('/\b(reg (?:bc|club|team)|sports?|match(?:es)?|games?|champion(?:ship)?s?|tournaments?|league|players?|coaches?|imikino|shampiyona|amarushanwa)\b/u', $normal);
    return ['corporate', '', !$ambiguous];
  }

  /** Determines language conservatively. */
  private function language(string $text): array {
    $normal = ' ' . mb_strtolower($this->plain($text)) . ' ';
    $rw_hits = preg_match_all('/\b(u rwanda|amakuru|itangazo|abanyarwanda|amashanyarazi|umuriro|umushinga|imishinga|yatangaje|yakiriye|igihugu|abakozi|mu rwego|kuri uyu|yegukanye|yatsinze|abakinnyi|umukinnyi|abatoza|umutoza|imikino|shampiyona|amarushanwa|irushanwa|igikombe|kugira ngo|binyuze mu|ku bufatanye)\b/u', $normal);
    $en_hits = preg_match_all('/\b(the|and|with|from|this|energy|electricity|project|rwanda energy group|said|has|have|were|will|club|team|players?|coaches?|match(?:es)?|championships?|tournament|won|wins|hosted|announced|signed|during|through)\b/u', $normal);
    if ($rw_hits >= 2 && $rw_hits > $en_hits) return ['rw', TRUE];
    if ($en_hits >= 2 && $en_hits > $rw_hits) return ['en', TRUE];
    return [$rw_hits > 0 ? 'rw' : 'en', FALSE];
  }

  /** Extracts a complete original date without inventing a year. */
  private function articleDate(\DOMXPath $xpath, string $text, ?string $listing_date): ?string {
    foreach (['//meta[@property=\'article:published_time\']/@content', '//meta[@name=\'date\']/@content', '//time/@datetime'] as $query) {
      foreach ($xpath->query($query) ?: [] as $node) {
        if ($date = $this->dateFromText($node->nodeValue)) return $date;
      }
    }
    $context_date = $this->dateFromText($text) ?: $listing_date;
    $display_node = $xpath->query('//div[contains(@class,\'event_img_date\')]')->item(0);
    $display = $this->text($display_node);
    if ($context_date && preg_match('/\b(\d{1,2})\s*(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|Nov(?:ember)?|Dec(?:ember)?)\b/i', $display, $match)) {
      try {
        return (new \DateTimeImmutable($match[1] . ' ' . $match[2] . ' ' . substr($context_date, 0, 4), new \DateTimeZone('UTC')))->format('Y-m-d');
      }
      catch (\Throwable) {}
    }
    return $context_date;
  }

  private function dateFromText(string $text): ?string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $patterns = [
      ['/\b(20\d{2}|19\d{2})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/', 'ymd'],
      ['/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](20\d{2}|19\d{2})\b/', 'dmy'],
      ['/\b(\d{1,2})(?:st|nd|rd|th)?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s*,?\s*(20\d{2}|19\d{2})\b/iu', 'word'],
      ['/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?\s*,?\s*(20\d{2}|19\d{2})\b/iu', 'month'],
    ];
    foreach ($patterns as [$pattern, $order]) {
      if (!preg_match($pattern, $text, $match)) continue;
      try {
        $raw = match ($order) {
          'ymd' => sprintf('%04d-%02d-%02d', $match[1], $match[2], $match[3]),
          'dmy' => sprintf('%04d-%02d-%02d', $match[3], $match[2], $match[1]),
          'word' => $match[1] . ' ' . $match[2] . ' ' . $match[3],
          default => $match[2] . ' ' . $match[1] . ' ' . $match[3],
        };
        return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->format('Y-m-d');
      }
      catch (\Throwable) {}
    }
    return NULL;
  }

  private function listingDate(\DOMNode $anchor, string $listing_url): ?string {
    $container = $anchor->parentNode;
    for ($i = 0; $i < 4 && $container; $i++, $container = $container->parentNode) {
      if ($date = $this->dateFromText($this->text($container))) return $date;
    }
    return $this->dateFromText(urldecode($listing_url));
  }

  /** Fetches a source page with strict host/path controls. */
  private function fetch(string $url, array &$report): ?string {
    $url = $this->canonical($url, TRUE);
    if (array_key_exists($url, $this->responses)) return $this->responses[$url];
    if (!$this->allowed($url)) return $this->responses[$url] = NULL;
    try {
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => ['max' => 4, 'strict' => TRUE],
        'connect_timeout' => 10, 'timeout' => 35,
        'headers' => ['User-Agent' => 'REG Drupal controlled legacy migration/1.0'],
      ]);
      return $this->responses[$url] = (string) $response->getBody();
    }
    catch (\Throwable $exception) {
      $report['source_failures'][] = ['url' => $url, 'reason' => $this->safeError($exception->getMessage())];
      return $this->responses[$url] = NULL;
    }
  }

  private function fetchBinary(string $url, array &$report): ?string {
    if (!$this->allowed($url, TRUE)) return NULL;
    try {
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => ['max' => 4, 'strict' => TRUE],
        'connect_timeout' => 10, 'timeout' => 60,
        'headers' => ['User-Agent' => 'REG Drupal controlled legacy migration/1.0'],
      ]);
      $contents = (string) $response->getBody();
      return $contents !== '' && strlen($contents) <= 20 * 1024 * 1024 ? $contents : NULL;
    }
    catch (\Throwable $exception) {
      $report['media']['failures'][] = ['url' => $url, 'reason' => $this->safeError($exception->getMessage())];
      return NULL;
    }
  }

  private function allowed(string $url, bool $asset = FALSE): bool {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (!in_array($host, ['www.reg.rw', 'reg.rw'], TRUE)) return FALSE;
    if ($asset) {
      return str_starts_with($path, '/fileadmin/');
    }
    return str_starts_with($path, '/media-center/news-events')
      || str_starts_with($path, '/media-center/news-details/news/');
  }

  private function resolve(string $href): ?string {
    $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($href === '' || preg_match('@^(#|javascript:|mailto:)@i', $href)) return NULL;
    if (str_starts_with($href, '//')) $href = 'https:' . $href;
    elseif (!preg_match('@^https?://@i', $href)) $href = self::BASE . '/' . ltrim($href, '/');
    $asset = str_starts_with((string) parse_url($href, PHP_URL_PATH), '/fileadmin/');
    return $this->allowed($href, $asset) ? $href : NULL;
  }

  private function canonical(string $url, bool $keep_query = FALSE): string {
    $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $path = preg_replace('@/{2,}@', '/', $parts['path'] ?? '/');
    $query = '';
    if ($keep_query && isset($parts['query'])) {
      parse_str($parts['query'], $params);
      if ($params) $query = '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
    return self::BASE . $path . $query;
  }

  /** Deduplicates equivalent TYPO3 pages without invalidating fetch URLs. */
  private function discoveryKey(string $url): string {
    $parts = parse_url(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $params = [];
    if (isset($parts['query'])) parse_str($parts['query'], $params);
    unset($params['cHash']);
    $query = $params ? '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986) : '';
    return self::BASE . preg_replace('@/{2,}@', '/', $parts['path'] ?? '/') . $query;
  }

  private function newsIndex(string $path): bool {
    return $path === '/media-center/' || str_starts_with($path, '/media-center/news-events');
  }

  private function dom(string $html): array {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding=utf-8 ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return [$dom, new \DOMXPath($dom)];
  }

  private function text(?\DOMNode $node): string {
    return $node ? trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '';
  }

  private function plain(string $value): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  }

  /** Detects and optionally links high-confidence translation counterparts. */
  private function linkTranslationPairs(array $records, array &$report, bool $write): void {
    $by_date = [];
    foreach ($records as $record) if ($record['date']) $by_date[$record['date']][] = $record;
    $storage = $write ? $this->entityTypeManager->getStorage('node') : NULL;
    foreach ($by_date as $date => $candidates) {
      foreach ($candidates as $left) {
        foreach ($candidates as $right) {
          if (($left['source_url'] ?? '') === ($right['source_url'] ?? '')
            || $left['langcode'] === $right['langcode']
            || $left['section'] !== $right['section']
            || !$this->likelyPair($left['title'], $right['title'])) {
            continue;
          }
          if ($write && isset($left['node_id'], $right['node_id'])) {
            foreach ([[$left, $right], [$right, $left]] as [$source, $target]) {
              $node = $storage?->load($source['node_id']);
              if ($node instanceof NodeInterface
                && $node->hasField('field_reg_related_translation')
                && $node->get('field_reg_related_translation')->isEmpty()) {
                $node->set('field_reg_related_translation', ['target_id' => $target['node_id']]);
                $node->save();
              }
            }
          }
          $report['translation_pairs']++;
          break 2;
        }
      }
    }
  }

  private function likelyPair(string $left, string $right): bool {
    return array_intersect($this->counterpartAnchors($left), $this->counterpartAnchors($right)) !== [];
  }

  /** Returns story-specific anchors shared unchanged across en/rw headlines. */
  private function counterpartAnchors(string $title): array {
    $normal = mb_strtolower($this->plain($title));
    $anchors = [];
    $aliases = [
      'eapp' => ['eapp', 'eastern africa power pool'],
      'nyabihu' => ['nyabihu'],
      'rubavu' => ['rubavu'],
      'gisagara' => ['gisagara'],
      'australia' => ['australia'],
      'zimbabwe' => ['zimbabwe'],
      'belgium' => ['belgium', 'ububiligi'],
      'tid' => ['tid'],
      'be-earp' => ['be-earp', 'bearp'],
      'reg-bbc' => ['reg bbc'],
      'reg-wbbc' => ['reg wbbc'],
      'reg-vc' => ['reg vc'],
    ];
    foreach ($aliases as $anchor => $phrases) {
      foreach ($phrases as $phrase) {
        if (str_contains($normal, $phrase)) {
          $anchors[$anchor] = TRUE;
          break;
        }
      }
    }
    if (preg_match_all('/\b\d+(?:[.,]\d+)?\s*(?:mw|kv|mva|million|miliyoni|billion|miliyari)\b/u', $normal, $measurements)) {
      foreach ($measurements[0] as $measurement) {
        $anchors['measure:' . preg_replace('/\s+/u', '', str_replace([',', 'miliyoni', 'miliyari'], ['', 'million', 'billion'], $measurement))] = TRUE;
      }
    }
    if (preg_match_all('/\b[A-Z][A-Z0-9-]{2,}\b/', $this->plain($title), $acronyms)) {
      foreach ($acronyms[0] as $acronym) {
        if ($acronym !== 'REG') $anchors['acronym:' . $acronym] = TRUE;
      }
    }
    return array_keys($anchors);
  }

  private function recordHash(array $record): string {
    return hash('sha256', json_encode([
      $record['title'], $record['body'], $record['date'], $record['langcode'],
      $record['section'], $record['category'], $record['assets'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
  }

  private function bucket(array $record): string {
    if ($record['bundle'] === 'reg_news') return ($record['section'] === 'sports' ? 'sports_' : 'corporate_') . $record['langcode'];
    return $record['type'];
  }

  private function emptyReport(bool $dry_run, string $language): array {
    $counts = [
      'discovered' => 0,
      'would_create' => 0,
      'created' => 0,
      'existing' => 0,
      'updated' => 0,
      'skipped' => 0,
      'needs_review' => 0,
      'failed' => 0,
    ];
    $breakdown = [];
    foreach (['corporate_en', 'corporate_rw', 'sports_en', 'sports_rw'] as $bucket) {
      $breakdown[$bucket] = $counts;
    }
    return $counts + [
      'dry_run' => $dry_run, 'language' => $language ?: 'all',
      'source' => self::BASE, 'started_at' => gmdate(DATE_ATOM, $this->time->getCurrentTime()),
      'completed_at' => NULL, 'source_pages_scanned' => 0, 'translation_pairs' => 0,
      'breakdown' => $breakdown,
      'media' => ['images_imported' => 0, 'duplicates_reused' => 0, 'missing' => 0, 'failures' => []],
      'quality' => ['needs_date' => 0, 'needs_language' => 0, 'needs_classification' => 0, 'duplicate_candidate' => 0],
      'source_failures' => [], 'failures' => [],
    ];
  }

  private function primaryReviewStatus(array $issues): string {
    foreach (['needs_classification', 'needs_file', 'needs_date', 'needs_language', 'duplicate_candidate'] as $issue) {
      if (in_array($issue, $issues, TRUE)) return $issue;
    }
    return 'verified';
  }

  private function term(string $vocabulary, string $name): int {
    $key = $vocabulary . ':' . mb_strtolower($name);
    if (isset($this->terms[$key])) return $this->terms[$key];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('vid', $vocabulary)->condition('name', $name)->range(0, 1)->execute();
    if ($ids) return $this->terms[$key] = (int) reset($ids);
    $term = Term::create(['vid' => $vocabulary, 'name' => $name]);
    $term->save();
    return $this->terms[$key] = (int) $term->id();
  }

  private function setIfField(NodeInterface $node, string $field, mixed $value): void {
    if ($node->hasField($field) && $value !== NULL && $value !== '') $node->set($field, $value);
  }

  private function value(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) return '';
    $item = $node->get($field)->first();
    $values = $item?->getValue() ?? [];
    return trim((string) ($values['value'] ?? $values['uri'] ?? $values['target_id'] ?? ''));
  }

  private function safeError(string $message): string {
    return Unicode::truncate(preg_replace('@https?://[^\s]+@', '[source URL]', $message), 500, TRUE, TRUE);
  }

}
