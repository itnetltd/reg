<?php

namespace Drupal\reg_core\News;

use Drupal\node\NodeInterface;

/**
 * Provides published, language-aware News & Insights content.
 */
interface NewsRepositoryInterface {

  /**
   * Returns the homepage lead story and two latest secondary stories.
   */
  public function homepage(): array;

  /**
   * Returns a filtered, paged public newsroom result.
   */
  public function archive(array $filters, int $page, int $limit): array;

  /**
   * Returns public archive filter options.
   */
  public function filterOptions(): array;

  /**
   * Normalizes one News node for a public card or administration row.
   */
  public function item(NodeInterface $node, string $responsiveStyle = 'reg_news_card', string $loading = 'lazy'): array;

  /**
   * Returns the complete public article model.
   */
  public function article(NodeInterface $node): array;

}
