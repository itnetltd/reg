<?php

namespace Drupal\reg_core\Content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileExists;
use Drupal\file\FileRepositoryInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use GuzzleHttp\ClientInterface;

/**
 * Imports source-traceable institutional content from the official REG site.
 */
final class WhatWeDoImporter {

  private const BASE_URL = 'https://www.reg.rw';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ClientInterface $httpClient,
    private readonly TimeInterface $time,
    private readonly FileRepositoryInterface $fileRepository,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * Imports all official pages and structured source records.
   */
  public function import(): array {
    $counts = [
      'pages_created' => 0,
      'pages_updated' => 0,
      'projects_created' => 0,
      'projects_updated' => 0,
      'plants_created' => 0,
      'plants_updated' => 0,
      'facts_created' => 0,
      'facts_updated' => 0,
      'access_created' => 0,
      'access_updated' => 0,
      'media_created' => 0,
      'errors' => [],
    ];

    $summaries = [];
    foreach (self::pageManifest() as $key => $definition) {
      try {
        $document = $this->fetchDocument($definition['source']);
        $first_media = NULL;
        $body = $this->extractMainContent($document, $definition['source'], $counts, $first_media);
        $summary = $this->extractSummary($body, $definition['title']);
        if ($body === '' || $summary === '') {
          throw new \RuntimeException('No meaningful official main content was extracted.');
        }
        $summaries[$key] = $summary;
        $created = $this->upsertNode('reg_what_we_do_page', 'reg-wwd:' . $key, [
          'title' => $definition['title'],
          'status' => 1,
          'body' => ['value' => $body, 'summary' => $summary, 'format' => 'full_html'],
          'field_reg_wwd_key' => $key,
          'field_reg_summary' => $summary,
          'field_reg_wwd_group' => $definition['group'],
          'field_reg_source_url' => ['uri' => $definition['source']],
          'field_reg_source_label' => 'Official REG website',
          'field_reg_source_hash' => hash('sha256', $body),
          'field_reg_imported_date' => gmdate('Y-m-d', $this->time->getRequestTime()),
          'field_reg_content_review' => $definition['review'],
          'field_reg_featured_image' => $first_media ? ['target_id' => $first_media] : NULL,
        ], $definition['alias']);
        $counts[$created ? 'pages_created' : 'pages_updated']++;
      }
      catch (\Throwable $exception) {
        $fallback = $this->fallbackPageBody($key);
        if ($fallback !== '') {
          $summary = $this->extractSummary($fallback, $definition['title']);
          $created = $this->upsertNode('reg_what_we_do_page', 'reg-wwd:' . $key, [
            'title' => $definition['title'], 'status' => 1,
            'body' => ['value' => $fallback, 'summary' => $summary, 'format' => 'full_html'],
            'field_reg_wwd_key' => $key, 'field_reg_summary' => $summary,
            'field_reg_wwd_group' => $definition['group'], 'field_reg_source_url' => ['uri' => $definition['source']],
            'field_reg_source_label' => 'Official REG website', 'field_reg_source_hash' => hash('sha256', $fallback),
            'field_reg_imported_date' => gmdate('Y-m-d', $this->time->getRequestTime()),
            'field_reg_content_review' => $definition['review'],
          ], $definition['alias']);
          $counts[$created ? 'pages_created' : 'pages_updated']++;
        }
        else {
          $counts['errors'][$key] = $exception->getMessage();
        }
      }
    }

    $this->importLandingPages($summaries, $counts);
    $this->importFacts($counts);
    $this->importAccessStatistics($counts);
    $this->importPowerPlants($counts);
    $this->importProjects($counts);
    $this->refreshProjectsLanding($counts);
    $this->activateNavigation();
    return $counts;
  }

  /**
   * Hydrates structured project fields from already-imported official bodies.
   */
  public function hydrateProjectMetadataFromImportedBodies(): int {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_project')->execute();
    $updated = 0;
    foreach ($storage->loadMultiple($ids) as $node) {
      $document = new \DOMDocument('1.0', 'UTF-8');
      @$document->loadHTML('<?xml encoding="UTF-8">' . (string) $node->get('body')->value, LIBXML_NOERROR | LIBXML_NOWARNING);
      $metadata = $this->extractProjectMetadata($document);
      if (!$metadata) {
        continue;
      }
      foreach ($metadata as $field => $value) {
        if ($node->hasField($field) && $value !== '') {
          $node->set($field, $value);
        }
      }
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Mapped official project-detail labels into structured Drupal fields.');
      $node->save();
      $updated++;
    }
    return $updated;
  }

