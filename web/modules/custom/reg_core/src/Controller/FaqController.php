<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\PrivateKey;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Service\FaqSearchInterface;
use Drupal\reg_core\Service\FaqUnansweredLoggerInterface;
use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Public bilingual FAQ page and read-only assistant search endpoint.
 */
final class FaqController extends ControllerBase {

  public function __construct(
    private readonly FaqSearchInterface $faqSearch,
    private readonly FaqUnansweredLoggerInterface $unansweredLogger,
    private readonly ConfigFactoryInterface $regConfigFactory,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly FloodInterface $flood,
    private readonly PrivateKey $privateKey,
    private readonly AccountProxyInterface $regCurrentUser,
    private readonly AnalyticsEventTrackerInterface $analytics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(FaqSearchInterface::class),
      $container->get(FaqUnansweredLoggerInterface::class),
      $container->get('config.factory'),
      $container->get('language_manager'),
      $container->get('flood'),
      $container->get('private_key'),
      $container->get('current_user'),
      $container->get(AnalyticsEventTrackerInterface::class),
    );
  }

  /**
   * Builds the language-aware public FAQ knowledge base.
   */
  public function page(Request $request): array {
    $query = $this->queryValue($request, 'q', 120);
    $category = mb_strtolower($this->queryValue($request, 'category', 64));
    $categories = $this->faqSearch->categories();
    if (!isset($categories[$category])) {
      $category = '';
    }
    $search_requested = $query !== '' || $category !== '';
    $rate_limited = $search_requested && !$this->allowSearch($request);
    $items = $rate_limited ? [] : $this->faqSearch->search($query, $category, 200);
    if ($search_requested && !$rate_limited) {
      $this->analytics->record('faq_search', $this->analyticsContext($category));
      if (!$items) {
        $this->analytics->record('faq_no_result', $this->analyticsContext($category));
      }
    }
    $escalation = $this->escalationOptions();
    $items = $this->attachItemEscalations($items, $escalation);
    if (!$rate_limited && $query !== '' && !$items && $this->regCurrentUser->isAnonymous()) {
      $this->unansweredLogger->record($query, $this->regLanguageManager->getCurrentLanguage()->getId());
    }

    $category_links = [];
    foreach ($categories as $id => $label) {
      $category_links[] = [
        'id' => $id,
        'label' => $label,
        'url' => Url::fromRoute('reg_core.faq', [], ['query' => ['category' => $id]])->toString(),
        'active' => $category === $id,
      ];
    }

    return [
      '#theme' => 'reg_faq_page',
      '#items' => $items,
      '#query' => $query,
      '#current_category' => $category,
      '#category_links' => $category_links,
      '#quick_categories' => array_values(array_filter($category_links, static fn(array $item): bool => in_array($item['id'], [
        'outages',
        'online_services',
        'connection',
        'tariffs',
        'complaints',
        'branches',
      ], TRUE))),
      '#results_count' => count($items),
      '#rate_limited' => $rate_limited,
      '#escalation' => $escalation,
      '#language_name' => $this->regLanguageManager->getCurrentLanguage()->getName(),
      '#clear_url' => Url::fromRoute('reg_core.faq')->toString(),
      '#attached' => ['library' => ['reg_core/faq_page']],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'url.query_args:q', 'url.query_args:category'],
        'tags' => ['node_list:reg_faq', 'config:reg_core.settings'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Returns at most five approved FAQ answers as plain JSON.
   */
  public function search(Request $request): JsonResponse {
    $query = $this->queryValue($request, 'q', 120);
    $category = mb_strtolower($this->queryValue($request, 'category', 64));
    if (($query !== '' && mb_strlen($query) < 2) || ($query === '' && $category === '')) {
      return $this->json(['items' => [], 'escalation' => $this->escalationOptions()]);
    }

    if (!$this->allowSearch($request)) {
      $response = $this->json([
        'items' => [],
        'message' => (string) $this->t('Too many searches. Please wait a moment or use an official support channel.'),
        'escalation' => $this->escalationOptions(),
      ], 429);
      $response->headers->set('Retry-After', '60');
      return $response;
    }
    $items = $this->faqSearch->search($query, $category, 5);
    $this->analytics->record('faq_search', $this->analyticsContext($category));
    if (!$items) {
      $this->analytics->record('faq_no_result', $this->analyticsContext($category));
    }
    $escalation = $this->escalationOptions();
    $items = $this->attachItemEscalations($items, $escalation);
    foreach ($items as &$item) {
      $item['answer'] = mb_substr((string) $item['answer'], 0, 600);
    }
    unset($item);

    if (!$items && $query !== '' && $this->regCurrentUser->isAnonymous()) {
      $this->unansweredLogger->record($query, $this->regLanguageManager->getCurrentLanguage()->getId());
    }

    return $this->json([
      'items' => $items,
      'langcode' => $this->regLanguageManager->getCurrentLanguage()->getId(),
      'escalation' => $escalation,
    ]);
  }

  /**
   * Applies a shared privacy-preserving throttle to page and assistant search.
   */
  private function allowSearch(Request $request): bool {
    $identifier = hash_hmac('sha256', (string) ($request->getClientIp() ?: 'unknown'), $this->privateKey->get());
    if (!$this->flood->isAllowed('reg_core.faq_search', 40, 60, $identifier)) {
      return FALSE;
    }
    $this->flood->register('reg_core.faq_search', 60, $identifier);
    return TRUE;
  }

  /**
   * Returns configured support alternatives without external URL literals.
   */
  private function escalationOptions(): array {
    $config = $this->regConfigFactory->get('reg_core.settings');
    $number = trim((string) ($config->get('support.call_center') ?: '2727'));
    $telephone = preg_replace('/[^0-9+]/', '', $number) ?: '2727';
    $links = [];
    foreach ([
      ['complaints', (string) $this->t('Report Fault / Complaint'), Url::fromRoute('reg_core.complaints')->toString()],
      ['online_services', (string) $this->t('Online Services'), (string) ($config->get('links.online_services') ?: Url::fromRoute('reg_core.customer_services')->toString())],
      ['branches', (string) $this->t('Branch Locator'), Url::fromRoute('reg_core.branches')->toString()],
    ] as [$id, $label, $uri]) {
      $url = $this->safeUrl($uri);
      if ($url !== NULL) {
        $links[] = ['id' => $id, 'label' => $label, 'url' => $url];
      }
    }
    $links[] = [
      'id' => 'faq',
      'label' => (string) $this->t('Browse All FAQs'),
      'url' => Url::fromRoute('reg_core.faq')->toString(),
    ];
    return [
      'call_center' => [
        'id' => 'call_center',
        'label' => (string) $this->t('Call Center @number', ['@number' => $number]),
        'url' => 'tel:' . $telephone,
      ],
      'links' => $links,
    ];
  }

  /**
   * Resolves each FAQ's editorial escalation channel to configured contact data.
   */
  private function attachItemEscalations(array $items, array $escalation): array {
    $options = [$escalation['call_center']['id'] => $escalation['call_center']];
    foreach ($escalation['links'] as $link) {
      $options[$link['id']] = $link;
    }
    foreach ($items as &$item) {
      $channel = (string) ($item['escalation'] ?? '');
      $item['escalation_option'] = $options[$channel] ?? NULL;
      unset($item['escalation']);
    }
    unset($item);
    return $items;
  }

  /**
   * Converts one configured internal or external URI to a safe public URL.
   */
  private function safeUrl(string $uri): ?string {
    $uri = trim($uri);
    if ($uri === '') {
      return NULL;
    }
    try {
      return (str_starts_with($uri, '/') ? Url::fromUserInput($uri) : Url::fromUri($uri))->toString();
    }
    catch (\InvalidArgumentException) {
      return NULL;
    }
  }

  /**
   * Reads a bounded plain-text query parameter.
   */
  private function queryValue(Request $request, string $key, int $length): string {
    $value = preg_replace('/\s+/u', ' ', trim(strip_tags((string) $request->query->get($key, '')))) ?? '';
    return mb_substr($value, 0, $length);
  }

  /**
   * Keeps FAQ analytics independent from the user's search phrase.
   */
  private function analyticsContext(string $category): array {
    return $category !== ''
      ? ['dimension_type' => 'category', 'dimension_value' => $category]
      : [];
  }

  /**
   * Builds a non-cacheable JSON response with browser hardening headers.
   */
  private function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    return $response;
  }

}
