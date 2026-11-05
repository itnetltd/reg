<?php

namespace Drupal\reg_core\Media;

use Drupal\media_directories_browser\Service\MediaTypeService;

/** Restricts upload scope choices without treating folders as authorization. */
class SecureMediaTypeService extends MediaTypeService {

  public function getMediaTypes(): array {
    $types = parent::getMediaTypes();
    $policy = \Drupal::service('reg_core.public_media_policy');
    foreach ($types as &$type) {
      foreach ($type['fields'] as &$field) {
        if ($field['name'] === 'field_reg_asset_scope' && !$policy->isAdministrator(\Drupal::currentUser())) {
          $field['options'] = array_intersect_key($field['options'] ?? [], array_flip($policy->scopes(\Drupal::currentUser())));
        }
        if ($field['name'] === 'field_reg_public_asset') {
          // The installed browser supports selects, but not checkbox widgets.
          // A one-option required select provides an explicit attestation.
          $field['options'] = ['1' => (string) t('Confirmed: public asset, no confidential or personal data')];
          $field['required'] = TRUE;
        }
      }
      unset($field);
    }
    unset($type);
    return $types;
  }

}
