<?php

namespace Drupal\reg_core\Service;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionPathResolver;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Drupal\image\Entity\ImageStyle;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Resolves one deterministic, published hero for the active public section.
 */
final class SectionHeroResolver implements SectionHeroResolverInterface {

  private const DEFAULT_FILES = [
    'about_reg' => 'about-reg.png',
    'what_we_do' => 'what-we-do.png',
    'customer_services' => 'customer-services.png',
    'public_information' => 'public-information.png',
    'media_center' => 'media-center.png',
    'sports' => 'sports.png',
    'contact_branches' => 'contact-branches.png',
  ];

  private const SECTION_LABELS = [
    'about_reg' => 'About REG',
    'what_we_do' => 'What We Do',
    'customer_services' => 'Customer Services',
    'public_information' => 'Public Information',
    'media_center' => 'Media Center',
    'sports' => 'REG Sports',
    'contact_branches' => 'Contact and Branches',
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly CacheBackendInterface $cache,
    private readonly RequestStack $requestStack,
    private readonly RouteMatchInterface $routeMatch,
    private readonly ExtensionPathResolver $extensionPathResolver,
    private readonly LoggerChannelInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function resolve(array $overrides = [], ?string $path = NULL): array {
    $request = $this->requestStack->getCurrentRequest();
    $path ??= $request?->getPathInfo() ?: '/';
    $route_name = $this->routeMatch->getRouteName() ?: '';
    $section_key = self::mapSectionKey($path, $route_name);
    if ($section_key === '') {
      return [];
    }

    $langcode = $this->languageManager->getCurrentLanguage()->getId();
    $normalized_path = self::normalizePath($path);
    $route_node = $this->routeMatch->getParameter('node');
    $bundle = $route_node instanceof NodeInterface ? $route_node->bundle() : '';
    $size = self::mapHeroSize($normalized_path, $route_name, $bundle);
    $cid = 'reg_core:section_hero:v2:' . $langcode . ':' . hash('sha256', $section_key . ':' . $normalized_path);
    if ($cached = $this->cache->get($cid)) {
      return array_replace($cached->data, array_filter($overrides, static fn(mixed $value): bool => $value !== NULL));
    }

    $hero = $this->fallback($section_key);
    $cache_tags = ['node_list:reg_section_hero'];
    $candidates = $this->loadCandidates($section_key, $langcode);
    $page_candidates = [];
    $section_candidates = [];
    foreach ($candidates as $candidate) {
      $patterns = $this->pagePatterns($candidate);
      if ($patterns && $this->pathMatches($normalized_path, $patterns)) {
        $page_candidates[] = $candidate;
      }
      elseif (!$patterns) {
        $section_candidates[] = $candidate;
      }
    }

    $eligible = $page_candidates ?: $section_candidates;
    if (count($eligible) > 1) {
      $this->logger->warning('Multiple active Section Heroes match %section in %language at %path. Weight and node ID selected node %node.', [
        '%section' => $section_key,
        '%language' => $langcode,
        '%path' => $normalized_path,
        '%node' => $eligible[0]->id(),
      ]);
    }
    if ($eligible) {
      $node = $eligible[0];
      $hero = $this->normalizeNode($node, $section_key, (bool) $page_candidates);
      $cache_tags = Cache::mergeTags($cache_tags, $node->getCacheTags());
    }

    $hero['size'] = $size;

    $this->cache->set($cid, $hero, time() + 300, $cache_tags);
    return array_replace($hero, array_filter($overrides, static fn(mixed $value): bool => $value !== NULL));
  }

  /**
   * Maps a route/path to one controlled section key.
   */
  public static function mapSectionKey(string $path, string $route_name = ''): string {
    $path = self::normalizePath($path);

    if (str_starts_with($route_name, 'reg_core.about')) {
      return 'about_reg';
    }
    if ($route_name === 'reg_core.contact' || $route_name === 'reg_core.report_problem' || str_starts_with($route_name, 'reg_core.branch')) {
      return 'contact_branches';
    }
    if ($route_name === 'reg_core.complaints' || $route_name === 'reg_core.customer_services' || $route_name === 'reg_core.services' || $route_name === 'reg_core.faq' || $route_name === 'reg_core.bill_estimator' || str_starts_with($route_name, 'reg_core.outage')) {
      return 'customer_services';
    }
    if (str_starts_with($route_name, 'reg_core.public_information') || str_starts_with($route_name, 'reg_core.tender') || str_starts_with($route_name, 'reg_core.job') || str_starts_with($route_name, 'reg_core.publication')) {
      return 'public_information';
    }
    if (str_starts_with($route_name, 'reg_core.sports')) {
      return 'sports';
    }
    if ($route_name === 'reg_core.videos') {
      return 'media_center';
    }

    $patterns = [
      'about_reg' => '#^/(?:about|about-reg)(?:/|$)#',
      'what_we_do' => '#^/(?:what-we-do|generation|transmission|distribution|access|projects)(?:/|$)#',
      'customer_services' => '#^/(?:customer-services|outages|online-services|tools/bill-estimator|help/faq|complaints)(?:/|$)#',
      'public_information' => '#^/(?:public-information|procurement|careers|tenders|jobs|publications)(?:/|$)#',
      'media_center' => '#^/(?:media|news|videos)(?:/|$)#',
      'sports' => '#^/sports(?:/|$)#',
      'contact_branches' => '#^/(?:contact|branches|report-problem)(?:/|$)#',
    ];
    foreach ($patterns as $key => $pattern) {
      if (preg_match($pattern, $path)) {
        return $key;
      }
    }
    return '';
  }

  /**
   * Returns controlled section choices for forms and administration tables.
   */
  public static function sectionOptions(): array {
    return self::SECTION_LABELS;
  }

  /**
   * Maps public route context to one controlled internal hero size.
   */
  public static function mapHeroSize(string $path, string $route_name = '', string $bundle = ''): string {
    $path = self::normalizePath($path);
    $detail_routes = [
      'reg_core.branch_detail',
      'reg_core.outage_detail',
      'reg_core.tender_detail',
      'reg_core.job_detail',
      'reg_core.publication_detail',
      'reg_core.sports_news_detail',
      'reg_core.sports_player',
      'reg_core.sports_fixture',
      'reg_core.sports_gallery_detail',
    ];
    $detail_bundles = [
      'reg_job',
      'reg_leader',
      'reg_news',
      'reg_project',
      'reg_publication',
      'reg_tender',
    ];
    if (
      in_array($route_name, $detail_routes, TRUE)
      || in_array($bundle, $detail_bundles, TRUE)
      || preg_match('#^/(?:news|projects)/[^/]+$#', $path)
    ) {
      return 'compact';
    }

    if (in_array($path, [
      '/about',
      '/what-we-do',
      '/customer-services',
      '/public-information',
      '/media',
      '/sports',
      '/contact',
      '/branches',
    ], TRUE)) {
      return 'landing';
    }

    return 'standard';
  }

  /**
   * Loads active published candidates in deterministic priority order.
   *
   * @return \Drupal\node\NodeInterface[]
   *   Candidate nodes ordered by ascending weight then node ID.
   */
  private function loadCandidates(string $section_key, string $langcode): array {
    try {
      $storage = $this->entityTypeManager->getStorage('node');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'reg_section_hero')
        ->condition('status', NodeInterface::PUBLISHED)
        ->condition('field_reg_active', 1)
        ->condition('field_reg_section_key', $section_key)
        ->sort('field_reg_order', 'ASC')
        ->sort('nid', 'ASC')
        ->execute();
      $nodes = [];
      foreach ($storage->loadMultiple($ids) as $node) {
        if ($node->hasTranslation($langcode)) {
          $translation = $node->getTranslation($langcode);
          if ($translation->isPublished()) {
            $node = $translation;
          }
        }
        if ($node instanceof NodeInterface && $node->isPublished()) {
          $nodes[] = $node;
        }
      }
      return $nodes;
    }
    catch (\Throwable $exception) {
      $this->logger->error('Section Hero resolution failed for %section: %message', [
        '%section' => $section_key,
        '%message' => $exception->getMessage(),
      ]);
      return [];
    }
  }

