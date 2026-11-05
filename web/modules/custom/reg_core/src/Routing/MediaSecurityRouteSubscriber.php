<?php

namespace Drupal\reg_core\Routing;

use Drupal\Core\Routing\RouteSubscriberBase;
use Symfony\Component\Routing\RouteCollection;

/** Requires the existing browser client's CSRF header for every write API. */
class MediaSecurityRouteSubscriber extends RouteSubscriberBase {

  protected function alterRoutes(RouteCollection $collection): void {
    foreach ($collection as $name => $route) {
      if (str_starts_with($name, 'media_directories_browser.api.') && array_intersect($route->getMethods(), ['POST', 'PUT', 'PATCH', 'DELETE'])) {
        $route->setRequirement('_csrf_request_header_token', 'TRUE');
      }
    }
  }

}