  /**
   * Returns the official page migration manifest.
   */
  public static function pageManifest(): array {
    $current = 'needs_current_data';
    $review = 'imported_needs_review';
    return [
      'generation' => ['title' => 'Generation', 'source' => self::BASE_URL . '/what-we-do/generation/', 'alias' => '/what-we-do/generation', 'group' => 'generation', 'review' => $current],
      'generation-hydropower' => ['title' => 'Hydropower', 'source' => self::BASE_URL . '/what-we-do/generation/hydro-power/', 'alias' => '/what-we-do/generation/hydropower', 'group' => 'generation', 'review' => $current],
      'generation-solar' => ['title' => 'Solar', 'source' => self::BASE_URL . '/what-we-do/generation/solar/', 'alias' => '/what-we-do/generation/solar', 'group' => 'generation', 'review' => $current],
      'generation-methane-gas' => ['title' => 'Methane Gas', 'source' => self::BASE_URL . '/what-we-do/generation/methane-gas/', 'alias' => '/what-we-do/generation/methane-gas', 'group' => 'generation', 'review' => $review],
      'generation-peat' => ['title' => 'Peat', 'source' => self::BASE_URL . '/what-we-do/generation/peat/', 'alias' => '/what-we-do/generation/peat', 'group' => 'generation', 'review' => $current],
      'generation-thermal' => ['title' => 'Thermal', 'source' => self::BASE_URL . '/what-we-do/generation/thermal/', 'alias' => '/what-we-do/generation/thermal', 'group' => 'generation', 'review' => $current],
      'generation-geothermal' => ['title' => 'Geothermal', 'source' => self::BASE_URL . '/what-we-do/generation/geothermal/', 'alias' => '/what-we-do/generation/geothermal', 'group' => 'generation', 'review' => $review],
      'transmission' => ['title' => 'Transmission', 'source' => self::BASE_URL . '/what-we-do/transmission/', 'alias' => '/what-we-do/transmission', 'group' => 'transmission', 'review' => $review],
      'distribution' => ['title' => 'Distribution', 'source' => self::BASE_URL . '/what-we-do/distribution/', 'alias' => '/what-we-do/distribution', 'group' => 'distribution', 'review' => $current],
      'distribution-medium-voltage' => ['title' => 'Medium Voltage', 'source' => self::BASE_URL . '/what-we-do/distribution/medium-voltage/', 'alias' => '/what-we-do/distribution/medium-voltage', 'group' => 'distribution', 'review' => $current],
      'distribution-low-voltage' => ['title' => 'Low Voltage', 'source' => self::BASE_URL . '/what-we-do/distribution/low-voltage/', 'alias' => '/what-we-do/distribution/low-voltage', 'group' => 'distribution', 'review' => $current],
      'access' => ['title' => 'Electricity Access', 'source' => self::BASE_URL . '/what-we-do/access/', 'alias' => '/what-we-do/access', 'group' => 'access', 'review' => 'approved'],
      'access-on-grid' => ['title' => 'On-grid Access', 'source' => self::BASE_URL . '/what-we-do/access/ongrid/', 'alias' => '/what-we-do/access/on-grid', 'group' => 'access', 'review' => 'approved'],
      'access-off-grid' => ['title' => 'Off-grid Access', 'source' => self::BASE_URL . '/what-we-do/access/offgrid/', 'alias' => '/what-we-do/access/off-grid', 'group' => 'access', 'review' => 'approved'],
      'access-national-electrification-plan' => ['title' => 'National Electrification Plan', 'source' => self::BASE_URL . '/what-we-do/access/national-electrification-plan/', 'alias' => '/what-we-do/access/national-electrification-plan', 'group' => 'access', 'review' => $review],
      'biomass-clean-cooking' => ['title' => 'Biomass & Clean Cooking', 'source' => self::BASE_URL . '/what-we-do/biomass/', 'alias' => '/what-we-do/biomass-clean-cooking', 'group' => 'energy_solutions', 'review' => $current],
      'petroleum' => ['title' => 'Petroleum', 'source' => self::BASE_URL . '/what-we-do/petroleum/', 'alias' => '/what-we-do/petroleum', 'group' => 'energy_solutions', 'review' => $current],
      'off-grid-mini-grids' => ['title' => 'Mini-grids', 'source' => self::BASE_URL . '/what-we-do/offgrid-solutions/mini-grids/', 'alias' => '/what-we-do/off-grid/mini-grids', 'group' => 'energy_solutions', 'review' => $current],
      'off-grid-solar-home-systems' => ['title' => 'Solar Home Systems', 'source' => self::BASE_URL . '/what-we-do/offgrid-solutions/solar-home-systems/', 'alias' => '/what-we-do/off-grid/solar-home-systems', 'group' => 'energy_solutions', 'review' => 'needs_contact_verification'],
      'program-rbf-window-5' => ['title' => 'RBF Window 5', 'source' => self::BASE_URL . '/what-we-do/rbf-programs/rbf-window-5/', 'alias' => '/what-we-do/programs/rbf-window-5', 'group' => 'programs', 'review' => $review],
      'program-clean-cooking' => ['title' => 'Clean Cooking RBF', 'source' => self::BASE_URL . '/what-we-do/rbf-programs/rbf-clean-cooking/', 'alias' => '/what-we-do/programs/clean-cooking', 'group' => 'programs', 'review' => $current],
      'program-productive-use-energy' => ['title' => 'Productive Use of Energy', 'source' => self::BASE_URL . '/what-we-do/rbf-programs/productive-use-of-energy-technologies/', 'alias' => '/what-we-do/programs/productive-use-of-energy', 'group' => 'programs', 'review' => 'needs_contact_verification'],
      'investment-opportunities' => ['title' => 'Investment Opportunities', 'source' => self::BASE_URL . '/what-we-do/investments/opportunities/', 'alias' => '/what-we-do/investment/opportunities', 'group' => 'investment', 'review' => $current],
      'investment-incentives' => ['title' => 'Investment Incentives', 'source' => self::BASE_URL . '/what-we-do/investments/incentives/', 'alias' => '/what-we-do/investment/incentives', 'group' => 'investment', 'review' => 'needs_contact_verification'],
      'investment-ipp' => ['title' => 'Independent Power Producers', 'source' => self::BASE_URL . '/what-we-do/investments/ipps/process-to-become-an-ipp/', 'alias' => '/what-we-do/investment/ipp', 'group' => 'investment', 'review' => 'needs_content_enhancement'],
      'projects' => ['title' => 'Projects', 'source' => self::BASE_URL . '/what-we-do/projects/', 'alias' => '/projects', 'group' => 'projects', 'review' => $review],
    ];
  }