  /**
   * Converts one Section Hero node to a public view model.
   */
  private function normalizeNode(NodeInterface $node, string $section_key, bool $page_specific): array {
    $fallback = $this->fallback($section_key);
    $background = $this->mediaImage($node, 'field_reg_background_image', 'reg_section_hero_desktop');
    $mobile = $this->mediaImage($node, 'field_reg_mobile_background', 'reg_section_hero_mobile');
    $alignment = $this->fieldValue($node, 'field_reg_text_alignment');
    $text_theme = $this->fieldValue($node, 'field_reg_text_theme');
    $overlay = $this->fieldValue($node, 'field_reg_overlay_strength');

    return [
      'id' => (int) $node->id(),
      'section_key' => $section_key,
      'section_class' => 'reg-section-hero--' . str_replace('_', '-', $section_key),
      'eyebrow' => $this->fieldValue($node, 'field_reg_eyebrow') ?: $fallback['eyebrow'],
      'title' => trim(strip_tags($node->label())) ?: $fallback['title'],
      'description' => $this->fieldValue($node, 'field_reg_description'),
      'background_image' => $background ?: $fallback['background_image'],
      'mobile_background_image' => $mobile,
      'primary_cta' => $this->cta($node, 'field_reg_primary_cta_label', 'field_reg_primary_cta_url'),
      'secondary_cta' => $this->cta($node, 'field_reg_secondary_cta_label', 'field_reg_secondary_cta_url'),
      'text_alignment' => in_array($alignment, ['left', 'center', 'right'], TRUE) ? $alignment : 'left',
      'text_theme' => in_array($text_theme, ['light_background', 'dark_background'], TRUE) ? $text_theme : 'light_background',
      'overlay' => in_array($overlay, ['none', 'light', 'medium', 'strong'], TRUE) ? $overlay : 'light',
      'page_specific' => $page_specific,
      'source' => 'cms',
      'cache_tags' => $node->getCacheTags(),
    ];
  }

