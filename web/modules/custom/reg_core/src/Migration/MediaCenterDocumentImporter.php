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

/** Controlled legacy Media Center document importer. */
final class MediaCenterDocumentImporter {

  private const BASE = 'https://www.reg.rw';
  private const TYPES = [
    'press-releases' => [
      'listing' => '/media-center/press-releases/',
      'detail_prefix' => '/media-center/details/news/',
      'label' => 'Press Release',
      'category' => 'press_release',
    ],
    'publications' => [
      'listing' => '/media-center/publications/',
      'label' => 'Publication',
      'category' => 'publication',
    ],
    'announcements' => [
      'listing' => '/media-center/announcements/',
      'detail_prefix' => '/media-center/announcements/announcements-details/news/',
      'label' => 'Announcement Archive',
      'category' => 'archived_announcement',
    ],
    'newsletters' => [
      'listing' => '/media-center/newsletter/',
      'label' => 'Newsletter',
      'category' => 'newsletter',
    ],
    'company-laws' => [
      'listing' => '/media-center/company-laws/',
      'label' => 'Corporate / Legal Documents',
      'category' => 'corporate_legal',
    ],
  ];

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

  /** Discovers and optionally imports one approved legacy document type. */
  public function run(array $options): array {
    $type = (string) ($options['type'] ?? '');
    if (!isset(self::TYPES[$type])) {
      throw new \InvalidArgumentException('Unsupported Media Center document type.');
    }
    $dry_run = (bool) ($options['dry_run'] ?? FALSE);
    $limit = max(0, (int) ($options['limit'] ?? 0));
    $update_existing = (bool) ($options['update_existing'] ?? FALSE);
    $report = $this->emptyReport($type, $dry_run);
    $records = $this->discover($type, $limit, $report);
    $report['discovered'] = count($records);
    $report['failed'] += count($report['source_failures']);
    foreach (array_slice($records, 0, 5) as $record) {
      $report['sample'][] = array_intersect_key($record, array_flip([
        'title', 'date', 'language', 'category', 'publication_type',
        'issue_number', 'archived_outage',
        'document_url', 'source_url', 'source_id',
      ]));
    }

    foreach ($records as $record) {
      if ($record['issues']) {
        $report['needs_review']++;
        foreach ($record['issues'] as $issue) {
          $report['quality'][$issue]++;
        }
      }
      try {
        $outcome = $this->upsert($record, $dry_run, $update_existing, $report);
        $report[$outcome]++;
      }
      catch (\Throwable $exception) {
        $report['failed']++;
        $report['failures'][] = [
          'source_url' => $record['source_url'],
          'reason' => $this->safeError($exception->getMessage()),
        ];
        $this->logger->warning('Legacy REG document failed: @url (@reason)', [
          '@url' => $record['source_url'],
          '@reason' => $this->safeError($exception->getMessage()),
        ]);
      }
    }
    return $report;
  }

