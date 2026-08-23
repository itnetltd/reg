<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\reg_core\About\AboutRepositoryInterface;
use Drupal\reg_core\Service\SectionHeroResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public CMS-driven About REG routes.
 */
final class AboutController extends ControllerBase {

  private const ROUTES = [
    'about' => 'reg_core.about',
    'history' => 'reg_core.about_history',
    'vision_mission_values' => 'reg_core.about_vision',
    'group' => 'reg_core.about_group',
    'edcl' => 'reg_core.about_edcl',
    'eucl' => 'reg_core.about_eucl',
    'leadership' => 'reg_core.about_leadership',
    'board' => 'reg_core.about_board',
    'executive_management' => 'reg_core.about_executive',
    'partners' => 'reg_core.about_partners',
    'our_people' => 'reg_core.about_people',
  ];

  public function __construct(
    private readonly AboutRepositoryInterface $aboutRepository,
    private readonly SectionHeroResolverInterface $sectionHeroResolver,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(AboutRepositoryInterface::class),
      $container->get(SectionHeroResolverInterface::class),
    );
  }

  /**
   * Builds one public About page from structured Drupal content.
   */
  public function page(string $page_key, Request $request): array {
    if (!isset(self::ROUTES[$page_key])) {
      throw new NotFoundHttpException();
    }
    $page = $this->aboutRepository->page($page_key);
    if ($page === NULL) {
      throw new NotFoundHttpException();
    }

    $hero = $this->sectionHeroResolver->resolve([
      'eyebrow' => (string) $this->t('About REG'),
      'title' => $page['title'],
      'description' => $page['summary'],
    ], $request->getPathInfo());
    $canonical = Url::fromRoute(self::ROUTES[$page_key], [], ['absolute' => TRUE])->toString();
    $build = [
      '#theme' => 'reg_about_page',
      '#page' => $page,
      '#section_hero' => $hero,
      '#breadcrumbs' => $this->breadcrumbs($page_key, $page['title']),
      '#links' => $this->links(),
      '#vision_page' => [],
      '#group_page' => [],
      '#values' => [],
      '#subsidiaries' => [],
      '#board' => [],
      '#executives' => [],
      '#stakeholders' => [],
      '#development_partners' => [],
      '#employees' => [],
      '#branch_count' => 0,
      '#attached' => [
        'library' => ['reg_core/about'],
        ...$this->metadata($canonical, $page, $hero, $request),
      ],
      '#cache' => [
        'contexts' => ['languages:language_interface', 'url.path'],
        'tags' => [
          'node_list:reg_about_page',
          'node_list:reg_value',
          'node_list:reg_leader',
          'node_list:reg_employee_recognition',
          'node_list:reg_partner',
          'node_list:reg_branch',
          'node_list:reg_section_hero',
        ],
        'max-age' => 300,
      ],
    ];

    if (in_array($page_key, ['about', 'vision_mission_values'], TRUE)) {
      $build['#values'] = $this->aboutRepository->values();
    }
    if ($page_key === 'about') {
      $build['#vision_page'] = $this->aboutRepository->page('vision_mission_values') ?? [];
      $build['#group_page'] = $this->aboutRepository->page('group') ?? [];
    }
    if (in_array($page_key, ['about', 'group'], TRUE)) {
      $build['#subsidiaries'] = array_values(array_filter([
        $this->aboutRepository->page('edcl'),
        $this->aboutRepository->page('eucl'),
      ]));
    }
    if (in_array($page_key, ['about', 'leadership', 'board'], TRUE)) {
      $build['#board'] = $this->aboutRepository->leaders('board');
    }
    if (in_array($page_key, ['about', 'leadership', 'executive_management'], TRUE)) {
      $build['#executives'] = $this->aboutRepository->leaders('executive');
    }
    if (in_array($page_key, ['about', 'partners'], TRUE)) {
      $build['#stakeholders'] = $this->aboutRepository->partners('stakeholder');
      $build['#development_partners'] = $this->aboutRepository->partners('development_partner');
    }
    if (in_array($page_key, ['about', 'our_people'], TRUE)) {
      $build['#employees'] = $this->aboutRepository->employees();
    }
    if ($page_key === 'about') {
      $build['#branch_count'] = $this->aboutRepository->branchCount();
    }

    return $build;
  }

  /**
   * Returns a CMS title for route and browser-title resolution.
   */
  public function title(string $page_key): string {
    return (string) ($this->aboutRepository->page($page_key)['meta_title'] ?? $this->t('About REG'));
  }

  /**
   * Returns all canonical section links once for templates.
   */
  private function links(): array {
    $links = [];
    foreach (self::ROUTES as $key => $route) {
      $links[$key] = Url::fromRoute($route)->toString();
    }
    $links['branches'] = Url::fromRoute('reg_core.branches')->toString();
    return $links;
  }

  /**
   * Builds compact semantic breadcrumbs for each About route.
   */
  private function breadcrumbs(string $key, string $title): array {
    $items = [
      ['label' => (string) $this->t('Home'), 'url' => Url::fromRoute('<front>')->toString()],
    ];
    if ($key !== 'about') {
      $items[] = ['label' => (string) $this->t('About REG'), 'url' => Url::fromRoute('reg_core.about')->toString()];
    }
    if (in_array($key, ['edcl', 'eucl'], TRUE)) {
      $items[] = ['label' => (string) $this->t('REG Group'), 'url' => Url::fromRoute('reg_core.about_group')->toString()];
    }
    if (in_array($key, ['board', 'executive_management'], TRUE)) {
      $items[] = ['label' => (string) $this->t('Leadership'), 'url' => Url::fromRoute('reg_core.about_leadership')->toString()];
    }
    $items[] = ['label' => $title, 'url' => ''];
    return $items;
  }

  /**
   * Adds canonical, description and Open Graph metadata without extra modules.
   */
  private function metadata(string $canonical, array $page, array $hero, Request $request): array {
    $title = $page['meta_title'] ?: $page['title'];
    $description = $page['meta_description'] ?: $page['summary'];
    $head = [
      [['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => $description]], 'reg_about_description'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:title', 'content' => $title]], 'reg_about_og_title'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:description', 'content' => $description]], 'reg_about_og_description'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:url', 'content' => $canonical]], 'reg_about_og_url'],
      [['#tag' => 'meta', '#attributes' => ['property' => 'og:type', 'content' => 'website']], 'reg_about_og_type'],
    ];
    $image = $page['image']['url'] ?? ($hero['background_image'] ?? '');
    if ($image !== '') {
      if (str_starts_with($image, '/')) {
        $image = $request->getSchemeAndHttpHost() . $image;
      }
      $head[] = [['#tag' => 'meta', '#attributes' => ['property' => 'og:image', 'content' => $image]], 'reg_about_og_image'];
    }
    return [
      'html_head_link' => [[['rel' => 'canonical', 'href' => $canonical], TRUE]],
      'html_head' => $head,
    ];
  }

}
