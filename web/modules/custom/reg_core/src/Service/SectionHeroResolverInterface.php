<?php

namespace Drupal\reg_core\Service;

/**
 * Resolves the published CMS hero for the current public section.
 */
interface SectionHeroResolverInterface {

  /**
   * Resolves a section hero and applies optional page-specific text overrides.
   *
   * @param array $overrides
   *   Trusted presentation values supplied by a controller or template.
   * @param string|null $path
   *   An optional public path. The current request path is used by default.
   *
   * @return array
   *   A normalized, Twig-safe hero view model, or an empty array when the
   *   current route is not part of a configured section.
   */
  public function resolve(array $overrides = [], ?string $path = NULL): array;

}