  /** Follows only the selected listing, its categories, and pagination. */
  private function discover(string $type, int $limit, array &$report): array {
    $queue = [$this->canonical(self::BASE . self::TYPES[$type]['listing'], TRUE)];
    $seen_pages = [];
    $seen_records = [];
    $records = [];
    while ($queue && count($seen_pages) < 250) {
      $page_url = array_shift($queue);
      $page_key = $this->discoveryKey($page_url);
      if (isset($seen_pages[$page_key])) {
        continue;
      }
      $seen_pages[$page_key] = TRUE;
      $html = $this->fetch($page_url, $type, $report);
      if ($html === NULL) {
        continue;
      }
      [, $xpath] = $this->dom($html);
      foreach ($xpath->query("//div[contains(@class, 'new_tendetable')]//tbody/tr") ?: [] as $row) {
        $title_cell = $xpath->query(".//td[@data-title='Title']", $row)->item(0);
        $category_cell = $xpath->query(".//td[@data-title='Category']", $row)->item(0);
        $title_anchor = $xpath->query(".//td[@data-title='Title']//a[@href]", $row)->item(0);
        $document_anchor = $xpath->query(".//td[@data-title='Download']//a[@href]", $row)->item(0);
        $source_anchor = $document_anchor ?: $title_anchor;
        if (!$title_cell || !$source_anchor) {
          continue;
        }
        $href = $this->resolve($source_anchor->getAttribute('href'), $type, (bool) $document_anchor);
        if (!$href) continue;
        $path = (string) parse_url($href, PHP_URL_PATH);
        $document_url = $this->isDocumentPath($path) ? $href : '';
        $source_url = $document_url ?: $this->canonical($href);
        if (isset($seen_records[$source_url])) {
          continue;
        }
        $seen_records[$source_url] = TRUE;
        $stub = [
          'source_url' => $source_url,
          'title' => $this->text($title_cell),
          'listing_date' => $this->listingDate($title_cell),
          'listing_url' => $page_url,
          'document_url' => $document_url,
          'legacy_category' => $this->text($category_cell),
        ];
        $record = $document_url
          ? $this->buildRecord($type, $stub)
          : $this->parseDetail($type, $stub, $report);
        if ($record) {
          $records[] = $record;
          if ($limit > 0 && count($records) >= $limit) {
            break 2;
          }
        }
      }
      foreach ($xpath->query("//div[contains(@class, 'tender_pahination')]//a[@href]") ?: [] as $anchor) {
        $href = $this->resolve($anchor->getAttribute('href'), $type);
        if ($href && $this->isDiscoveryLink($href, $type)) {
          $queue[] = $href;
        }
      }
    }
    $report['source_pages_scanned'] = count($seen_pages);
    return $records;
  }

  /** Parses a TYPO3 detail page and extracts its locally hosted document. */
  private function parseDetail(string $type, array $stub, array &$report): ?array {
    $html = $this->fetch($stub['source_url'], $type, $report);
    if ($html === NULL) {
      return NULL;
    }
    [, $xpath] = $this->dom($html);
    $article = $xpath->query("//div[contains(@class, 'news-single')]//div[contains(@class, 'article')]")->item(0);
    if (!$article) {
      return NULL;
    }
    $headline = $xpath->query(".//*[@itemprop='headline']", $article)->item(0)
      ?: $xpath->query('.//h3', $article)->item(0);
    $title = $this->text($headline) ?: $stub['title'];
    $document_url = '';
    foreach ($xpath->query('.//a[@href]', $article) ?: [] as $anchor) {
      $candidate = $this->resolve($anchor->getAttribute('href'), $type, TRUE);
      if ($candidate && $this->isDocumentPath((string) parse_url($candidate, PHP_URL_PATH))) {
        $document_url = $candidate;
        break;
      }
    }
    $stub['title'] = $title;
    $stub['document_url'] = $document_url;
    $stub['listing_date'] = $this->originalDate($xpath, $this->text($article), $stub['listing_date']);
    return $this->buildRecord($type, $stub);
  }

  /** Normalizes one source item to the existing reg_publication schema. */
  private function buildRecord(string $type, array $stub): ?array {
    $title = trim($stub['title']);
    if ($title === '') {
      $title = pathinfo(basename((string) parse_url($stub['document_url'], PHP_URL_PATH)), PATHINFO_FILENAME);
      $title = trim(str_replace(['_', '-'], ' ', $title));
    }
    if ($title === '') {
      return NULL;
    }
    $date = $stub['listing_date'];
    [$language, $language_certain] = $this->language($title . ' ' . $stub['document_url']);
    $legacy_category = trim($stub['legacy_category'] ?? '');
    $category = $this->category($type, $stub['listing_url'], $title, $legacy_category);
    $issue_number = $type === 'newsletters'
      ? $this->issueNumber($title . ' ' . $stub['document_url'])
      : '';
    $archived_outage = $type === 'announcements'
      && $this->historicalOutage($title);
    $source_url = $this->canonical($stub['source_url']);
    $source_id = pathinfo(basename(rtrim((string) parse_url($source_url, PHP_URL_PATH), '/')), PATHINFO_FILENAME);
    $issues = [];
    if (!$date) $issues[] = 'needs_date';
    if (!$language_certain) $issues[] = 'needs_language';
    if ($stub['document_url'] === '') $issues[] = 'needs_file';
    $record = [
      'title' => $title, 'date' => $date, 'language' => $language,
      'category' => $category,
      'publication_type' => $legacy_category ?: self::TYPES[$type]['label'],
      'issue_number' => $issue_number,
      'archived_outage' => $archived_outage,
      'document_url' => $stub['document_url'], 'source_url' => $source_url,
      'source_id' => $source_id, 'issues' => $issues,
    ];
    $record['source_hash'] = hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    return $record;
  }

