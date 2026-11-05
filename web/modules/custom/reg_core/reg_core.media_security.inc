<?php

/**
 * @file
 * Public Media gates. Private submission storage/routes remain independent.
 */

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\MediaInterface;
use Drupal\reg_core\Media\ProtectedFileStorage;

/** Implements hook_entity_type_alter(). */
function reg_core_entity_type_alter(array &$entity_types): void {
  $entity_types['media']->addConstraint('RegPublicMedia');
  $entity_types['file']->setHandlerClass('storage', ProtectedFileStorage::class);
}

/** Implements hook_media_access(). */
function reg_core_media_access(MediaInterface $media, string $operation, AccountInterface $account): AccessResult {
  $policy = \Drupal::service('reg_core.public_media_policy');
  if (!$policy->isPublic($media)) {
    return AccessResult::forbidden('Private files are excluded from public Media.')->addCacheableDependency($media);
  }
  if (in_array($operation, ['update', 'delete'], TRUE) && !$policy->canManage($media, $account)) {
    return AccessResult::forbidden('Media scope does not match the editor domain.')->cachePerPermissions()->addCacheableDependency($media)->addCacheTags(['config:reg_core.media_security']);
  }
  if ($operation === 'delete' && $policy->referenced('media', $media->id(), $media->uuid())) {
    return AccessResult::forbidden('Media is referenced by content or a revision.')->setCacheMaxAge(0);
  }
  return AccessResult::neutral()->cachePerPermissions()->addCacheableDependency($media)->addCacheTags(['config:reg_core.media_security']);
}

/** Implements hook_media_predelete(). */
function reg_core_media_predelete(MediaInterface $media): void {
  if (\Drupal::service('reg_core.public_media_policy')->referenced('media', $media->id(), $media->uuid())) {
    throw new EntityStorageException('Referenced Media cannot be deleted. Remove content and revision references first.');
  }
}

/** Implements hook_file_access(). */
function reg_core_file_access(\Drupal\file\FileInterface $file, string $operation, AccountInterface $account): AccessResult {
  return $operation === 'delete' && \Drupal::service('reg_core.public_media_policy')->referenced('file', $file->id(), $file->uuid())
    ? AccessResult::forbidden('File is still referenced.')->setCacheMaxAge(0)
    : AccessResult::neutral();
}

/** Implements hook_field_widget_single_element_form_alter(). */
function reg_core_field_widget_single_element_form_alter(array &$element, FormStateInterface $form_state, array $context): void {
  if ($context['items']->getEntity() instanceof MediaInterface && $context['items']->getName() === 'field_reg_asset_scope') {
    $policy = \Drupal::service('reg_core.public_media_policy');
    if (!$policy->isAdministrator(\Drupal::currentUser())) {
      $allowed = $policy->scopes(\Drupal::currentUser());
      $element['#options'] = array_intersect_key($element['#options'] ?? [], array_flip($allowed));
      if ($context['items']->getEntity()->isNew()) {
        $element['#default_value'] = array_slice($allowed, 0, 1);
        $element['#required'] = TRUE;
      }
    }
  }
  if ($context['items']->getEntity() instanceof MediaInterface && in_array($context['items']->getFieldDefinition()->getType(), ['file', 'image'], TRUE)) {
    $element['#upload_validators']['RegPublicFile'] = [];
  }
}

/** Implements hook_library_info_alter(). */
function reg_core_library_info_alter(array &$libraries, string $extension): void {
  if ($extension === 'media_directories_browser' && isset($libraries['media_directories_browser_app'])) {
    $libraries['media_directories_browser_app']['dependencies'][] = 'reg_core/media_browser_mobile';
  }
}
