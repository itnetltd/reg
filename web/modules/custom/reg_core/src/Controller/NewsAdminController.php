<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Form\NewsAdminFilterForm;
use Drupal\reg_core\News\NewsRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the dedicated Communications administration view for News.
 */
final class NewsAdminController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  private const PAGE_SIZE = 25;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly NewsRepositoryInterface $newsRepository,
    private readonly LanguageManagerInterface $languageManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
    private readonly PagerParametersInterface $pagerParameters,
    private readonly FormBuilderInterface $formBuilder,
    private readonly Connection $database,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get(NewsRepositoryInterface::class),
      $container->get('language_manager'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
      $container->get('pager.parameters'),
      $container->get('form_builder'),
      $container->get('database'),
      $container->get('string_translation'),
    );
  }

  /**
   * Displays a filtered News table including editorial metadata.
   */
  public function overview(Request $request): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'reg_news');
    $title = mb_substr(trim(strip_tags((string) $request->query->get('title', ''))), 0, 120);
    if ($title !== '') {
      $query->condition('title', $title, 'CONTAINS');
    }
    $category = max(0, (int) $request->query->get('category', 0));
    if ($category) {
      $query->condition('field_reg_news_category_term.target_id', $category);
    }
    $featured = (string) $request->query->get('featured', '');
    if (in_array($featured, ['0', '1'], TRUE)) {
      $query->condition('field_reg_featured', (int) $featured);
    }
    $langcode = preg_replace('/[^a-z0-9-]/i', '', (string) $request->query->get('langcode', ''));
    if ($langcode !== '') {
      $query->condition('langcode', $langcode);
    }
    $moderation_state = (string) $request->query->get('moderation_state', '');
    if (in_array($moderation_state, ['draft', 'needs_review', 'published', 'archived'], TRUE)) {
      $moderated_ids = $this->database->select('content_moderation_state_field_data', 'cms')
        ->fields('cms', ['content_entity_id'])
        ->condition('content_entity_type_id', 'node')
        ->condition('workflow', 'reg_news_editorial')
        ->condition('moderation_state', $moderation_state)
        ->distinct()
        ->execute()
        ->fetchCol();
      $query->condition('nid', $moderated_ids ?: [0], 'IN');
    }

    $total = (int) (clone $query)->count()->execute();
    $page = max(0, $this->pagerParameters->findPage(0));
    $ids = $query
      ->sort('field_reg_publication_date', 'DESC')
      ->sort('changed', 'DESC')
      ->range($page * self::PAGE_SIZE, self::PAGE_SIZE)
      ->execute();
    $this->pagerManager->createPager($total, self::PAGE_SIZE, 0);

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $latest_revision_id = $storage->getLatestRevisionId($node->id());
      $latest = $latest_revision_id ? $storage->loadRevision($latest_revision_id) : NULL;
      if ($latest instanceof NodeInterface) {
        $node = $latest;
      }
      $item = $this->newsRepository->item($node, 'reg_news_thumbnail');
      $state = $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
        ? (string) $node->get('moderation_state')->value
        : ($node->isPublished() ? 'published' : 'draft');
      $language = $this->languageManager->getLanguage($node->language()->getId());
      $rows[] = [
        'image' => ['data' => $item['image']['render'] ?? ['#markup' => '—'], 'class' => ['reg-news-admin-image']],
        'title' => ['data' => Link::fromTextAndUrl($node->label(), $node->toUrl())->toRenderable()],
        'category' => $item['category']['label'],
        'publication_date' => $item['date'] ?: $this->t('Not set'),
        'featured' => $item['featured'] ? $this->t('Yes') : $this->t('No'),
        'language' => $language?->getName() ?? $node->language()->getId(),
        'moderation' => ucwords(str_replace('_', ' ', $state)),
        'updated' => $this->dateFormatter->format($node->getChangedTime(), 'short'),
        'edit' => ['data' => Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('entity.node.edit_form', ['node' => $node->id()], ['query' => ['destination' => '/admin/content/news']]))->toRenderable()],
      ];
    }

    return [
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-news-admin-actions']],
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('ADD NEWS'),
          '#url' => Url::fromRoute('node.add', ['node_type' => 'reg_news']),
          '#attributes' => ['class' => ['button', 'button--primary', 'button--action']],
        ],
      ],
      'filters' => $this->formBuilder->getForm(NewsAdminFilterForm::class),
      'summary' => ['#markup' => '<p>' . $this->formatPlural($total, '1 news item', '@count news items') . '</p>'],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Featured Image'),
          $this->t('Title'),
          $this->t('Category'),
          $this->t('Publication Date'),
          $this->t('Homepage Featured'),
          $this->t('Language'),
          $this->t('Moderation Status'),
          $this->t('Updated'),
          $this->t('Edit'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No News content matches the selected filters.'),
        '#sticky' => TRUE,
      ],
      'pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['reg_core/newsroom_admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