  /** Determines the official document language without using page chrome. */
  public function language(string $text): array {
    $normal = ' ' . mb_strtolower($this->plain($text)) . ' ';
    if (preg_match('/\b(kinyarwanda|ikinyarwanda)\b/u', $normal)) {
      return ['rw', TRUE];
    }
    if (preg_match('/\b(french|francais|français)\b/u', $normal)) {
      return ['fr', TRUE];
    }
    if (preg_match('/\b(multilingual|bilingual)\b/u', $normal)) {
      return ['multi', TRUE];
    }
    $rw = (bool) preg_match('/\b(itangazo|amakuru|amashanyarazi|umushinga|abanyarwanda|inyandiko|ibura|riteganyijwe)\b/u', $normal);
    $en = (bool) preg_match('/\b(english|press release|report|plan|policy|project|publication|assessment|management|financial|annual|newsletter|issue|law|gazette|outage|electricity|interruption)\b/u', $normal);
    if ($rw && $en) {
      return ['multi', TRUE];
    }
    if ($rw) {
      return ['rw', TRUE];
    }
    if ($en) {
      return ['en', TRUE];
    }
    return ['en', FALSE];
  }

  /** Maps legacy labels/slugs into the established publication categories. */
  public function category(string $type, string $listing_url, string $title, string $legacy_category = ''): string {
    if ($type === 'press-releases') return 'press_release';
    if (isset(self::TYPES[$type]) && $type !== 'publications') {
      return self::TYPES[$type]['category'];
    }
    $normal = mb_strtolower($listing_url . ' ' . $legacy_category . ' ' . $title);
    if (preg_match('/\b(esf|esa|esia|esmps?|ehsps?|araps?|safeguard|resettlement|environmental|social impact)\b/u', $normal)) {
      return 'safeguard';
    }
    foreach (['report', 'policy', 'plan', 'form'] as $category) {
      if (preg_match('/\b' . $category . 's?\b/u', $normal)) return $category;
    }
    return 'publication';
  }

  /** Extracts a structured newsletter issue identifier when supplied. */
  public function issueNumber(string $text): string {
    $text = $this->plain(str_replace(['_', '-'], ' ', $text));
    return preg_match('/\bissue\s*(?:no\.?|number|#)?\s*([a-z0-9][a-z0-9.\/-]*)\b/iu', $text, $match)
      ? trim($match[1], './-')
      : '';
  }

  /** Identifies historical outage notices without creating outage entities. */
  public function historicalOutage(string $text): bool {
    return (bool) preg_match(
      '/\b(outage|power interruption|electricity interruption|planned maintenance)|ibura\s+ry[’\x{2019}\x{0027}]?amashanyarazi/iu',
      $this->plain($text),
    );
  }

  /** Extracts a complete original date without using the import time. */
  private function originalDate(\DOMXPath $xpath, string $text, ?string $fallback): ?string {
    foreach (["//meta[@property='article:published_time']/@content", "//meta[@name='date']/@content", '//time/@datetime'] as $query) {
      foreach ($xpath->query($query) ?: [] as $node) {
        if ($date = $this->dateFromText($node->nodeValue)) return $date;
      }
    }
    return $this->dateFromText($text) ?: $fallback;
  }

