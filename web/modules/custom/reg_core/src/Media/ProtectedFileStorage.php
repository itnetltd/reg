<?php

namespace Drupal\reg_core\Media;

use Drupal\Core\Entity\EntityStorageException;
use Drupal\file\FileStorage;

/**
 * Checks references before File::preDelete can remove physical bytes.
 */
class ProtectedFileStorage extends FileStorage {

  public function delete(array $entities) {
    foreach ($entities as $file) {
      if (\Drupal::service('reg_core.public_media_policy')->referenced('file', $file->id(), $file->uuid())) {
        throw new EntityStorageException('This file is still referenced. Remove its content/Media references before deleting it.');
      }
    }
    parent::delete($entities);
  }

}
