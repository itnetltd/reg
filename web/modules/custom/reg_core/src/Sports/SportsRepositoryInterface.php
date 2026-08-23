<?php

namespace Drupal\reg_core\Sports;

/**
 * Provides published, normalized REG Sports Portal data.
 */
interface SportsRepositoryInterface {

  /**
   * Returns every dynamic section needed by the sports landing page.
   */
  public function landing(): array;

  /**
   * Returns a team and its related published content.
   */
  public function team(string $team_key): ?array;

  /**
   * Searches a supported sports bundle.
   */
  public function listing(string $bundle, array $filters = []): array;

  /**
   * Loads one published sports record.
   */
  public function detail(string $bundle, int $id): ?array;

  /**
   * Searches teams, players, news, and fixtures/results together.
   */
  public function search(string $keywords): array;

  /**
   * Returns controlled filter options for a sports vocabulary.
   */
  public function taxonomyOptions(string $vocabulary): array;

  /**
   * Returns published active team options.
   */
  public function teamOptions(): array;

  /**
   * Returns compact homepage highlights.
   */
  public function homepageHighlights(): array;

}
