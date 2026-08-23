<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Preserves indexed legacy About Us URLs with permanent redirects.
 */
final class AboutLegacyRedirectController extends ControllerBase {

  /**
   * Redirects one controlled legacy path to its canonical Drupal route.
   */
  public function legacy(string $destination_route): RedirectResponse {
    $allowed = [
      'reg_core.about',
      'reg_core.about_history',
      'reg_core.about_vision',
      'reg_core.about_edcl',
      'reg_core.about_eucl',
      'reg_core.about_board',
      'reg_core.about_executive',
      'reg_core.branches',
      'reg_core.about_partners',
      'reg_core.about_people',
    ];
    if (!in_array($destination_route, $allowed, TRUE)) {
      $destination_route = 'reg_core.about';
    }
    return new RedirectResponse(Url::fromRoute($destination_route)->toString(), 301);
  }

}
