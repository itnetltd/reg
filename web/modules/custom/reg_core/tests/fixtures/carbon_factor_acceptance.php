<?php

use Drupal\Core\Form\FormState;
use Drupal\reg_core\Form\CarbonCalculatorForm;
use Drupal\reg_core\Form\RegSettingsForm;

$factory = \Drupal::configFactory();
$original = $factory->get('reg_core.settings')->getRawData();
$check = static function (bool $condition, string $message): void {
  if (!$condition) {
    throw new RuntimeException($message);
  }
};
$save = static function (string $status, string $factor = '0.35398') use ($factory): void {
  $factory->getEditable('reg_core.settings')->set('carbon.status', $status)
    ->set('carbon.emission_factor', $factor)->save();
};
$http = new \GuzzleHttp\Client(['base_uri' => 'http://localhost', 'cookies' => TRUE, 'http_errors' => FALSE]);
$page = static fn(string $path): string => (string) $http->get($path)->getBody();
$card = static function () use ($check): array {
  foreach (\Drupal::service('reg_core.energy_tool_repository')->homepageTools() as $tool) {
    if ($tool['key'] === 'carbon_footprint') {
      return $tool;
    }
  }
  throw new RuntimeException('Published homepage carbon card missing.');
};
try {
  $save('published');
  $settings = RegSettingsForm::create(\Drupal::getContainer());
  $settingsState = new FormState();
  $settingsForm = $settings->buildForm([], $settingsState);
  $check($settingsForm['carbon']['emission_factor']['#step'] === '0.00001', 'Factor step incorrect.');
  $values = [];
  $collect = static function (array $elements) use (&$collect, &$values): void {
    foreach ($elements as $key => $element) {
      if (!is_array($element) || str_starts_with((string) $key, '#')) {
        continue;
      }
      if (array_key_exists('#default_value', $element)) {
        $values[$key] = $element['#default_value'];
      }
      $collect($element);
    }
  };
  $collect($settingsForm);
  $values['emission_factor'] = '0.35000';
  $settingsState->setValues($values);
  $settings->submitForm($settingsForm, $settingsState);
  $check($factory->get('reg_core.settings')->get('carbon.emission_factor') === '0.35000', 'Settings save lost trailing zeroes.');
  $save('published');
  $calculator = CarbonCalculatorForm::create(\Drupal::getContainer());
  $results = [];
  foreach ([0 => 0.0, 1 => 0.35398, 100 => 35.398, 500 => 176.99, 5000 => 1769.9] as $kwh => $expected) {
    $state = (new FormState())->setValue('consumption', $kwh);
    $form = $calculator->buildForm([], $state);
    $calculator->validateForm($form, $state);
    $check(!$state->hasAnyErrors(), 'Valid consumption rejected.');
    $calculator->submitForm($form, $state);
    $result = $state->get('result');
    $check(abs($result['emissions'] - $expected) < 0.0000001, 'Incorrect kg result.');
    $check(abs($result['emissions_tonnes'] - $expected / 1000) < 0.0000001, 'Incorrect tonnes result.');
    $rebuilt = $calculator->buildForm([], $state);
    $check(str_contains((string) $rebuilt['result']['#markup'], '0.35398'), 'Factor display precision lost.');
    $check($kwh < 5000 || str_contains((string) $rebuilt['result']['#markup'], 'tonnes CO2e'), 'Useful tonnes not displayed.');
    $results[$kwh] = $result['emissions'];
  }
  foreach (['', 'abc', -1, 1000001, 'INF', 'NaN', '1e309', [], '0.001'] as $invalid) {
    $state = (new FormState())->setValue('consumption', $invalid)->set('result', ['emissions' => 999]);
    $form = [];
    $calculator->validateForm($form, $state);
    $check($state->hasAnyErrors(), 'Invalid input accepted.');
    $calculator->submitForm($form, $state);
    $check($state->get('result') === NULL, 'Invalid input retained result.');
    $state->clearErrors();
  }
  $enabledCard = $card();
  $check($enabledCard['status_label'] === 'Estimate available', 'Enabled repository label incorrect.');
  $check(parse_url($enabledCard['cta_url'], PHP_URL_PATH) === '/tools/carbon-footprint', 'Incorrect card CTA.');
  $check(str_contains($page('/'), 'Estimate available'), 'Rendered homepage missing available status.');
  foreach (['pending', 'withdrawn', 'disabled'] as $status) {
    $save($status);
    $check($card()['status_label'] === 'Awaiting factor', 'Repository cache did not invalidate on disable.');
    $check(str_contains($page('/'), 'Awaiting factor'), 'Homepage render cache did not invalidate on disable.');
    $state = (new FormState())->setValue('consumption', 100);
    $form = $calculator->buildForm([], $state);
    $check($form['actions']['submit']['#disabled'], 'Disabled factor enabled submit.');
    $calculator->submitForm($form, $state);
    $check($state->get('result') === NULL, 'Disabled factor calculated.');
  }
  $save('published', '0.00000');
  $check($card()['status_label'] === 'Awaiting factor', 'Missing factor available.');
  $save('published', '0.40000');
  $check($card()['status_label'] === 'Estimate available', 'Repository cache did not invalidate on enable.');
  $check(str_contains($page('/'), 'Estimate available'), 'Homepage render cache did not invalidate on enable.');
  $state = (new FormState())->setValue('consumption', 100);
  $form = [];
  $calculator->submitForm($form, $state);
  $check($state->get('result')['emissions'] === 40.0, 'Changed factor ignored.');
  $save('approved');
  $check($card()['status_label'] === 'Estimate available', 'Explicit approval compatibility lost.');
  $save('published');
  // Submit through real Drupal Form API, including hidden form identifiers.
  foreach ([0, 1, 100, 500, 'abc', -1, 1000001] as $input) {
    $html = $page('/tools/carbon-footprint');
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $fields = ['consumption' => $input, 'op' => 'Estimate carbon footprint'];
    foreach ($xpath->query('//form[@id="reg-carbon-calculator-form"]//input[@type="hidden"]') as $hidden) {
      $fields[$hidden->getAttribute('name')] = $hidden->getAttribute('value');
    }
    $check(isset($fields['form_id']), 'Calculator form not rendered.');
    $response = $http->post('/tools/carbon-footprint', ['form_params' => $fields]);
    $body = (string) $response->getBody();
    $check($response->getStatusCode() === 200, 'Form request failed.');
    $valid = is_numeric($input) && $input >= 0 && $input <= 1000000;
    $check(str_contains($body, 'Estimated emissions:') === $valid, 'HTTP Form API accepted invalid or rejected valid input.');
    $check(str_contains($body, 'This is not a verified carbon inventory.'), 'Disclaimer missing.');
  }
  $check($card()['title'] === $enabledCard['title'] && $card()['description'] === $enabledCard['description'] && $card()['cta_label'] === $enabledCard['cta_label'], 'Editorial card fields changed.');
  print json_encode(['kg_results' => $results, 'input_validation' => 'PASS', 'factor_states_and_change' => 'PASS', 'repository_and_homepage_cache_invalidation' => 'PASS', 'http_form_api' => 'PASS', 'cta' => $enabledCard['cta_url']], JSON_PRETTY_PRINT) . "\n";
}
finally {
  $factory->getEditable('reg_core.settings')->setData($original)->save();
}
