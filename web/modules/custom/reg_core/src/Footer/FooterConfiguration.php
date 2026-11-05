<?php

namespace Drupal\reg_core\Footer;

use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\system\Entity\Menu;

/** One-time initialization of independently managed footer content. */
final class FooterConfiguration {

  public const DEFAULTS = [
    'organization_name' => 'Rwanda Energy Group',
    'organization_description' => 'Powering Rwanda’s future through innovation and reliable service delivery.',
    'quick_links_heading' => 'Quick links',
    'support_heading' => 'Customer support',
    'support_description' => 'For electricity service help, contact the REG call center.',
    'call_label' => 'Call',
    'social_heading' => 'Follow REG',
    'language_heading' => 'Language',
    'copyright' => '© @year Rwanda Energy Group Limited. All Rights Reserved.',
  ];

  /** Never reseed an existing menu, including an intentionally empty menu. */
  public static function initialize(): void {
    $config = \Drupal::configFactory()->getEditable('reg_core.footer');
    if ($config->isNew()) {
      $config->setData(['langcode' => 'en'] + self::DEFAULTS)->save();
    }
    if (!Menu::load('reg-footer-quick-links')) {
      Menu::create(['id' => 'reg-footer-quick-links', 'label' => 'REG Footer Quick Links', 'description' => 'Footer links managed independently of the header.'])->save();
      $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
      $copies = [];
      $parents = [];
      foreach ($storage->loadByProperties(['menu_name' => 'reg-utility-menu']) as $source) {
        $copy = $source->createDuplicate();
        foreach ($copy->getTranslationLanguages() as $langcode => $language) {
          $translation = $copy->getTranslation($langcode);
          $translation->set('menu_name', 'reg-footer-quick-links');
          $options = $translation->get('link')->options ?? [];
          // Header migration markers must not identify footer links.
          unset($options['reg_core_navigation_id'], $options['reg_mobile_priority']);
          $translation->get('link')->options = $options;
        }
        $copy->set('parent', '')->save();
        $copies[$source->getPluginId()] = $copy;
        $parents[$source->getPluginId()] = $source->getParentId();
      }
      foreach ($copies as $original => $copy) {
        if (isset($copies[$parents[$original]])) {
          $copy->set('parent', $copies[$parents[$original]]->getPluginId())->save();
        }
      }
    }
    if (!Menu::load('reg-footer-support')) {
      Menu::create(['id' => 'reg-footer-support', 'label' => 'REG Footer Customer Support', 'description' => 'Footer customer support destinations. Call center uses REG website settings.'])->save();
      foreach (['Branch Locator' => 'reg_core.branches', 'Contact REG' => 'reg_core.contact'] as $title => $route) {
        MenuLinkContent::create(['title' => $title, 'menu_name' => 'reg-footer-support', 'link' => ['uri' => 'route:' . $route], 'weight' => $route === 'reg_core.branches' ? 0 : 1, 'enabled' => TRUE])->save();
      }
    }
  }

}
