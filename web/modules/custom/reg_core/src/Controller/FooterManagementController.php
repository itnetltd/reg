<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/** One entry point for existing Drupal footer administration tools. */
final class FooterManagementController extends ControllerBase {

  public function overview(): array {
    $build = ['links' => ['#theme' => 'item_list', '#items' => []], '#cache' => ['contexts' => ['user.permissions', 'languages:language_interface']]];
    $destinations = [
      [$this->t('Footer Settings'), 'reg_core.footer_settings', []],
      [$this->t('Footer Quick Links menu'), 'entity.menu.edit_form', ['menu' => 'reg-footer-quick-links']],
      [$this->t('Footer Customer Support menu'), 'entity.menu.edit_form', ['menu' => 'reg-footer-support']],
      [$this->t('Social Media Settings'), 'reg_core.social_media_settings', []],
      [$this->t('Footer block layout (including login visibility)'), 'block.admin_display_theme', ['theme' => 'reg_theme']],
      [$this->t('Shared call center settings'), 'reg_core.settings', []],
    ];
    foreach ($destinations as [$label, $route, $parameters]) {
      $url = Url::fromRoute($route, $parameters);
      $access = $url->access($this->currentUser(), TRUE);
      CacheableMetadata::createFromObject($access)->merge(CacheableMetadata::createFromRenderArray($build))->applyTo($build);
      if ($access->isAllowed()) {
        $build['links']['#items'][] = Link::fromTextAndUrl($label, $url)->toRenderable();
      }
    }
    $build['guidance'] = ['#markup' => '<p>' . $this->t('Footer settings require Administer REG footer. Core menu editing requires Administer menus; social and shared contact settings require Administer REG website settings; block visibility requires Administer blocks. Assign these permissions only to authorized editors. Footer menus are independent of the header utility menu.') . '</p>'];
    return $build;
  }

}