  /**
   * Fetches an official REG page into a DOM document.
   */
  private function fetchDocument(string $url): \DOMDocument {
    $response = $this->httpClient->request('GET', $url, [
      'timeout' => 45,
      'connect_timeout' => 15,
      'headers' => ['User-Agent' => 'REG Drupal official-content migration/1.0'],
    ]);
    if ($response->getStatusCode() !== 200) {
      throw new \RuntimeException('Official source returned HTTP ' . $response->getStatusCode());
    }
    $document = new \DOMDocument('1.0', 'UTF-8');
    @$document->loadHTML('<?xml encoding="UTF-8">' . (string) $response->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
    return $document;
  }

  /**
   * Extracts and sanitizes the TYPO3 main-content column only.
   */
  private function extractMainContent(\DOMDocument $source, string $source_url, array &$counts, ?int &$first_media): string {
    $xpath = new \DOMXPath($source);
    $content = $xpath->query("//section[contains(concat(' ', normalize-space(@class), ' '), ' our-infoMain ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' col-md-9 ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' about_content ')]")->item(0)
      ?: $xpath->query("//section[contains(concat(' ', normalize-space(@class), ' '), ' our-infoMain ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' col-md-9 ')]")->item(0);
    if (!$content) {
      throw new \RuntimeException('Official main-content container was not found.');
    }

    $fragment = new \DOMDocument('1.0', 'UTF-8');
    @$fragment->loadHTML('<?xml encoding="UTF-8"><div id="reg-import-root">' . $source->saveHTML($content) . '</div>', LIBXML_NOERROR | LIBXML_NOWARNING);
    $fragment_xpath = new \DOMXPath($fragment);
    $root = $fragment_xpath->query('//*[@id="reg-import-root"]')->item(0);
    foreach ($fragment_xpath->query('//script|//style|//iframe|//form|//*[contains(concat(" ", normalize-space(@class), " "), " social_share ")]|//*[contains(concat(" ", normalize-space(@class), " "), " tx-projectmanagement ")]') as $remove) {
      $remove->parentNode?->removeChild($remove);
    }

    $images_seen = 0;
    foreach ($fragment_xpath->query('//img[@src]') as $image) {
      if ($images_seen >= 3) {
        $image->parentNode?->removeChild($image);
        continue;
      }
      $remote = $this->absoluteUrl($image->getAttribute('src'), $source_url);
      try {
        [$media_id, $public_url, $created] = $this->importImage($remote, $image->getAttribute('alt'), $image->getAttribute('title'));
        $image->setAttribute('src', $public_url);
        $image->setAttribute('loading', 'lazy');
        $image->setAttribute('alt', trim($image->getAttribute('alt')) ?: 'Official REG energy infrastructure');
        $first_media ??= $media_id;
        $counts['media_created'] += $created ? 1 : 0;
        $images_seen++;
      }
      catch (\Throwable) {
        $image->parentNode?->removeChild($image);
      }
    }

    foreach ($fragment_xpath->query('//a[@href]') as $link) {
      $link->setAttribute('href', $this->rewriteOfficialLink($this->absoluteUrl($link->getAttribute('href'), $source_url)));
      if (str_starts_with($link->getAttribute('href'), 'http')) {
        $link->setAttribute('rel', 'noopener');
      }
    }
    foreach ($fragment_xpath->query('//*') as $element) {
      if (!$element instanceof \DOMElement) {
        continue;
      }
      foreach (iterator_to_array($element->attributes) as $attribute) {
        if ($element === $root && $attribute->name === 'id') {
          continue;
        }
        if (!in_array($attribute->name, ['href', 'src', 'alt', 'title', 'loading', 'rel', 'colspan', 'rowspan'], TRUE)) {
          $element->removeAttribute($attribute->name);
        }
      }
    }

    $html = '';
    foreach ($root?->childNodes ?? [] as $child) {
      $html .= $fragment->saveHTML($child);
    }
    $html = preg_replace('/<!--.*?-->/s', '', $html) ?? $html;
    $html = preg_replace('/<p>\s*(?:&nbsp;|\x{00A0})*\s*<\/p>/u', '', $html) ?? $html;
    return trim($html);
  }

  /**
   * Extracts a substantive summary from sanitized official content.
   */
  private function extractSummary(string $html, string $title): string {
    $text_document = new \DOMDocument();
    @$text_document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
    foreach ((new \DOMXPath($text_document))->query('//p') as $paragraph) {
      $text = trim(preg_replace('/\s+/u', ' ', $paragraph->textContent) ?? '');
      if (mb_strlen($text) >= 80 && !str_contains(mb_strtolower($text), 'read more')) {
        return mb_strlen($text) > 360 ? rtrim(mb_substr($text, 0, 357)) . '…' : $text;
      }
    }
    return $title . ' information published by Rwanda Energy Group.';
  }

  /**
   * Creates the landing pages from real imported child summaries.
   */
  private function importLandingPages(array $summaries, array &$counts): void {
    $landings = [
      'overview' => [
        'title' => 'What We Do', 'alias' => '/what-we-do', 'group' => 'overview',
        'source' => self::BASE_URL . '/what-we-do/',
        'children' => ['generation', 'transmission', 'distribution', 'access', 'biomass-clean-cooking', 'petroleum', 'projects'],
      ],
      'off-grid' => [
        'title' => 'Off-grid Solutions', 'alias' => '/what-we-do/off-grid', 'group' => 'energy_solutions',
        'source' => self::BASE_URL . '/what-we-do/offgrid-solutions/mini-grids/',
        'children' => ['off-grid-mini-grids', 'off-grid-solar-home-systems'],
      ],
    ];
    $manifest = self::pageManifest();
    foreach ($landings as $key => $landing) {
      $sections = [];
      foreach ($landing['children'] as $child_key) {
        if (empty($summaries[$child_key]) || empty($manifest[$child_key])) {
          continue;
        }
        $child = $manifest[$child_key];
        $sections[] = '<section><h2>' . htmlspecialchars($child['title'], ENT_QUOTES | ENT_HTML5) . '</h2><p>' . htmlspecialchars($summaries[$child_key], ENT_QUOTES | ENT_HTML5) . '</p><p><a href="' . $child['alias'] . '">Explore ' . htmlspecialchars($child['title'], ENT_QUOTES | ENT_HTML5) . ' →</a></p></section>';
      }
      $body = implode('', $sections);
      $summary = $key === 'overview'
        ? 'REG develops and operates Rwanda’s electricity system, expands access, advances energy solutions, delivers projects and programs, and supports responsible investment.'
        : 'REG’s off-grid solutions extend electricity access through mini-grids and quality-assured solar home systems.';
      $created = $this->upsertNode('reg_what_we_do_page', 'reg-wwd:' . $key, [
        'title' => $landing['title'], 'status' => 1,
        'body' => ['value' => $body, 'summary' => $summary, 'format' => 'full_html'],
        'field_reg_wwd_key' => $key, 'field_reg_summary' => $summary,
        'field_reg_wwd_group' => $landing['group'],
        'field_reg_source_url' => ['uri' => $landing['source']],
        'field_reg_source_label' => 'Official REG website',
        'field_reg_source_hash' => hash('sha256', $body),
        'field_reg_imported_date' => gmdate('Y-m-d', $this->time->getRequestTime()),
        'field_reg_content_review' => 'imported_needs_review',
      ], $landing['alias']);
      $counts[$created ? 'pages_created' : 'pages_updated']++;
    }
  }

  /**
   * Imports dated facts without reconciling conflicting source periods.
   */
  private function importFacts(array &$counts): void {
    $facts = [
      ['generation-capacity-fy-2023-24', 'generation', 'Installed generation capacity', 406.402, 'MW', 'FY 2023/2024', self::BASE_URL . '/what-we-do/generation/', 'needs_current_data'],
      ['generation-capacity-undated-332-6', 'generation', 'Installed generation capacity (legacy page value)', 332.6, 'MW', 'Reporting period not stated', self::BASE_URL . '/what-we-do/generation/', 'needs_current_data'],
      ['solar-resource', 'generation-solar', 'Average solar resource', 4.5, 'kWh/m²/day', 'Reporting period not stated', self::BASE_URL . '/what-we-do/generation/solar/', 'needs_current_data'],
      ['transmission-network-june-2023', 'transmission', 'Transmission network length', 1158, 'km', 'June 2023', self::BASE_URL . '/what-we-do/transmission/', 'approved'],
      ['access-total-july-2025', 'access', 'Total household electricity access', 84.6, '%', 'July 2025', self::BASE_URL . '/what-we-do/access/', 'approved'],
      ['access-on-grid-july-2025', 'access', 'Households with on-grid access', 59.6, '%', 'July 2025', self::BASE_URL . '/what-we-do/access/', 'approved'],
      ['access-off-grid-july-2025', 'access', 'Households with off-grid access', 25.0, '%', 'July 2025', self::BASE_URL . '/what-we-do/access/', 'approved'],
    ];
    foreach ($facts as [$id, $page, $metric, $value, $unit, $period, $source, $review]) {
      $created = $this->upsertNode('reg_fact', 'reg-fact:' . $id, [
        'title' => $metric . ' — ' . $period, 'status' => 1,
        'field_reg_fact_key' => $id, 'field_reg_wwd_key' => $page,
        'field_reg_metric' => $metric, 'field_reg_fact_value' => $value,
        'field_reg_fact_unit' => $unit, 'field_reg_reporting_period' => $period,
        'field_reg_source_url' => ['uri' => $source], 'field_reg_source_label' => 'Official REG website',
        'field_reg_source_hash' => hash('sha256', implode('|', [$metric, $value, $unit, $period, $source])),
        'field_reg_imported_date' => gmdate('Y-m-d', $this->time->getRequestTime()),
        'field_reg_content_review' => $review,
      ]);
      $counts[$created ? 'facts_created' : 'facts_updated']++;
    }
  }

  /**
   * Imports district access tables from total, on-grid and off-grid pages.
   */
  private function importAccessStatistics(array &$counts): void {
    $sources = [
      'total' => self::BASE_URL . '/what-we-do/access/',
      'on_grid' => self::BASE_URL . '/what-we-do/access/ongrid/',
      'off_grid' => self::BASE_URL . '/what-we-do/access/offgrid/',
    ];
    foreach ($sources as $type => $url) {
      try {
        $document = $this->fetchDocument($url);
        foreach ($this->extractDistrictRates($document) as $district => $rate) {
          if (mb_strtoupper($district) === 'NATIONAL') {
            continue;
          }
          $source_id = 'reg-access:' . $type . ':' . strtolower(str_replace(' ', '-', $district));
          $created = $this->upsertNode('reg_access_statistic', $source_id, [
            'title' => $district . ' — ' . str_replace('_', ' ', $type) . ' access', 'status' => 1,
            'field_reg_district_name' => $district, 'field_reg_access_type' => $type,
            'field_reg_access_rate' => $rate, 'field_reg_reporting_period' => 'July 2025',
            'field_reg_source_url' => ['uri' => $url], 'field_reg_source_label' => 'Official REG website',
            'field_reg_source_hash' => hash('sha256', "$district|$type|$rate|July 2025"),
            'field_reg_imported_date' => gmdate('Y-m-d', $this->time->getRequestTime()),
            'field_reg_content_review' => 'approved',
          ]);
          $counts[$created ? 'access_created' : 'access_updated']++;
        }
      }
      catch (\Throwable $exception) {
        $counts['errors']['access-' . $type] = $exception->getMessage();
      }
    }
  }

  /**
   * Extracts district percentages from official HTML tables.
   */
  private function extractDistrictRates(\DOMDocument $document): array {
    $rates = [];
    $xpath = new \DOMXPath($document);
    foreach ($xpath->query('//table//tr') as $row) {
      $cells = [];
      foreach ($xpath->query('./th|./td', $row) as $cell) {
        $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? '');
      }
      if (count($cells) < 2 || !preg_match('/([0-9]+(?:\.[0-9]+)?)\s*%/', $cells[1], $match)) {
        continue;
      }
      $district = trim($cells[0]);
      if ($district !== '' && !is_numeric($district)) {
        $rates[$district] = (float) $match[1];
      }
    }
    return $rates;
  }

