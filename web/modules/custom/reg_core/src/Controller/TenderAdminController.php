<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
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
use Drupal\reg_core\Form\TenderAdminFilterForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the dedicated Procurement administration page for tenders.
 */
final class TenderAdminController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  private const PAGE_SIZE = 25;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
   * Displays a filterable tender administration table.
   */
  public function overview(Request $request): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(TRUE)->condition('type', 'reg_tender');
    $title = mb_substr(trim(strip_tags((string) $request->query->get('title', ''))), 0, 120);
    if ($title !== '') {
      $group = $query->orConditionGroup()->condition('title', $title, 'CONTAINS')->condition('field_reg_reference', $title, 'CONTAINS');
      $query->condition($group);
    }
    foreach (['entity' => 'field_reg_entity', 'status' => 'field_reg_tender_status', 'featured' => 'field_reg_featured', 'langcode' => 'langcode'] as $parameter => $field) {
      $value = (string) $request->query->get($parameter, '');
      if ($value !== '') {
        $query->condition($field, $value);
      }
    }
    $category = max(0, (int) $request->query->get('category', 0));
    if ($category) {
      $query->condition('field_reg_tender_category.target_id', $category);
    }
    $moderation_state = (string) $request->query->get('moderation_state', '');
    if (in_array($moderation_state, ['draft', 'needs_review', 'published', 'archived'], TRUE)) {
      $moderated_ids = $this->database->select('content_moderation_state_field_data', 'cms')
        ->fields('cms', ['content_entity_id'])
        ->condition('content_entity_type_id', 'node')
        ->condition('workflow', 'reg_public_information_editorial')
        ->condition('moderation_state', $moderation_state)
        ->distinct()
        ->execute()
        ->fetchCol();
      $query->condition('nid', $moderated_ids ?: [0], 'IN');
    }

    $total = (int) (clone $query)->count()->execute();
    $page = max(0, $this->pagerParameters->findPage(0));
    $ids = $query->sort('changed', 'DESC')->sort('nid', 'DESC')->range($page * self::PAGE_SIZE, self::PAGE_SIZE)->execute();
    $this->pagerManager->createPager($total, self::PAGE_SIZE, 0);

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $category_label = $node->get('field_reg_tender_category')->entity?->label() ?? '';
      $status = (string) ($node->get('field_reg_tender_status')->value ?? '');
      $status_labels = ['active' => 'Current / Open', 'closed' => 'Closed', 'awarded' => 'Awarded', 'cancelled' => 'Cancelled', 'archived' => 'Archived'];
      $state = (string) ($node->get('moderation_state')->value ?? ($node->isPublished() ? 'published' : 'draft'));
      $date_value = (string) ($node->get('field_reg_closing_date')->value ?? '');
      $deadline = $date_value !== '' ? $this->dateFormatter->format(strtotime($date_value . ' UTC'), 'short') : '—';
      $language = $this->languageManager->getLanguage($node->language()->getId());
      $rows[] = [
        ['data' => Link::fromTextAndUrl($node->label(), Url::fromRoute('reg_core.tender_detail', ['tender' => $node->id()]))->toRenderable()],
        (string) ($node->get('field_reg_reference')->value ?? ''),
        strtoupper((string) ($node->get('field_reg_entity')->value ?? '')),
        $category_label,
        $status_labels[$status] ?? $status,
        $deadline,
        (bool) ($node->get('field_reg_featured')->value ?? FALSE) ? $this->t('Yes') : $this->t('No'),
        $language?->getName() ?? $node->language()->getId(),
        ucwords(str_replace('_', ' ', $state)),
        $this->dateFormatter->format($node->getChangedTime(), 'short'),
        ['data' => Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('entity.node.edit_form', ['node' => $node->id()], ['query' => ['destination' => '/admin/content/tenders']]))->toRenderable()],
      ];
    }

    return [
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['reg-tender-admin-actions']],
        'add' => ['#type' => 'link', '#title' => $this->t('ADD TENDER'), '#url' => Url::fromRoute('node.add', ['node_type' => 'reg_tender']), '#attributes' => ['class' => ['button', 'button--primary', 'button--action']]],
      ],
      'filters' => $this->formBuilder->getForm(TenderAdminFilterForm::class),
      'summary' => ['#markup' => '<p>' . $this->formatPlural($total, '1 tender', '@count tenders') . '</p>'],
      'table' => [
        '#type' => 'table',
        '#header' => [$this->t('Title'), $this->t('Reference'), $this->t('Entity'), $this->t('Category'), $this->t('Status'), $this->t('Closing deadline'), $this->t('Homepage Featured'), $this->t('Language'), $this->t('Moderation Status'), $this->t('Updated'), $this->t('Edit')],
        '#rows' => $rows,
        '#empty' => $this->t('No tenders match the selected filters.'),
        '#sticky' => TRUE,
      ],
      'pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['reg_core/tenders_admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
