<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Searchable editorial overview for the REG FAQ knowledge base.
 */
final class FaqAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $regLanguageManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('language_manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the filtered FAQ editorial table.
   */
  public function overview(Request $request): array {
    $filters = [
      'q' => $this->queryValue($request, 'q', 120),
      'category' => $this->queryValue($request, 'category', 64),
      'review_status' => $this->queryValue($request, 'review_status', 32),
      'langcode' => $this->queryValue($request, 'langcode', 12),
      'published' => $this->queryValue($request, 'published', 16),
    ];
    $categories = $this->allowedValues('field_reg_faq_category');
    $reviewStatuses = $this->allowedValues('field_reg_review_status');
    $languages = [];
    foreach ($this->regLanguageManager->getLanguages() as $langcode => $language) {
      $languages[$langcode] = $language->getName();
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_faq')
      ->sort('changed', 'DESC')
      ->range(0, 1000)
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $category = (string) $node->get('field_reg_faq_category')->value;
      $reviewStatus = (string) $node->get('field_reg_review_status')->value;
      if ($filters['category'] !== '' && $category !== $filters['category']) {
        continue;
      }
      if ($filters['review_status'] !== '' && $reviewStatus !== $filters['review_status']) {
        continue;
      }
      foreach ($node->getTranslationLanguages() as $langcode => $language) {
        if ($filters['langcode'] !== '' && $langcode !== $filters['langcode']) {
          continue;
        }
        $translation = $node->getTranslation($langcode);
        $published = $translation->isPublished();
        if (($filters['published'] === 'published' && !$published) || ($filters['published'] === 'draft' && $published)) {
          continue;
        }
        if ($filters['q'] !== '' && !str_contains(mb_strtolower($translation->label()), mb_strtolower($filters['q']))) {
          continue;
        }
        $editUrl = $translation->toUrl('edit-form', [
          'query' => ['destination' => '/admin/content/faqs'],
        ])->toString();
        $rows[] = [
          'question' => $translation->label(),
          'question_url' => $translation->toUrl()->toString(),
          'category' => $categories[$category] ?? ucfirst(str_replace('_', ' ', $category)),
          'language' => $languages[$langcode] ?? strtoupper($langcode),
          'review_status' => $reviewStatuses[$reviewStatus] ?? ucfirst(str_replace('_', ' ', $reviewStatus)),
          'review_status_id' => $reviewStatus,
          'changed' => $this->dateFormatter->format($translation->getChangedTime(), 'short'),
          'published' => $published,
          'edit_url' => $editUrl,
        ];
      }
    }

    return [
      '#theme' => 'reg_faq_admin',
      '#rows' => $rows,
      '#filters' => $filters,
      '#categories' => $categories,
      '#review_statuses' => $reviewStatuses,
      '#languages' => $languages,
      '#add_url' => Url::fromRoute('node.add', ['node_type' => 'reg_faq'])->toString(),
      '#clear_url' => Url::fromRoute('reg_core.faqs_admin')->toString(),
      '#attached' => ['library' => ['reg_core/faq_admin']],
      '#cache' => [
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['node_list:reg_faq'],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Returns allowed values for a shared FAQ list field.
   */
  private function allowedValues(string $fieldName): array {
    $storage = $this->regEntityTypeManager->getStorage('field_storage_config')->load('node.' . $fieldName);
    $values = $storage?->getSetting('allowed_values');
    return is_array($values) ? $values : [];
  }

  /**
   * Returns one bounded plain query parameter.
   */
  private function queryValue(Request $request, string $key, int $length): string {
    $value = preg_replace('/\s+/u', ' ', trim(strip_tags((string) $request->query->get($key, '')))) ?? '';
    return mb_substr($value, 0, $length);
  }

}