  /**
   * Imports official power-plant rows without silently correcting values.
   */
  private function importPowerPlants(array &$counts): void {
    $url = self::BASE_URL . '/what-we-do/generation/power-plant/';
    try {
      $document = $this->fetchDocument($url);
      $xpath = new \DOMXPath($document);
      $technology = 'Unspecified';
      foreach ($xpath->query('//table//tr') as $row) {
        $cells = [];
        foreach ($xpath->query('./th|./td', $row) as $cell) {
          $cells[] = trim(preg_replace('/\s+/u', ' ', $cell->textContent) ?? '');
        }
        $joined = mb_strtoupper(implode(' ', $cells));
        foreach (['HYDROPOWER' => 'Hydropower', 'SOLAR POWER' => 'Solar', 'THERMAL POWER' => 'Thermal'] as $needle => $label) {
          if (str_contains($joined, $needle)) {
            $technology = $label;
          }
        }
        if (count($cells) < 5 || !is_numeric(trim($cells[0])) || trim($cells[1]) === '' || !is_numeric(str_replace(',', '', trim($cells[3])))) {
          continue;
        }
        $name = trim($cells[1]);
        $grid_text = mb_strtolower(trim($cells[2]));
        $grid = str_contains($grid_text, 'off') ? 'off_grid' : (str_contains($grid_text, 'on') ? 'on_grid' : 'unspecified');
        $year = isset($cells[5]) && preg_match('/\b(19|20)\d{2}\b/', $cells[5], $year_match) ? (int) $year_match[0] : NULL;
        $id = 'reg-plant:' . strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $created = $this->upsertNode('reg_power_plant', $id, [
          'title' => $name, 'status' => 1,
          'field_reg_plant_technology' => $technology, 'field_reg_grid_status' => $grid,
          'field_reg_capacity_mw' => (float) str_replace(',', '', trim($cells[3])),
          'field_reg_ownership' => trim($cells[4]), 'field_reg_commissioning_year' => $year,
          'field_reg_source_url' => ['uri' => $url], 'field_reg_source_label' => 'Official REG power-plant table',
          'field_reg_source_hash' => hash('sha256', implode('|', $cells)),
          'field_reg_imported_date' => gmdate('Y-m-d', $this->time->getRequestTime()),
          'field_reg_content_review' => 'needs_technical_review',
        ]);
        $counts[$created ? 'plants_created' : 'plants_updated']++;
      }
    }
    catch (\Throwable $exception) {
      $counts['errors']['power-plants'] = $exception->getMessage();
    }
  }

