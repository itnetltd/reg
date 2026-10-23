<?php

/**
 * @file
 * Local acceptance: native menu form save, reload, target reset and rendering.
 * Run with Drush php:script. Set REG_MENU_TEST_CLEANUP=1 to remove fixtures.
 */

use Drupal\Core\Form\FormState;
use Drupal\Core\Menu\MenuTreeParameters;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\menu_link_content\Entity\MenuLinkContent;

$state = \Drupal::state();
$key = 'reg_core.menu_target_acceptance';
$storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
if (getenv('REG_MENU_TEST_CLEANUP')) {
  foreach (array_filter($state->get($key, [])) as $id) {
    $storage->load($id)?->delete();
  }
  $state->delete($key);
  echo "Menu test fixtures removed.\n";
  return;
}
if ($state->get($key)) {
  throw new RuntimeException('Clean up existing menu target fixtures first.');
}
$assert = static function (bool $ok, string $message): void {
  if (!$ok) {
    throw new RuntimeException($message);
  }
};
$ids = [];
$switcher = \Drupal::service('account_switcher');
$switcher->switchTo(\Drupal\user\Entity\User::load(1));
$save = static function ($entity, bool $checked) use ($assert) {
  $form_object = \Drupal::entityTypeManager()->getFormObject('menu_link_content', 'default');
  $form_object->setEntity($entity);
  $form_state = (new FormState())->setValues([
    'title' => [['value' => $entity->label()]],
    'link' => [['uri' => str_replace('internal:', '', $entity->get('link')->uri)]],
    'menu_parent' => $entity->getMenuName() . ':' . $entity->getParentId(),
    'enabled' => ['value' => 1],
    'expanded' => ['value' => 1],
    'weight' => [['value' => $entity->getWeight()]],
    'reg_open_in_new_tab' => $checked ? 1 : NULL,
    'op' => 'Save',
  ]);
  \Drupal::formBuilder()->submitForm($form_object, $form_state);
  $assert(!$form_state->hasAnyErrors(), 'Form errors: ' . json_encode($form_state->getErrors()));
  return $form_state->getFormObject()->getEntity();
};
try {
  foreach (['main', 'reg-utility-menu', 'footer'] as $menu) {
    foreach (['internal' => 'internal:/contact', 'external' => 'https://example.com/reg-menu-test'] as $kind => $uri) {
      $entity = MenuLinkContent::create([
        'title' => 'REG menu test ' . $menu . ' ' . $kind,
        'menu_name' => $menu,
        'link' => ['uri' => $uri, 'options' => ['reg_menu_test' => 'preserved', 'attributes' => ['class' => ['reg-menu-target-test'], 'rel' => 'nofollow noreferrer']]],
        'enabled' => TRUE,
        'expanded' => TRUE,
        'weight' => 90,
      ]);
      $form = \Drupal::service('entity.form_builder')->getForm($entity);
      $assert(isset($form['reg_open_in_new_tab']) && !$form['reg_open_in_new_tab']['#default_value'], 'Checkbox must default unchecked.');
      $entity = $save($entity, TRUE);
      $ids[] = $entity->id();
      $state->set($key, $ids);
      $storage->resetCache([$entity->id()]);
      $entity = $storage->load($entity->id());
      $options = $entity->get('link')->options;
      $assert(($options['attributes']['target'] ?? '') === '_blank', 'Checked target was not saved.');
      $assert(str_contains($options['attributes']['rel'], 'noopener') && str_contains($options['attributes']['rel'], 'nofollow'), 'Rel attributes not preserved.');
      $assert(($options['reg_menu_test'] ?? '') === 'preserved', 'Other link options were lost.');
      $form = \Drupal::service('entity.form_builder')->getForm($entity);
      $assert((bool) $form['reg_open_in_new_tab']['#default_value'], 'Saved checkbox did not reload checked.');
      $assert(!$entity->access('update', new AnonymousUserSession()), 'Anonymous user must not edit menu links.');
      $entity = $save($entity, FALSE);
      $assert(!isset($entity->get('link')->options['attributes']['target']), 'Unchecked target was not removed.');
      // Leave both states available for browser checks: internal checked, external unchecked.
      if ($kind === 'internal') {
        $entity = $save($entity, TRUE);
      }
      echo "$menu $kind: checkbox default, save, reload, reset, permissions and URL options passed.\n";
    }
  }
  // Exercise nested navigation without changing any existing link.
  $parent = $storage->load($ids[0]);
  $child = $storage->load($ids[1]);
  $child->set('parent', $parent->getPluginId())->save();
  $child = $save($child, TRUE);
  $assert($child->getParentId() === $parent->getPluginId(), 'Parent was lost during save.');
  $child->addTranslation('rw', ['title' => 'REG menu test Kinyarwanda'])->save();
  $assert($child->getTranslation('rw')->label() === 'REG menu test Kinyarwanda', 'Translation lost.');

  \Drupal::theme()->setActiveTheme(\Drupal::service('theme.initialization')->initTheme('reg_theme'));
  $tree_service = \Drupal::menuTree();
  foreach (['main', 'reg-utility-menu', 'footer'] as $menu) {
    $tree = $tree_service->load($menu, (new MenuTreeParameters())->setMinDepth(1)->setMaxDepth(9));
    $build = $tree_service->build($tree);
    $html = (string) \Drupal::service('renderer')->renderInIsolation($build);
    $assert(str_contains($html, 'reg-menu-target-test'), 'Fixture missing from menu render.');
    $assert(str_contains($html, 'target="_blank"') && str_contains($html, 'noopener'), 'Target or rel missing from rendered menu.');
    $assert(str_contains($html, 'opens in a new tab'), 'Accessible new-tab indication missing.');
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($document);
    foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " reg-menu-target-test ")]') as $anchor) {
      $expected = str_contains($anchor->textContent, 'internal') || $menu === 'main';
      $assert($anchor->getAttribute('target') === ($expected ? '_blank' : ''), 'Per-link target mismatch.');
      if ($expected) {
        $assert(str_contains($anchor->getAttribute('rel'), 'noopener'), 'Per-link noopener missing.');
      }
    }
    file_put_contents('/var/www/html/tmp/menu-target-' . $menu . '.html', $html);
    echo "$menu: actual theme render passed.\n";
  }
  echo "All assertions passed. Fixtures retained for local browser check; run cleanup afterwards.\n";
}
catch (\Throwable $exception) {
  foreach ($ids as $id) {
    $storage->load($id)?->delete();
  }
  $state->delete($key);
  throw $exception;
}
finally {
  $switcher->switchBack();
}
