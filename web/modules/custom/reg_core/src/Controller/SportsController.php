<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\reg_core\Sports\SportsRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public routes for the CMS-managed REG Sports Portal.
 */
final class SportsController extends ControllerBase {

  public function __construct(
    private readonly SportsRepositoryInterface $repository,
    private readonly PagerManagerInterface $regPagerManager,
    private readonly ConfigFactoryInterface $regConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reg_core.sports_repository'),
      $container->get('pager.manager'),
      $container->get('config.factory'),
    );
  }

  /**
   * Builds the dynamic sports landing page.
   */
  public function landing(): array {
    $content = $this->repository->landing();
    if (!$content['teams']) {
      $content['teams'] = $this->teamNavigation();
    }
    $social_links = [];
    foreach ($content['teams'] as $team) {
      $social_links = array_merge($social_links, $team['social_links'] ?? []);
    }
    $content['social_links'] = $social_links;
    $content['social_embed_enabled'] = (bool) $this->regConfigFactory->get('reg_core.settings')->get('sports.social_embed_enabled');

    return [
      '#theme' => 'reg_sports_landing',
      '#content' => $content,
      '#links' => $this->portalLinks(),
      '#attached' => $this->metadata(
        Url::fromRoute('reg_core.sports', [], ['absolute' => TRUE])->toString(),
        (string) $this->t('Official REG Basketball Men, Basketball Women, and Volleyball Men fixtures, results, teams, players, standings, news, galleries, and videos.'),
      ),
      '#cache' => $this->cacheability(),
    ];
  }

  /**
   * Builds the REG Basketball Men page.
   */
  public function basketballMen(): array {
    return $this->teamPage('basketball_men');
  }

  /**
   * Builds the REG Basketball Women page.
   */
  public function basketballWomen(): array {
    return $this->teamPage('basketball_women');
  }

  /**
   * Builds the REG Volleyball Men page.
   */
  public function volleyballMen(): array {
    return $this->teamPage('volleyball_men');
  }

  /**
   * Builds one team page from a controlled team identifier.
   */
  private function teamPage(string $key): array {
    $team = $this->repository->team($key);
    if ($team === NULL) {
      $team = $this->teamNavigation()[$key] + [
        'description' => '',
        'competition' => '',
        'season' => '',
        'coach' => '',
        'technical_summary' => '',
        'venue' => '',
        'logo' => [],
        'image' => [],
        'social_links' => [],
        'players' => [],
        'staff' => [],
        'fixtures' => [],
        'results' => [],
        'standings' => [],
        'news' => [],
        'galleries' => [],
        'videos' => [],
      ];
    }
    return [
      '#theme' => 'reg_sports_team',
      '#team' => $team,
      '#links' => $this->portalLinks(),
      '#attached' => $this->metadata(
        Url::fromRoute($this->teamRoute($key), [], ['absolute' => TRUE])->toString(),
        $team['description'] ?: (string) $this->t('Official @team fixtures, results, roster, standings, news, galleries, and videos.', ['@team' => $team['title']]),
      ),
      '#cache' => $this->cacheability(),
    ];
  }

  /**
   * Lists upcoming fixtures.
   */
  public function fixtures(Request $request): array {
    return $this->listingPage('reg_sports_fixture', 'fixtures', $request, $this->t('Upcoming Fixtures'), $this->t('No upcoming fixtures are currently published.'));
  }

  /**
   * Lists completed results.
   */
  public function results(Request $request): array {
    return $this->listingPage('reg_sports_fixture', 'results', $request, $this->t('Latest Results'), $this->t('No results are currently available.'));
  }

  /**
   * Lists sports news.
   */
  public function news(Request $request): array {
    return $this->listingPage('reg_sports_update', 'news', $request, $this->t('Sports News'), $this->t('No sports stories are currently published.'));
  }

  /**
   * Lists player profiles.
   */
  public function players(Request $request): array {
    return $this->listingPage('reg_sports_player', 'players', $request, $this->t('Player Directory'), $this->t('No players are currently published for these filters.'));
  }

  /**
   * Lists galleries.
   */
  public function gallery(Request $request): array {
    return $this->listingPage('reg_sports_gallery', 'gallery', $request, $this->t('Sports Gallery'), $this->t('No sports galleries are currently published.'));
  }

  /**
   * Lists approved remote videos.
   */
  public function videos(Request $request): array {
    return $this->listingPage('reg_sports_video', 'videos', $request, $this->t('Video Highlights'), $this->t('No approved video highlights are currently published.'));
  }

  /**
   * Builds a shared, filtered listing page.
   */
  private function listingPage(string $bundle, string $view, Request $request, string $heading, string $empty): array {
    $filters = $this->filters($request) + ['mode' => in_array($view, ['fixtures', 'results'], TRUE) ? $view : ''];
    $all = $this->repository->listing($bundle, $filters);
    return [
      '#theme' => 'reg_sports_listing',
      '#view' => $view,
      '#heading' => $heading,
      '#items' => $this->paginate($all),
      '#count' => count($all),
      '#empty_message' => $empty,
      '#filters' => $filters,
      '#teams' => $this->repository->teamOptions(),
      '#sports' => $this->repository->taxonomyOptions('reg_sports_sport'),
      '#competitions' => $this->repository->taxonomyOptions('reg_sports_competition'),
      '#seasons' => $this->repository->taxonomyOptions('reg_sports_season'),
      '#positions' => $this->repository->taxonomyOptions('reg_sports_position'),
      '#pager' => ['#type' => 'pager'],
      '#links' => $this->portalLinks(),
      '#attached' => $this->metadata(
        Url::fromRoute('reg_core.sports_' . $view, [], ['absolute' => TRUE])->toString(),
        (string) $heading . ' – ' . (string) $this->t('official published REG Sports information.'),
      ),
      '#cache' => $this->cacheability(TRUE),
    ];
  }

  /**
   * Builds CMS-managed competition standings.
   */
  public function standings(Request $request): array {
    $filters = $this->filters($request);
    $rows = $this->repository->listing('reg_sports_standing', $filters);
    return [
      '#theme' => 'reg_sports_standings',
      '#rows' => $rows,
      '#filters' => $filters,
      '#sports' => $this->repository->taxonomyOptions('reg_sports_sport'),
      '#competitions' => $this->repository->taxonomyOptions('reg_sports_competition'),
      '#seasons' => $this->repository->taxonomyOptions('reg_sports_season'),
      '#links' => $this->portalLinks(),
      '#attached' => $this->metadata(
        Url::fromRoute('reg_core.sports_standings', [], ['absolute' => TRUE])->toString(),
        (string) $this->t('Published REG Sports standings by sport, competition, and season.'),
      ),
      '#cache' => $this->cacheability(TRUE),
    ];
  }

  /**
   * Searches published sports content.
   */
  public function search(Request $request): array {
    $query = (string) $request->query->get('q', '');
    $all = $this->repository->search($query);
    return [
      '#theme' => 'reg_sports_listing',
      '#view' => 'search',
      '#heading' => $this->t('Search REG Sports'),
      '#items' => $this->paginate($all),
      '#count' => count($all),
      '#empty_message' => $query === '' ? $this->t('Enter a team, player, story, opponent, or fixture keyword.') : $this->t('No published sports content matched your search.'),
      '#filters' => ['query' => $query],
      '#teams' => [],
      '#sports' => [],
      '#competitions' => [],
      '#seasons' => [],
      '#positions' => [],
      '#pager' => ['#type' => 'pager'],
      '#links' => $this->portalLinks(),
      '#attached' => $this->metadata(
        Url::fromRoute('reg_core.sports_search', [], ['absolute' => TRUE])->toString(),
        (string) $this->t('Search published REG teams, players, news, fixtures, and results.'),
      ),
      '#cache' => $this->cacheability(TRUE),
    ];
  }

  /**
   * Builds one player profile.
   */
  public function player(int $player): array {
    return $this->detailPage('reg_sports_player', $player, 'player_profile', 'reg_core.sports_player', 'player');
  }

  /**
   * Builds one fixture or result detail page.
   */
  public function fixture(int $fixture): array {
    return $this->detailPage('reg_sports_fixture', $fixture, 'fixture_view', 'reg_core.sports_fixture', 'fixture');
  }

  /**
   * Builds one sports news article.
   */
  public function newsDetail(int $node): array {
    return $this->detailPage('reg_sports_update', $node, 'sports_article', 'reg_core.sports_news_detail', 'node');
  }

  /**
   * Builds one gallery detail page.
   */
  public function galleryDetail(int $gallery): array {
    return $this->detailPage('reg_sports_gallery', $gallery, 'gallery_view', 'reg_core.sports_gallery_detail', 'gallery');
  }

  /**
   * Builds a shared published detail page.
   */
  private function detailPage(string $bundle, int $id, string $event, string $route, string $parameter): array {
    $item = $this->repository->detail($bundle, $id);
    if ($item === NULL) {
      throw new NotFoundHttpException();
    }
    return [
      '#theme' => 'reg_sports_detail',
      '#item' => $item,
      '#analytics_event' => $event,
      '#links' => $this->portalLinks(),
      '#attached' => $this->metadata(
        Url::fromRoute($route, [$parameter => $id], ['absolute' => TRUE])->toString(),
        $item['summary'] ?: $item['description'] ?: $item['score_label'],
        $item['image']['url'] ?? '',
      ),
      '#cache' => $this->cacheability(),
    ];
  }

  /**
   * Returns a player profile title.
   */
  public function playerTitle(int $player): string {
    return $this->title('reg_sports_player', $player);
  }

  /**
   * Returns a fixture or result title.
   */
  public function fixtureTitle(int $fixture): string {
    return $this->title('reg_sports_fixture', $fixture);
  }

  /**
   * Returns a sports article title.
   */
  public function newsTitle(int $node): string {
    return $this->title('reg_sports_update', $node);
  }

  /**
   * Returns a sports gallery title.
   */
  public function galleryTitle(int $gallery): string {
    return $this->title('reg_sports_gallery', $gallery);
  }

  /**
   * Returns a safe detail title.
   */
  private function title(string $bundle, int $id): string {
    return (string) ($this->repository->detail($bundle, $id)['title'] ?? $this->t('REG Sports'));
  }

  /**
   * Extracts supported filters from the query string.
   */
  private function filters(Request $request): array {
    return [
      'query' => (string) $request->query->get('q', ''),
      'team' => (int) $request->query->get('team', 0),
      'sport' => (int) $request->query->get('sport', 0),
      'competition' => (int) $request->query->get('competition', 0),
      'season' => (int) $request->query->get('season', 0),
      'position' => (int) $request->query->get('position', 0),
      'month' => (string) $request->query->get('month', ''),
      'status' => (string) $request->query->get('status', ''),
    ];
  }

  /**
   * Returns structural links for the sports portal navigation.
   */
  private function portalLinks(): array {
    return [
      ['label' => $this->t('Sports home'), 'url' => Url::fromRoute('reg_core.sports')->toString()],
      ['label' => $this->t('Fixtures'), 'url' => Url::fromRoute('reg_core.sports_fixtures')->toString()],
      ['label' => $this->t('Results'), 'url' => Url::fromRoute('reg_core.sports_results')->toString()],
      ['label' => $this->t('Standings'), 'url' => Url::fromRoute('reg_core.sports_standings')->toString()],
      ['label' => $this->t('News'), 'url' => Url::fromRoute('reg_core.sports_news')->toString()],
      ['label' => $this->t('Players'), 'url' => Url::fromRoute('reg_core.sports_players')->toString()],
      ['label' => $this->t('Gallery'), 'url' => Url::fromRoute('reg_core.sports_gallery')->toString()],
      ['label' => $this->t('Videos'), 'url' => Url::fromRoute('reg_core.sports_videos')->toString()],
    ];
  }

  /**
   * Returns fallback navigation for the three required teams.
   */
  private function teamNavigation(): array {
    return [
      'basketball_men' => [
        'id' => 0,
        'title' => $this->t('REG Basketball Men'),
        'team_key' => 'basketball_men',
        'sport' => $this->t('Basketball'),
        'url' => Url::fromRoute('reg_core.sports_team_basketball_men')->toString(),
        'social_links' => [],
      ],
      'basketball_women' => [
        'id' => 0,
        'title' => $this->t('REG Basketball Women'),
        'team_key' => 'basketball_women',
        'sport' => $this->t('Basketball'),
        'url' => Url::fromRoute('reg_core.sports_team_basketball_women')->toString(),
        'social_links' => [],
      ],
      'volleyball_men' => [
        'id' => 0,
        'title' => $this->t('REG Volleyball Men'),
        'team_key' => 'volleyball_men',
        'sport' => $this->t('Volleyball'),
        'url' => Url::fromRoute('reg_core.sports_team_volleyball_men')->toString(),
        'social_links' => [],
      ],
    ];
  }

  /**
   * Returns the route for a controlled team key.
   */
  private function teamRoute(string $key): string {
    return 'reg_core.sports_team_' . $key;
  }

  /**
   * Creates a pager and returns the current slice.
   */
  private function paginate(array $items): array {
    $limit = min(48, max(6, (int) ($this->regConfigFactory->get('reg_core.settings')->get('sports.results_per_page') ?: 12)));
    $pager = $this->regPagerManager->createPager(count($items), $limit);
    return array_slice($items, $pager->getCurrentPage() * $limit, $limit);
  }

  /**
   * Adds canonical, description, and social metadata.
   */
  private function metadata(string $canonical, string $description, string $image = ''): array {
    $description = mb_substr(trim(strip_tags($description)), 0, 240);
    $head = [
      [
        ['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => $description]],
        'reg_sports_description',
      ],
      [
        ['#tag' => 'meta', '#attributes' => ['property' => 'og:url', 'content' => $canonical]],
        'reg_sports_og_url',
      ],
      [
        ['#tag' => 'meta', '#attributes' => ['property' => 'og:description', 'content' => $description]],
        'reg_sports_og_description',
      ],
    ];
    if ($image !== '') {
      $head[] = [
        ['#tag' => 'meta', '#attributes' => ['property' => 'og:image', 'content' => $image]],
        'reg_sports_og_image',
      ];
    }
    return [
      'library' => ['reg_core/sports_portal'],
      'html_head_link' => [[['rel' => 'canonical', 'href' => $canonical], TRUE]],
      'html_head' => $head,
    ];
  }

  /**
   * Returns shared sports cacheability metadata.
   */
  private function cacheability(bool $query = FALSE): array {
    return [
      'contexts' => array_filter(['languages:language_interface', 'user.permissions', $query ? 'url.query_args' : NULL]),
      'tags' => array_map(static fn(string $bundle): string => 'node_list:' . $bundle, [
        'reg_sports_team',
        'reg_sports_player',
        'reg_sports_staff',
        'reg_sports_fixture',
        'reg_sports_standing',
        'reg_sports_update',
        'reg_sports_gallery',
        'reg_sports_video',
      ]),
      'max-age' => 300,
    ];
  }

}