  /**
   * Imports every unique project detail linked from the official directory.
   */
  private function importProjects(array &$counts): void {
    $directory_url = self::BASE_URL . '/what-we-do/projects/';
    try {
      $directory = $this->fetchDocument($directory_url);
      $xpath = new \DOMXPath($directory);
      $links = [];
      foreach ($xpath->query('//a[contains(@href, "/what-we-do/projects/project-details/view/")]') as $anchor) {
        $url = strtok($this->absoluteUrl($anchor->getAttribute('href'), $directory_url), '?');
        $title = trim(preg_replace('/\s+/u', ' ', $anchor->textContent) ?? '');
        if ($url && $title !== '' && !isset($links[$url])) {
          $links[$url] = $title;
        }
      }
      foreach ($links as $url => $fallback_title) {
        try {
          $document = $this->fetchDocument($url);
          $first_media = NULL;
          $body = $this->extractMainContent($document, $url, $counts, $first_media);
          $title = $this->documentHeading($document) ?: $fallback_title;
          $metadata = $this->extractProjectMetadata($document);
          $category = $metadata['field_reg_project_category'] ?? (preg_match('#/category/([^/]+)/?#', $url, $category_match) ? str_replace('-', ' ', $category_match[1]) : '');
          $source_id = 'reg-project:' . strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
          $created = $this->upsertNode('reg_project', $source_id, [
            'title' => $title, 'status' => 1,
            'body' => ['value' => $body, 'summary' => $this->extractSummary($body, $title), 'format' => 'full_html'],
            'field_reg_summary' => $this->extractSummary($body, $title),
            'field_reg_project_category' => ucwords($category),
            'field_reg_project_status' => $metadata['field_reg_project_status'] ?? '',
            'field_reg_project_funder' => $metadata['field_reg_project_funder'] ?? '',
            'field_reg_project_scope' => $metadata['field_reg_project_scope'] ?? NULL,
            'field_reg_effectiveness_date' => $metadata['field_reg_effectiveness_date'] ?? NULL,
            'field_reg_closure_date' => $metadata['field_reg_closure_date'] ?? NULL,
            'field_reg_geographic_coverage' => $metadata['field_reg_geographic_coverage'] ?? '',
            'field_reg_featured_image' => $first_media ? ['target_id' => $first_media] : NULL,
            'field_reg_source_url' => ['uri' => $url], 'field_reg_source_label' => 'Official REG project page',
            'field_reg_source_hash' => hash('sha256', $body),
            'field_reg_imported_date' => gmdate('Y-m-d', $this->time->getRequestTime()),
            'field_reg_content_review' => 'imported_needs_review',
          ], '/projects/' . substr($source_id, strlen('reg-project:')));
          $counts[$created ? 'projects_created' : 'projects_updated']++;
        }
        catch (\Throwable $exception) {
          $counts['errors']['project:' . $fallback_title] = $exception->getMessage();
        }
      }
    }
    catch (\Throwable $exception) {
      $counts['errors']['projects-directory'] = $exception->getMessage();
    }
  }

