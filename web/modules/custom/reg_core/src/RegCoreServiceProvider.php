<?php

namespace Drupal\reg_core;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\reg_core\Media\SecureMediaService;

/**
 * Retains the installed browser service dependencies while adding policy gates.
 */
class RegCoreServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('media_directories_browser.media_type_service')) {
      $container->getDefinition('media_directories_browser.media_type_service')->setClass(\Drupal\reg_core\Media\SecureMediaTypeService::class);
    }
    if ($container->hasDefinition('media_directories_browser.media_service')) {
      $container->getDefinition('media_directories_browser.media_service')->setClass(SecureMediaService::class);
    }
  }

}