  public function dateFromText(string $text): ?string {
    $patterns = [
      ['/\b(20\d{2}|19\d{2})[-\/.](\d{1,2})[-\/.](\d{1,2})\b/', 'ymd'],
      ['/\b(\d{1,2})[-\/.](\d{1,2})[-\/.](20\d{2}|19\d{2})\b/', 'dmy'],
      ['/\b(\d{1,2})(?:st|nd|rd|th)?\s+(January|February|March|April|May|June|July|August|September|October|November|December)\s*,?\s*(20\d{2}|19\d{2})\b/iu', 'word'],
      ['/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?\s*,?\s*(20\d{2}|19\d{2})\b/iu', 'month'],
    ];
    foreach ($patterns as [$pattern, $order]) {
      if (!preg_match($pattern, html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $match)) continue;
      if ($order === 'ymd') $raw = $match[1] . '-' . $match[2] . '-' . $match[3];
      elseif ($order === 'dmy') $raw = $match[3] . '-' . $match[2] . '-' . $match[1];
      elseif ($order === 'word') $raw = $match[1] . ' ' . $match[2] . ' ' . $match[3];
      else $raw = $match[2] . ' ' . $match[1] . ' ' . $match[3];
      try {
        return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->format('Y-m-d');
      }
      catch (\Throwable) {}
    }
    return NULL;
  }