  /**
   * Rebuilds the Projects landing from actual imported project records.
   */
  private function refreshProjectsLanding(array &$counts): void {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_project')->condition('status', 1)->sort('title')->execute();
    $sections = [];
    foreach ($storage->loadMultiple($ids) as $project) {
      $sections[] = '<article><h2><a href="' . htmlspecialchars($project->toUrl()->toString(), ENT_QUOTES | ENT_HTML5) . '">' . htmlspecialchars($project->label(), ENT_QUOTES | ENT_HTML5) . '</a></h2><p>' . htmlspecialchars((string) $project->get('field_reg_summary')->value, ENT_QUOTES | ENT_HTML5) . '</p></article>';
    }
    if (!$sections) {
      return;
    }
    $body = '<p>Browse project records migrated from the official REG project directory.</p>' . implode('', $sections);
    $created = $this->upsertNode('reg_what_we_do_page', 'reg-wwd:projects', [
      'title' => 'Projects', 'status' => 1,
      'body' => ['value' => $body, 'summary' => 'Official Rwanda Energy Group generation, transmission, distribution, and electricity-access projects.', 'format' => 'full_html'],
      'field_reg_wwd_key' => 'projects', 'field_reg_summary' => 'Official Rwanda Energy Group generation, transmission, distribution, and electricity-access projects.',
      'field_reg_wwd_group' => 'projects', 'field_reg_source_url' => ['uri' => self::BASE_URL . '/what-we-do/projects/'],
      'field_reg_source_label' => 'Official REG project directory', 'field_reg_source_hash' => hash('sha256', $body),
      'field_reg_imported_date' => gmdate('Y-m-d', $this->time->getRequestTime()), 'field_reg_content_review' => 'imported_needs_review',
    ], '/projects');
    $counts[$created ? 'pages_created' : 'pages_updated']++;
    unset($counts['errors']['projects']);
  }

