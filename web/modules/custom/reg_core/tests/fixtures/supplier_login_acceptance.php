<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;

require dirname(__DIR__, 6) . '/vendor/autoload.php';

$email = 'mugunga.louis@gmail.com';
$password = (string) ($argv[1] ?? '');
$admin_login_url = (string) ($argv[2] ?? '');
$correct_email = (string) ($argv[3] ?? $email);
$base_url = 'http://localhost';
$failures = [];
$results = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
  if (!$condition) {
    $failures[] = $message;
  }
};

$login = static function (string $submitted_password, string $login_email) use ($base_url): array {
  $client = new Client([
    'cookies' => new CookieJar(),
    'http_errors' => FALSE,
    'allow_redirects' => ['track_redirects' => TRUE],
    'timeout' => 20,
  ]);
  $page = $client->get($base_url . '/supplier/login');
  $html = (string) $page->getBody();
  $dom = new DOMDocument();
  @$dom->loadHTML($html);
  $xpath = new DOMXPath($dom);
  $fields = [];
  foreach ($xpath->query('//form[contains(concat(" ", normalize-space(@class), " "), " reg-supplier-form ")]//input[@name]') as $input) {
    $name = $input->getAttribute('name');
    if ($name !== '') {
      $fields[$name] = $input->getAttribute('value');
    }
  }
  $fields['name'] = $login_email;
  $fields['pass'] = $submitted_password;
  $fields['op'] = 'LOG IN';
  $response = $client->post($base_url . '/supplier/login', ['form_params' => $fields]);
  $history = $response->getHeader('X-Guzzle-Redirect-History');
  return [
    'client' => $client,
    'html' => (string) $response->getBody(),
    'status' => $response->getStatusCode(),
    'final_url' => $history ? end($history) : $base_url . '/supplier/login',
    'submitted_fields' => array_keys($fields),
    'headers' => $response->getHeaders(),
  ];
};

$wrong = $login('deliberately-incorrect-password', $email);
$wrong_dom = new DOMDocument();
@$wrong_dom->loadHTML($wrong['html']);
$wrong_xpath = new DOMXPath($wrong_dom);
$wrong_title = trim((string) $wrong_xpath->evaluate('string(//title)'));
$wrong_notices = [];
foreach ($wrong_xpath->query('//*[contains(@class, "messages") or contains(@class, "form-item--error-message")]') as $notice) {
  $text = trim(preg_replace('/\s+/', ' ', $notice->textContent) ?? '');
  if ($text !== '') {
    $wrong_notices[] = $text;
  }
}
$wrong_error_elements = [];
foreach ($wrong_xpath->query('//*[@role="alert" and contains(normalize-space(string(.)), "Incorrect email address or password.")]') as $element) {
  $wrong_error_elements[] = [
    'tag' => $element->nodeName,
    'class' => $element->attributes?->getNamedItem('class')?->nodeValue ?? '',
    'role' => $element->attributes?->getNamedItem('role')?->nodeValue ?? '',
    'hidden' => $element->attributes?->getNamedItem('hidden') !== NULL,
  ];
}
$results['wrong_password'] = [
  'status' => $wrong['status'],
  'final_url' => $wrong['final_url'],
  'visible_error' => (bool) $wrong_error_elements,
  'admin_toolbar' => str_contains($wrong['html'], 'toolbar-bar'),
  'notices' => array_values(array_unique($wrong_notices)),
  'submitted_fields' => $wrong['submitted_fields'],
  'title' => $wrong_title,
  'flood_message' => str_contains($wrong['html'], 'failed login attempts'),
  'retained_email' => str_contains($wrong['html'], 'value="' . $email . '"'),
  'redirect_history' => $wrong['headers']['X-Guzzle-Redirect-History'] ?? [],
  'error_elements' => $wrong_error_elements,
];
$assert($results['wrong_password']['status'] === 200, 'Wrong-password response was not HTTP 200.');
$assert($results['wrong_password']['visible_error'], 'Wrong-password error was not visible.');
$assert((bool) array_filter($wrong_error_elements, static fn(array $element): bool => !$element['hidden']), 'Wrong-password text existed only in non-visible markup.');
$assert(!$results['wrong_password']['admin_toolbar'], 'Anonymous wrong-password response exposed the admin toolbar.');

if ($password !== '') {
  $correct = $login($password, $correct_email);
  $results['correct_password'] = [
    'status' => $correct['status'],
    'final_url' => $correct['final_url'],
    'dashboard' => str_contains($correct['html'], 'Supplier dashboard'),
    'pending' => str_contains($correct['html'], 'Pending Approval'),
    'company' => str_contains($correct['html'], 'IT NET Ltd'),
    'review_message' => str_contains($correct['html'], 'Your supplier registration is currently being reviewed by REG Procurement.'),
    'admin_toolbar' => str_contains($correct['html'], 'toolbar-bar'),
    'account' => $correct_email,
  ];
  $assert($results['correct_password']['status'] === 200, 'Correct-password response was not HTTP 200.');
  $assert(parse_url($results['correct_password']['final_url'], PHP_URL_PATH) === '/supplier/dashboard', 'Correct-password login did not redirect to /supplier/dashboard.');
  $assert($results['correct_password']['dashboard'], 'Supplier dashboard did not render after login.');
  $assert($results['correct_password']['pending'], 'Pending Approval was not visible on the supplier dashboard.');
  $assert($results['correct_password']['company'], 'IT NET Ltd was not visible on the supplier dashboard.');
  $assert($results['correct_password']['review_message'], 'The pending-review message was not visible.');
  $assert(!$results['correct_password']['admin_toolbar'], 'Supplier response unexpectedly exposed the admin toolbar.');
}

if ($admin_login_url !== '') {
  $admin = new Client([
    'cookies' => new CookieJar(),
    'http_errors' => FALSE,
    'allow_redirects' => ['track_redirects' => TRUE],
    'timeout' => 20,
  ]);
  $admin_login_url = preg_replace('#^https?://[^/]+#', $base_url, $admin_login_url);
  $admin->get($admin_login_url);
  $admin_page = $admin->get($base_url . '/supplier/login');
  $admin_html = (string) $admin_page->getBody();
  $results['authenticated_admin'] = [
    'status' => $admin_page->getStatusCode(),
    'already_signed_in' => str_contains($admin_html, 'You are already signed in. Sign out before signing in as a supplier.'),
    'login_form' => str_contains($admin_html, 'reg_supplier_login_form'),
    'sign_out' => str_contains($admin_html, 'SIGN OUT'),
  ];
  $assert($results['authenticated_admin']['status'] === 200, 'Authenticated-admin response was not HTTP 200.');
  $assert($results['authenticated_admin']['already_signed_in'], 'Authenticated admin did not receive the already-signed-in message.');
  $assert(!$results['authenticated_admin']['login_form'], 'Authenticated admin received a usable supplier login form.');
  $assert($results['authenticated_admin']['sign_out'], 'Authenticated admin did not receive the sign-out action.');
}

print json_encode(['results' => $results, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