  /**
   * Builds a safe CTA from separate translatable label and URL fields.
   */
  private function cta(NodeInterface $node, string $label_field, string $url_field): array {
    $label = $this->fieldValue($node, $label_field);
    if ($label === '' || !$node->hasField($url_field) || $node->get($url_field)->isEmpty()) {
      return [];
    }
    try {
      return ['label' => $label, 'url' => $node->get($url_field)->first()->getUrl()->toString()];
    }
    catch (\Throwable) {
      return [];
    }
  }

  /**
   * Resolves a bounded derivative from an image Media reference.
   */
  private function mediaImage(NodeInterface $node, string $field_name, string $style_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    $media = $node->get($field_name)->entity;
    if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->hasField('field_media_image') || $media->get('field_media_image')->isEmpty()) {
      return '';
    }
    $file = $media->get('field_media_image')->entity;
    if (!$file) {
      return '';
    }
    try {
      $style = ImageStyle::load($style_name);
      $url = $style ? $style->buildUrl($file->getFileUri()) : $this->fileUrlGenerator->generateString($file->getFileUri());
      // Never cache a request-host-specific absolute URL. Internal health
      // checks commonly use localhost while public requests use the DDEV or
      // production hostname.
      return $this->fileUrlGenerator->transformRelative($url);
    }
    catch (\Throwable) {
      return '';
    }
  }

  /**
   * Returns a plain text value without allowing editor markup into attributes.
   */
  private function fieldValue(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    return trim(strip_tags((string) ($node->get($field_name)->first()?->getValue()['value'] ?? '')));
  }

  /**
   * Returns normalized page-specific paths attached to a hero.
   */
  private function pagePatterns(NodeInterface $node): array {
    $value = $this->fieldValue($node, 'field_reg_page_paths');
    if ($value === '') {
      return [];
    }
    return array_values(array_filter(array_map(
      static fn(string $item): string => self::normalizePath(trim($item)),
      preg_split('/[\r\n,]+/', $value) ?: [],
    )));
  }

  /**
   * Checks exact paths and bounded trailing-wildcard patterns.
   */
  private function pathMatches(string $path, array $patterns): bool {
    foreach ($patterns as $pattern) {
      if (str_ends_with($pattern, '/*')) {
        $prefix = substr($pattern, 0, -2);
        if ($path !== $prefix && str_starts_with($path, $prefix . '/')) {
          return TRUE;
        }
      }
      elseif ($path === $pattern) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Builds the resilient bundled fallback for one controlled section.
   */
  private function fallback(string $section_key): array {
    $label = self::SECTION_LABELS[$section_key] ?? 'REG';
    $url = '';
    $filename = self::DEFAULT_FILES[$section_key] ?? '';
    if ($filename !== '') {
      $module_path = $this->extensionPathResolver->getPath('module', 'reg_core');
      if (is_file(DRUPAL_ROOT . '/' . $module_path . '/assets/section-heroes/' . $filename)) {
        $base_path = $this->requestStack->getCurrentRequest()?->getBasePath() ?: '';
        $url = rtrim($base_path, '/') . '/' . $module_path . '/assets/section-heroes/' . $filename;
      }
    }
    return [
      'id' => 0,
      'section_key' => $section_key,
      'section_class' => 'reg-section-hero--' . str_replace('_', '-', $section_key),
      'eyebrow' => $label,
      'title' => $label,
      'description' => '',
      'background_image' => $url,
      'mobile_background_image' => '',
      'primary_cta' => [],
      'secondary_cta' => [],
      'text_alignment' => 'left',
      'text_theme' => 'light_background',
      'overlay' => 'light',
      'page_specific' => FALSE,
      'source' => $url === '' ? 'plain' : 'bundled_fallback',
      'cache_tags' => ['node_list:reg_section_hero'],
    ];
  }

  /**
   * Normalizes language-prefixed paths for consistent section matching.
   */
  private static function normalizePath(string $path): string {
    $path = '/' . ltrim(parse_url($path, PHP_URL_PATH) ?: '/', '/');
    $path = preg_replace('#^/(?:en|rw)(?=/|$)#', '', $path) ?: '/';
    return $path !== '/' ? rtrim($path, '/') : '/';
  }

}