  /** Creates or updates a duplicate-safe reg_publication record. */
  private function upsert(array $record, bool $dry_run, bool $update_existing, array &$report): string {
    $existing = $this->findExisting($record);
    if ($dry_run) return $existing ? 'existing' : 'would_create';
    if ($existing && $this->value($existing, 'field_reg_source_hash') === $record['source_hash']) return 'existing';
    if ($existing && !$update_existing) return 'existing';

    $media = $record['document_url'] !== '' ? $this->importDocument($record, $report) : NULL;
    $node = $existing ?: Node::create([
      'type' => 'reg_publication',
      'title' => $record['title'],
      'langcode' => $record['language'] === 'rw' ? 'rw' : 'en',
      'uid' => 1,
      'created' => $record['date'] ? strtotime($record['date'] . ' UTC') : $this->time->getCurrentTime(),
      'status' => $record['issues'] === [] ? NodeInterface::PUBLISHED : NodeInterface::NOT_PUBLISHED,
    ]);
    $node->setTitle($record['title']);
    $this->setIfField($node, 'field_reg_entity', 'reg');
    $this->setIfField($node, 'field_reg_document_language', $record['language']);
    $this->setIfField($node, 'field_reg_publication_category', $record['category']);
    $this->setIfField($node, 'field_reg_publication_type', ['target_id' => $this->term($record['publication_type'])]);
    $this->setIfField($node, 'field_reg_publication_date', $record['date'] ? $record['date'] . 'T12:00:00' : NULL);
    $this->setIfField($node, 'field_reg_issue_number', $record['issue_number']);
    $this->setIfField($node, 'field_reg_archived_outage', $record['archived_outage'] ? 1 : 0);
    if ($media) $this->setIfField($node, 'field_reg_publication_document', ['target_id' => $media->id()]);
    $this->setIfField($node, 'field_reg_external_url', ['uri' => $record['source_url']]);
    $this->setIfField($node, 'field_reg_source_id', $record['source_id']);
    $this->setIfField($node, 'field_reg_source_hash', $record['source_hash']);
    if (!$existing || $node->get('field_reg_imported_date')->isEmpty()) {
      $this->setIfField($node, 'field_reg_imported_date', gmdate('Y-m-d', $this->time->getCurrentTime()));
    }
    $this->setIfField($node, 'field_reg_migration_status', $existing ? 'updated' : 'imported');
    $this->setIfField($node, 'field_reg_migration_review', $record['issues'][0] ?? 'verified');
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', $record['issues'] === [] ? 'published' : 'draft');
    }
    $node->save();
    return $existing ? 'updated' : 'created';
  }

  /** Finds matches by source URL, legacy ID, then title/date/language. */
  private function findExisting(array $record): ?NodeInterface {
    $storage = $this->entityTypeManager->getStorage('node');
    foreach ([['field_reg_external_url.uri', $record['source_url']], ['field_reg_source_id', $record['source_id']]] as [$field, $value]) {
      if ($value === '') continue;
      $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_publication')
        ->condition($field, $value)->range(0, 1)->execute();
      if ($ids && ($node = $storage->load(reset($ids))) instanceof NodeInterface) return $node;
    }
    if (!$record['date']) return NULL;
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_publication')
      ->condition('langcode', $record['language'] === 'rw' ? 'rw' : 'en')
      ->condition('title', $record['title'])
      ->condition('field_reg_publication_date', $record['date'] . 'T00:00:00', '>=')
      ->condition('field_reg_publication_date', $record['date'] . 'T23:59:59', '<=')
      ->range(0, 1)->execute();
    $node = $ids ? $storage->load(reset($ids)) : NULL;
    return $node instanceof NodeInterface ? $node : NULL;
  }

  /** Downloads a source document into File and Document Media entities. */
  private function importDocument(array $record, array &$report): ?MediaInterface {
    $contents = $this->fetchBinary($record['document_url'], $report);
    if ($contents === NULL) {
      $report['documents']['failed']++;
      return NULL;
    }
    $extension = strtolower(pathinfo((string) parse_url($record['document_url'], PHP_URL_PATH), PATHINFO_EXTENSION));
    if (!in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx'], TRUE)) {
      $report['documents']['failed']++;
      return NULL;
    }
    $hash = hash('sha256', $contents);
    $directory = 'public://legacy-reg/documents';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $directory . '/' . $hash . '.' . $extension;
    $file_storage = $this->entityTypeManager->getStorage('file');
    $ids = $file_storage->getQuery()->accessCheck(FALSE)->condition('uri', $uri)->range(0, 1)->execute();
    $file = $ids ? $file_storage->load(reset($ids)) : NULL;
    if ($file) {
      $report['documents']['reused']++;
    }
    else {
      $file = $this->fileRepository->writeData($contents, $uri, FileExists::Replace);
      $file->setPermanent();
      $file->save();
      $report['documents']['imported']++;
    }
    $media_storage = $this->entityTypeManager->getStorage('media');
    $media_ids = $media_storage->getQuery()->accessCheck(FALSE)->condition('bundle', 'document')
      ->condition('field_media_document.target_id', $file->id())->range(0, 1)->execute();
    $media = $media_ids ? $media_storage->load(reset($media_ids)) : NULL;
    if (!$media instanceof MediaInterface) {
      $media = $media_storage->create([
        'bundle' => 'document',
        'name' => Unicode::truncate($record['title'], 250, TRUE, TRUE),
        'uid' => 1,
        'status' => 1,
        'field_media_document' => ['target_id' => $file->id(), 'description' => $record['title']],
        'field_reg_document_date' => $record['date'],
        'field_reg_document_type' => 'other',
      ]);
      $media->save();
    }
    return $media;
  }

  private function term(string $name): int {
    $key = mb_strtolower($name);
    if (isset($this->terms[$key])) return $this->terms[$key];
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('vid', 'reg_publication_type')
      ->condition('name', $name)->range(0, 1)->execute();
    if ($ids) return $this->terms[$key] = (int) reset($ids);
    $term = Term::create(['vid' => 'reg_publication_type', 'name' => $name]);
    $term->save();
    return $this->terms[$key] = (int) $term->id();
  }

  private function fetch(string $url, string $type, array &$report): ?string {
    $url = $this->canonical($url, TRUE);
    if (array_key_exists($url, $this->responses)) return $this->responses[$url];
    if (!$this->allowed($url, $type)) return $this->responses[$url] = NULL;
    try {
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => ['max' => 4, 'strict' => TRUE],
        'connect_timeout' => 10,
        'timeout' => 35,
        'headers' => ['User-Agent' => 'REG Drupal controlled document migration/1.0'],
      ]);
      return $this->responses[$url] = (string) $response->getBody();
    }
    catch (\Throwable $exception) {
      $report['source_failures'][] = ['url' => $url, 'reason' => $this->safeError($exception->getMessage())];
      return $this->responses[$url] = NULL;
    }
  }

  private function fetchBinary(string $url, array &$report): ?string {
    if (!$this->allowed($url, 'publications', TRUE)) return NULL;
    try {
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => ['max' => 4, 'strict' => TRUE],
        'connect_timeout' => 10,
        'timeout' => 60,
        'headers' => ['User-Agent' => 'REG Drupal controlled document migration/1.0'],
      ]);
      $contents = (string) $response->getBody();
      return $contents !== '' && strlen($contents) <= 50 * 1024 * 1024 ? $contents : NULL;
    }
    catch (\Throwable $exception) {
      $report['documents']['failures'][] = ['url' => $url, 'reason' => $this->safeError($exception->getMessage())];
      return NULL;
    }
  }

  public function allowed(string $url, string $type, bool $asset = FALSE): bool {
    if (!isset(self::TYPES[$type])) return FALSE;
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $path = (string) parse_url($url, PHP_URL_PATH);
    if (!in_array($host, ['www.reg.rw', 'reg.rw'], TRUE)) return FALSE;
    if ($asset) return str_starts_with($path, '/fileadmin/') && $this->isDocumentPath($path);
    if ($this->isIndexPath($path, $type)) return TRUE;
    $detail_prefix = self::TYPES[$type]['detail_prefix'] ?? '';
    return $detail_prefix !== '' && str_starts_with($path, $detail_prefix);
  }

  private function resolve(string $href, string $type, bool $asset_only = FALSE): ?string {
    $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($href === '' || preg_match('@^(#|javascript:|mailto:)@i', $href)) return NULL;
    if (str_starts_with($href, '//')) $href = 'https:' . $href;
    elseif (!preg_match('@^https?://@i', $href)) $href = self::BASE . '/' . ltrim($href, '/');
    $asset = $this->isDocumentPath((string) parse_url($href, PHP_URL_PATH));
    if ($asset_only && !$asset) return NULL;
    return $this->allowed($href, $type, $asset) ? $this->canonical($href, !$asset) : NULL;
  }

  private function isIndexPath(string $path, string $type): bool {
    return str_starts_with($path, rtrim(self::TYPES[$type]['listing'], '/'));
  }

  private function isDocumentPath(string $path): bool {
    return (bool) preg_match('/\.(pdf|docx?|xlsx?)$/i', $path);
  }

  private function isDiscoveryLink(string $url, string $type): bool {
    $path = (string) parse_url($url, PHP_URL_PATH);
    if ($type === 'publications' && str_contains($path, '/category/')) return TRUE;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    return isset($query['tx_news_pi1']);
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

  private function discoveryKey(string $url): string {
    return $this->canonical($url, TRUE);
  }

  private function dom(string $html): array {
    $dom = new \DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(TRUE);
    $dom->loadHTML('<?xml encoding=utf-8 ?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return [$dom, new \DOMXPath($dom)];
  }

  private function listingDate(\DOMNode $anchor): ?string {
    $container = $anchor->parentNode;
    for ($i = 0; $i < 5 && $container; $i++, $container = $container->parentNode) {
      if ($date = $this->dateFromText($this->text($container))) return $date;
    }
    return NULL;
  }

  private function text(?\DOMNode $node): string {
    return $node ? trim(preg_replace('/\s+/u', ' ', html_entity_decode($node->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'))) : '';
  }

  private function plain(string $value): string {
    return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
  }

  private function setIfField(NodeInterface $node, string $field, mixed $value): void {
    if ($node->hasField($field) && $value !== NULL && $value !== '') $node->set($field, $value);
  }

  private function value(NodeInterface $node, string $field): string {
    if (!$node->hasField($field) || $node->get($field)->isEmpty()) return '';
    $values = $node->get($field)->first()?->getValue() ?? [];
    return trim((string) ($values['value'] ?? $values['uri'] ?? $values['target_id'] ?? ''));
  }

  private function safeError(string $message): string {
    return Unicode::truncate(preg_replace('/[\r\n]+/', ' ', $message), 300, TRUE, TRUE);
  }

  private function emptyReport(string $type, bool $dry_run): array {
    return [
      'type' => $type, 'dry_run' => $dry_run,
      'discovered' => 0, 'would_create' => 0, 'created' => 0,
      'existing' => 0, 'updated' => 0, 'needs_review' => 0, 'failed' => 0,
      'source_pages_scanned' => 0,
      'quality' => ['needs_date' => 0, 'needs_language' => 0, 'needs_file' => 0],
      'documents' => ['imported' => 0, 'reused' => 0, 'failed' => 0, 'failures' => []],
      'sample' => [], 'source_failures' => [], 'failures' => [],
    ];
  }

}