  /**
   * Returns official-document-backed content when no legacy HTML page exists.
   */
  private function fallbackPageBody(string $key): string {
    if ($key !== 'access-national-electrification-plan') {
      return '';
    }
    return '<h2>National Electrification Plan</h2><p>REG publishes the National Electrification Plan revision concept note as an official electricity-access planning document.</p><p><a href="https://www.reg.rw/fileadmin/user_upload/Concept_Note_NEP_Revision__July_2023.pdf" rel="noopener">View the National Electrification Plan revision concept note →</a></p>';
  }

  /**
   * Returns the primary official heading.
   */
  private function documentHeading(\DOMDocument $document): string {
    $xpath = new \DOMXPath($document);
    $heading = $xpath->query("//section[contains(concat(' ', normalize-space(@class), ' '), ' our-infoMain ')]//h2")->item(0);
    return $heading ? trim(preg_replace('/\s+/u', ' ', $heading->textContent) ?? '') : '';
  }

  /**
   * Extracts labeled project details into their Drupal field values.
   */
  private function extractProjectMetadata(\DOMDocument $document): array {
    $xpath = new \DOMXPath($document);
    $values = [];
    foreach ($xpath->query('//table//tr') as $row) {
      $cells = $xpath->query('./th|./td', $row);
      if ($cells->length < 2) {
        continue;
      }
      $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $cells->item(0)->textContent) ?? ''));
      $value = trim(preg_replace('/\s+/u', ' ', $cells->item(1)->textContent) ?? '');
      if ($value === '') {
        continue;
      }
      if (str_contains($label, 'project category')) {
        $values['field_reg_project_category'] = $value;
      }
      elseif (str_contains($label, 'donor') || str_contains($label, 'funder')) {
        $values['field_reg_project_funder'] = $value;
      }
      elseif ($label === 'status') {
        $values['field_reg_project_status'] = $value;
      }
      elseif (str_contains($label, 'effectiveness date')) {
        $values['field_reg_effectiveness_date'] = $this->normalizeDate($value);
      }
      elseif (str_contains($label, 'closure date')) {
        $values['field_reg_closure_date'] = $this->normalizeDate($value);
      }
    }
    $scope = $xpath->query("//h3[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'scope')]/following-sibling::*[1]")->item(0);
    if ($scope) {
      $scope_html = '';
      foreach ($scope->childNodes as $child) {
        $scope_html .= $document->saveHTML($child);
      }
      $values['field_reg_project_scope'] = ['value' => trim($scope_html), 'format' => 'full_html'];
    }
    $coverage = $xpath->query("//h3[contains(translate(normalize-space(.), 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'geographic coverage')]/following-sibling::*[1]")->item(0);
    if ($coverage) {
      $values['field_reg_geographic_coverage'] = trim(preg_replace('/\s+/u', ' ', $coverage->textContent) ?? '');
    }
    return array_filter($values, static fn($value): bool => $value !== '' && $value !== NULL);
  }

  /**
   * Normalizes a legacy human-readable date for Drupal date storage.
   */
  private function normalizeDate(string $value): string {
    $timestamp = strtotime($value . ' UTC');
    return $timestamp ? gmdate('Y-m-d', $timestamp) : '';
  }

  /**
   * Upserts one source-owned node and optional canonical alias.
   */
  private function upsertNode(string $bundle, string $source_id, array $values, ?string $alias = NULL): bool {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->condition('field_reg_source_id', $source_id)->range(0, 1)->execute();
    $node = $ids ? $storage->load(reset($ids)) : NULL;
    $created = !$node;
    $node ??= Node::create(['type' => $bundle, 'langcode' => 'en']);
    $values['field_reg_source_id'] = $source_id;
    foreach ($values as $field => $value) {
      if ($value !== NULL && ($field === 'title' || $field === 'status' || $node->hasField($field))) {
        $node->set($field, $value);
      }
    }
    if (!$created) {
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Refreshed from the official REG migration source.');
    }
    $node->save();
    if ($alias) {
      $this->ensureAlias('/node/' . $node->id(), $alias);
    }
    return $created;
  }

  /**
   * Creates or repairs one English path alias without duplicates.
   */
  private function ensureAlias(string $path, string $alias): void {
    $storage = $this->entityTypeManager->getStorage('path_alias');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('alias', $alias)->condition('langcode', 'en')->execute();
    $entities = $storage->loadMultiple($ids);
    $entity = $entities ? reset($entities) : NULL;
    if (!$entity) {
      $entity = $storage->create(['path' => $path, 'alias' => $alias, 'langcode' => 'en']);
    }
    elseif ($entity->getPath() !== $path) {
      $entity->setPath($path);
    }
    $entity->save();
  }

  /**
   * Downloads an official image and returns Media ID, local URL, and creation.
   */
  private function importImage(string $url, string $alt, string $caption): array {
    $extension = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
    $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], TRUE) ? $extension : 'jpg';
    $filename = substr(hash('sha256', $url), 0, 16) . '.' . $extension;
    $directory = 'public://reg-official/what-we-do';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $uri = $directory . '/' . $filename;
    $file_ids = $this->entityTypeManager->getStorage('file')->getQuery()->accessCheck(FALSE)->condition('uri', $uri)->range(0, 1)->execute();
    $file = $file_ids ? $this->entityTypeManager->getStorage('file')->load(reset($file_ids)) : NULL;
    $created = FALSE;
    if (!$file) {
      $response = $this->httpClient->request('GET', $url, ['timeout' => 45, 'connect_timeout' => 15]);
      $file = $this->fileRepository->writeData((string) $response->getBody(), $uri, FileExists::Replace);
      $file->setPermanent();
      $file->save();
    }
    $media_ids = $this->entityTypeManager->getStorage('media')->getQuery()->accessCheck(FALSE)->condition('bundle', 'image')->condition('field_media_image.target_id', $file->id())->range(0, 1)->execute();
    $media = $media_ids ? $this->entityTypeManager->getStorage('media')->load(reset($media_ids)) : NULL;
    if (!$media) {
      $media = Media::create([
        'bundle' => 'image', 'name' => $caption ?: ($alt ?: 'Official REG What We Do image'), 'status' => 1,
        'field_media_image' => ['target_id' => $file->id(), 'alt' => $alt ?: 'Official REG energy infrastructure', 'title' => $caption],
        'field_reg_official_source_url' => ['uri' => $url],
        'field_reg_official_alt' => $alt,
        'field_reg_official_caption' => $caption,
      ]);
      $media->save();
      $created = TRUE;
    }
    return [(int) $media->id(), $this->fileSystem->realpath($uri) ? '/sites/default/files/reg-official/what-we-do/' . $filename : '', $created];
  }

  /**
   * Resolves a possibly relative official URL.
   */
  private function absoluteUrl(string $url, string $base): string {
    if ($url === '' || str_starts_with($url, '#') || str_starts_with($url, 'mailto:') || str_starts_with($url, 'tel:')) {
      return $url;
    }
    if (preg_match('#^https?://#i', $url)) {
      return $url;
    }
    if (str_starts_with($url, '//')) {
      return 'https:' . $url;
    }
    return self::BASE_URL . '/' . ltrim($url, '/');
  }

  /**
   * Rewrites known legacy source paths to canonical Drupal destinations.
   */
  private function rewriteOfficialLink(string $url): string {
    foreach (self::pageManifest() as $definition) {
      if (rtrim($url, '/') === rtrim($definition['source'], '/')) {
        return $definition['alias'];
      }
    }
    return $url;
  }

  /**
   * Enables module-owned menu links only after real destination content exists.
   */
  private function activateNavigation(): void {
    $destinations = [
      'what-we-do' => '/what-we-do', 'work-overview' => '/what-we-do',
      'work-generation' => '/what-we-do/generation', 'work-hydropower' => '/what-we-do/generation/hydropower',
      'work-solar' => '/what-we-do/generation/solar', 'work-methane-gas' => '/what-we-do/generation/methane-gas',
      'work-peat' => '/what-we-do/generation/peat', 'work-thermal' => '/what-we-do/generation/thermal',
      'work-geothermal' => '/what-we-do/generation/geothermal', 'work-transmission' => '/what-we-do/transmission',
      'work-distribution' => '/what-we-do/distribution', 'work-electricity-access' => '/what-we-do/access',
      'work-on-grid' => '/what-we-do/access/on-grid', 'work-off-grid' => '/what-we-do/access/off-grid',
      'work-mini-grids' => '/what-we-do/off-grid/mini-grids',
      'work-solar-home-systems' => '/what-we-do/off-grid/solar-home-systems',
      'work-biomass-clean-cooking' => '/what-we-do/biomass-clean-cooking', 'work-petroleum' => '/what-we-do/petroleum',
      'work-projects' => '/projects', 'work-rbf-window-5' => '/what-we-do/programs/rbf-window-5',
      'work-clean-cooking-rbf' => '/what-we-do/programs/clean-cooking',
      'work-productive-use-energy' => '/what-we-do/programs/productive-use-of-energy',
      'work-opportunities' => '/what-we-do/investment/opportunities', 'work-incentives' => '/what-we-do/investment/incentives',
      'work-investment-procedures' => 'https://businessprocedures.rdb.rw/',
      'work-independent-power-producers' => '/what-we-do/investment/ipp',
    ];
    $group_ids = ['work-electricity-system', 'work-energy-solutions', 'work-programs', 'work-investment'];
    $storage = $this->entityTypeManager->getStorage('menu_link_content');
    $ids = $storage->getQuery()->accessCheck(FALSE)->condition('menu_name', 'main')->execute();
    foreach ($storage->loadMultiple($ids) as $link) {
      $value = $link->get('link')->first()?->getValue() ?? [];
      $options = $value['options'] ?? [];
      if (is_string($options)) {
        $options = @unserialize($options, ['allowed_classes' => FALSE]) ?: [];
      }
      $marker = (string) ($options['reg_core_navigation_id'] ?? '');
      $id = str_starts_with($marker, 'main:') ? substr($marker, 5) : '';
      if (isset($destinations[$id])) {
        $destination = $destinations[$id];
        $uri = str_starts_with($destination, 'http') ? $destination : 'internal:' . $destination;
        $link->set('link', ['uri' => $uri, 'options' => $options]);
        $link->set('enabled', TRUE)->save();
      }
      elseif (in_array($id, $group_ids, TRUE)) {
        $link->set('enabled', TRUE)->save();
      }
    }
  }

}
