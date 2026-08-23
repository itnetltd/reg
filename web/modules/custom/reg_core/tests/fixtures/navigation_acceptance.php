<?php

use Drupal\Core\Menu\MenuLinkTreeElement;
use Drupal\Core\Menu\MenuTreeParameters;

$menu_tree = \Drupal::menuTree();
$paths = [];
$titles = [];

$collect = static function (array $tree) use (&$collect, &$paths, &$titles): void {
  foreach ($tree as $element) {
    if (!$element instanceof MenuLinkTreeElement || !$element->link->isEnabled()) {
      continue;
    }
    $titles[] = (string) $element->link->getTitle();
    $url = $element->link->getUrlObject();
    if ($url->isRouted() && !str_starts_with($url->getRouteName(), '<')) {
      $paths[] = $url->toString();
    }
    if ($element->subtree) {
      $collect($element->subtree);
    }
  }
};

foreach (['main', 'reg-utility-menu'] as $menu_name) {
  $parameters = (new MenuTreeParameters())->setMinDepth(1)->setMaxDepth(4);
  $collect($menu_tree->load($menu_name, $parameters));
}

$paths = array_values(array_unique(array_merge($paths, [
  '/search/node',
  '/rw/outages',
])));
sort($paths);
$client = \Drupal::httpClient();
$responses = [];
$failures = [];
foreach ($paths as $path) {
  try {
    $status = $client->get('http://localhost' . $path, [
      'allow_redirects' => TRUE,
      'http_errors' => FALSE,
      'timeout' => 15,
    ])->getStatusCode();
  }
  catch (\Throwable $exception) {
    $status = 0;
    $failures[$path] = $exception->getMessage();
  }
  $responses[$path] = $status;
  if ($status < 200 || $status >= 400) {
    $failures[$path] ??= 'HTTP ' . $status;
  }
}

foreach (['Home', 'Log in'] as $removed_title) {
  if (in_array($removed_title, $titles, TRUE)) {
    $failures['menu:' . $removed_title] = 'Unexpected public navigation item.';
  }
}

$rw_html = html_entity_decode((string) $client->get('http://localhost/rw/outages')->getBody(), ENT_QUOTES | ENT_HTML5);
foreach (["Serivisi z'abakiliya", "Ibura ry'amashanyarazi"] as $translated_title) {
  if (!str_contains($rw_html, $translated_title)) {
    $failures['translation:' . $translated_title] = 'Translated menu title was not rendered.';
  }
}

echo json_encode([
  'checked_paths' => count($responses),
  'responses' => $responses,
  'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if ($failures) {
  exit(1);
}
