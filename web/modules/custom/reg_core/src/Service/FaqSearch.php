<?php

namespace Drupal\reg_core\Service;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Searches approved CMS FAQs without generating or inferring answers.
 */
final class FaqSearch implements FaqSearchInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function search(string $query = '', string $category = '', int $limit = 100): array {
    $query = self::normalizeText($query, 120);
    $category = mb_strtolower(self::normalizeText($category, 64));
    if ($query !== '' && mb_strlen($query) < 2) {
      return [];
    }

    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_faq')
      ->condition('status', NodeInterface::PUBLISHED)
      ->sort('changed', 'DESC')
      ->range(0, 500)
      ->execute();

    $current_langcode = $this->languageManager->getCurrentLanguage()->getId();
    $categories = $this->categories();
    $results = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $translation = $this->approvedTranslation($node, $current_langcode);
      if ($translation === NULL) {
        continue;
      }

      $category_id = mb_strtolower($this->fieldText($translation, 'field_reg_faq_category'));
      if ($category !== '' && $category_id !== $category) {
        continue;
      }

      $record = [
        'id' => (int) $translation->id(),
        'question' => trim($translation->label()),
        'answer' => $this->fieldAnswer($translation),
        'keywords' => $this->fieldText($translation, 'field_reg_faq_keywords'),
        'category' => $category_id,
        'category_label' => $categories[$category_id] ?? ucfirst(str_replace('_', ' ', $category_id)),
        'priority' => $this->priority($translation),
        'related_links' => $this->relatedLinks($translation),
        'escalation' => $this->fieldText($translation, 'field_reg_faq_escalation'),
        'langcode' => $translation->language()->getId(),
        'url' => $this->faqUrl($translation),
      ];
      // Retain the original single-link property for API consumers deployed
      // before FAQ actions became multi-value.
      $record['related_link'] = $record['related_links'][0] ?? NULL;
      $record['score'] = self::calculateScore($record, $query);
      if ($query !== '' && $record['score'] <= 0) {
        continue;
      }
      $results[] = $record;
    }

    usort($results, static function (array $left, array $right): int {
      return ($right['score'] <=> $left['score'])
        ?: ($left['priority'] <=> $right['priority'])
        ?: strcasecmp($left['question'], $right['question']);
    });

    $limit = max(1, min(200, $limit));
    return array_map(static function (array $record): array {
      unset($record['score'], $record['keywords']);
      return $record;
    }, array_slice($results, 0, $limit));
  }

  /**
   * {@inheritdoc}
   */
  public function categories(): array {
    $storage = $this->regEntityTypeManager->getStorage('field_storage_config')->load('node.field_reg_faq_category');
    $values = $storage?->getSetting('allowed_values');
    return is_array($values) ? $values : [];
  }

  /**
   * Calculates deterministic relevance with title and keywords weighted first.
   *
   * @param array<string, mixed> $record
   *   A normalized FAQ search record.
   */
  public static function calculateScore(array $record, string $query): int {
    $needle = mb_strtolower(self::normalizeText($query, 120));
    if ($needle === '') {
      return 1;
    }

    $question = mb_strtolower((string) ($record['question'] ?? ''));
    $keywords = mb_strtolower((string) ($record['keywords'] ?? ''));
    $category = mb_strtolower(implode(' ', [
      (string) ($record['category'] ?? ''),
      (string) ($record['category_label'] ?? ''),
    ]));
    $answer = mb_strtolower((string) ($record['answer'] ?? ''));
    $score = 0;
    if ($question === $needle) {
      $score += 1200;
    }
    elseif (str_contains($question, $needle)) {
      $score += 600;
    }
    if (str_contains($keywords, $needle)) {
      $score += 500;
    }
    if (str_contains($category, $needle)) {
      $score += 300;
    }
    if (str_contains($answer, $needle)) {
      $score += 120;
    }

    $terms = preg_split('/\s+/u', $needle, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    foreach ($terms as $term) {
      if (mb_strlen($term) < 2) {
        continue;
      }
      $score += str_contains($question, $term) ? 60 : 0;
      $score += str_contains($keywords, $term) ? 45 : 0;
      $score += str_contains($category, $term) ? 25 : 0;
      $score += str_contains($answer, $term) ? 10 : 0;
    }
    return $score;
  }

  /**
   * Selects a published current-language translation or published original.
   */
  private function approvedTranslation(NodeInterface $node, string $langcode): ?NodeInterface {
    if ($node->hasTranslation($langcode)) {
      $translation = $node->getTranslation($langcode);
      return $translation->isPublished() ? $translation : NULL;
    }
    return $node->isPublished() ? $node : NULL;
  }

  /**
   * Reads one customer-facing field as safe plain text.
   */
  private function fieldText(NodeInterface $node, string $field_name): string {
    if (!$node->hasField($field_name) || $node->get($field_name)->isEmpty()) {
      return '';
    }
    $value = $node->get($field_name)->first()?->get('value')->getValue();
    return self::normalizeText(Html::decodeEntities(strip_tags((string) $value)), 4000);
  }

  /**
   * Reads a plain-text answer while retaining editor-authored line breaks.
   */
  private function fieldAnswer(NodeInterface $node): string {
    if (!$node->hasField('field_reg_answer') || $node->get('field_reg_answer')->isEmpty()) {
      return '';
    }
    $value = Html::decodeEntities(strip_tags((string) $node->get('field_reg_answer')->value));
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
    $value = preg_replace('/\R{3,}/u', "\n\n", trim($value)) ?? '';
    return mb_substr($value, 0, 6000);
  }

  /**
   * Reads a non-negative priority; lower values display first on ties.
   */
  private function priority(NodeInterface $node): int {
    $value = $this->fieldText($node, 'field_reg_faq_priority');
    return is_numeric($value) ? max(0, (int) $value) : 100;
  }

  /**
   * Returns an optional approved related-service link.
   *
   * @return array{url: string, label: string}|null
   *   Link view model.
   */
  private function relatedLinks(NodeInterface $node): array {
    if (!$node->hasField('field_reg_related_url') || $node->get('field_reg_related_url')->isEmpty()) {
      return [];
    }
    $links = [];
    foreach ($node->get('field_reg_related_url') as $item) {
      $uri = trim((string) $item->get('uri')->getValue());
      if ($uri === '') {
        continue;
      }
      try {
        $url = str_starts_with($uri, '/') ? Url::fromUserInput($uri) : Url::fromUri($uri);
        $links[] = [
          'url' => $url->toString(),
          'label' => trim((string) $item->get('title')->getValue()) ?: 'Open related service',
          'external' => $url->isExternal(),
        ];
      }
      catch (\InvalidArgumentException) {
        continue;
      }
    }
    return $links;
  }

  /**
   * Returns the language-aware FAQ page URL with an item fragment.
   */
  private function faqUrl(NodeInterface $node): string {
    return Url::fromRoute('reg_core.faq', [], [
      'fragment' => 'faq-' . $node->id(),
      'language' => $node->language(),
    ])->toString();
  }

  /**
   * Normalizes whitespace and bounds public search/content text.
   */
  private static function normalizeText(string $value, int $length): string {
    $value = preg_replace('/\s+/u', ' ', trim(strip_tags($value))) ?? '';
    return mb_substr($value, 0, $length);
  }

}
