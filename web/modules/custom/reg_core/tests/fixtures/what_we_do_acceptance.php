<?php

$paths = [
  '/what-we-do', '/what-we-do/generation', '/what-we-do/generation/hydropower',
  '/what-we-do/generation/solar', '/what-we-do/generation/methane-gas',
  '/what-we-do/generation/geothermal', '/what-we-do/transmission',
  '/what-we-do/distribution', '/what-we-do/access', '/what-we-do/access/on-grid',
  '/what-we-do/access/off-grid', '/what-we-do/biomass-clean-cooking',
  '/what-we-do/petroleum', '/what-we-do/off-grid', '/what-we-do/off-grid/mini-grids',
  '/what-we-do/off-grid/solar-home-systems', '/projects',
  '/what-we-do/programs/rbf-window-5', '/what-we-do/programs/clean-cooking',
  '/what-we-do/programs/productive-use-of-energy',
  '/what-we-do/investment/opportunities', '/what-we-do/investment/incentives',
  '/what-we-do/investment/ipp',
];

$client = \Drupal::httpClient();
$failures = [];
$responses = [];
foreach ($paths as $path) {
  try {
    $response = $client->get('http://localhost' . $path, ['http_errors' => FALSE, 'timeout' => 20]);
    $html = (string) $response->getBody();
    $status = $response->getStatusCode();
    $responses[$path] = ['status' => $status, 'bytes' => strlen($html)];
    if ($status !== 200) {
      $failures[$path] = 'HTTP ' . $status;
    }
    elseif (strlen($html) < 6000 || !str_contains($html, 'reg-wwd__body')) {
      $failures[$path] = 'The structured What We Do content did not render.';
    }
  }
  catch (\Throwable $exception) {
    $failures[$path] = $exception->getMessage();
  }
}

$storage = \Drupal::entityTypeManager()->getStorage('node');
$counts = [];
foreach (['reg_what_we_do_page', 'reg_project', 'reg_power_plant', 'reg_fact', 'reg_access_statistic'] as $bundle) {
  $counts[$bundle] = (int) $storage->getQuery()->accessCheck(FALSE)->condition('type', $bundle)->count()->execute();
}

$page_ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_what_we_do_page')->execute();
$empty = [];
foreach ($storage->loadMultiple($page_ids) as $node) {
  $body = trim(strip_tags((string) $node->get('body')->value));
  $summary = trim((string) $node->get('field_reg_summary')->value);
  if ($body === '' || $summary === '' || preg_match('/lorem ipsum|placeholder|coming soon|sample content/i', $body . ' ' . $summary)) {
    $empty[] = $node->label();
  }
}
if ($empty) {
  $failures['empty_pages'] = implode(', ', $empty);
}
if ($counts['reg_access_statistic'] !== 90) {
  $failures['access_count'] = 'Expected 90 total/on-grid/off-grid district records.';
}

echo json_encode(['responses' => $responses, 'counts' => $counts, 'empty_or_placeholder_pages' => $empty, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
if ($failures) {
  exit(1);
}
