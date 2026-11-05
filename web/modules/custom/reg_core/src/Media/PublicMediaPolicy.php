<?php

namespace Drupal\reg_core\Media;

use Drupal\Core\Session\AccountInterface;
use Drupal\media\MediaInterface;

/**
 * Enforces public-only Media scope independently of virtual folders.
 */
final class PublicMediaPolicy {

  public const SCOPES = ['editorial' => 'Public editorial assets', 'procurement' => 'Public tender documents', 'hr' => 'Public recruitment documents', 'sports' => 'Public sports assets', 'unassigned' => 'Unassigned - administrator review'];

  private ?array $scopeIndex = NULL;

  public function resetScopeIndex(): void {
    $this->scopeIndex = NULL;
  }

  /**
   * Existing assets use a fixed registry, without migration writes.
   * Explicit scope on new assets is immutable to non-administrators.
   */
  public function assetScopes(MediaInterface $media): array {
    $explicit = $media->hasField('field_reg_asset_scope') ? array_column($media->get('field_reg_asset_scope')->getValue(), 'value') : [];
    if ($explicit) { return $explicit; }
    $this->scopeIndex ??= (array) \Drupal::config('reg_core.media_security')->get('legacy_scopes');
    return $this->scopeIndex[$media->uuid()] ?? ['unassigned'];
  }
  public function scopes(AccountInterface $account): array {
    return array_keys(array_filter(self::SCOPES, static fn($label, $scope) => $account->hasPermission('manage reg ' . $scope . ' media'), ARRAY_FILTER_USE_BOTH));
  }

  public function isAdministrator(AccountInterface $account): bool {
    return $account->hasPermission('administer media');
  }

  public function isPublic(MediaInterface $media): bool {
    $source = $media->getSource()->getConfiguration()['source_field'] ?? '';
    if (!$source || !$media->hasField($source)) {
      return FALSE;
    }
    $field = $media->get($source);
    if (in_array($field->getFieldDefinition()->getType(), ['file', 'image'], TRUE)) {
      foreach ($field as $item) {
        if ($item->entity && !str_starts_with($item->entity->getFileUri(), 'public://')) {
          return FALSE;
        }
      }
    }
    return TRUE;
  }

  public function canManage(MediaInterface $media, AccountInterface $account): bool {
    if (!$this->isPublic($media)) {
      return FALSE;
    }
    if ($this->isAdministrator($account)) {
      return TRUE;
    }
    $scopes = $this->assetScopes($media);
    return (bool) array_intersect($scopes, $this->scopes($account));
  }

  public function errors(MediaInterface $media, AccountInterface $account): array {
    if (!$media->hasField('field_reg_public_asset')) {
      return [];
    }
    $errors = [];
    if (!$this->isPublic($media)) {
      $errors[] = 'Private files cannot be attached to public Media. Use the existing secure submission workflow.';
    }
    $original = $media->isNew() ? NULL : ($media->original ?? \Drupal::entityTypeManager()->getStorage('media')->loadUnchanged($media->id()));
    if ($media->isNew() && !(bool) $media->get('field_reg_public_asset')->value) {
      $errors[] = 'Confirm this asset is intended for public publication and contains no bids, CVs, applications, confidential files or personal data.';
    }
    $scopes = $this->assetScopes($media);
    if (!$scopes || array_diff($scopes, array_keys(self::SCOPES))) {
      $errors[] = 'Select a valid public asset scope.';
    }
    if (!$this->isAdministrator($account)) {
      if (!array_intersect($scopes, $this->scopes($account)) || ($media->isNew() && array_diff($scopes, $this->scopes($account)))) {
        $errors[] = 'You may manage only Media in your authorized editorial domain.';
      }
      $original = $media->isNew() ? NULL : ($media->original ?? \Drupal::entityTypeManager()->getStorage('media')->loadUnchanged($media->id()));
      if ($original && $original->get('field_reg_asset_scope')->getValue() !== $media->get('field_reg_asset_scope')->getValue()) {
        $errors[] = 'Only a Media administrator may reclassify an existing asset.';
      }
    }
    $source = $media->getSource()->getConfiguration()['source_field'] ?? '';
    if ($source && in_array($media->get($source)->getFieldDefinition()->getType(), ['file', 'image'], TRUE)) {
      $original = $media->isNew() ? NULL : ($media->original ?? \Drupal::entityTypeManager()->getStorage('media')->loadUnchanged($media->id()));
      foreach ($media->get($source) as $item) {
        // Existing unchanged assets are not re-encoded or renamed.
        if ($item->entity && (!$original || $original->get($source)->target_id !== $item->target_id)) {

          if (!(bool) $media->get('field_reg_public_asset')->value) { $errors[] = 'Confirm this replacement file is a public asset with no confidential or personal data.'; }
          $errors = array_merge($errors, UploadPolicy::errors($item->entity->getFileUri(), $item->entity->getFilename()));
        }
      }
    }
    return array_unique($errors);
  }

  /**
   * Finds direct references and UUID embeds, including unpublished revisions.
   */
  public function referenced(string $targetType, string|int $id, ?string $uuid = NULL): bool {
    $manager = \Drupal::entityTypeManager();
    $fields = \Drupal::service('entity_field.manager');
    foreach ($manager->getDefinitions() as $type => $definition) {
      if (!$definition->entityClassImplements(\Drupal\Core\Entity\FieldableEntityInterface::class)) {
        continue;
      }
      foreach ($fields->getFieldStorageDefinitions($type) as $name => $field) {
        $reference = ($field->getType() === 'entity_reference' && $field->getSetting('target_type') === $targetType)
          || ($targetType === 'file' && in_array($field->getType(), ['file', 'image'], TRUE));
        $embed = $uuid && in_array($field->getType(), ['text', 'text_long', 'text_with_summary'], TRUE);
        if (!$reference && !$embed) {
          continue;
        }
        $query = $manager->getStorage($type)->getQuery()->accessCheck(FALSE);
        if ($definition->isRevisionable()) {
          $query->allRevisions();
        }
        $query->condition($name . ($reference ? '.target_id' : '.value'), $reference ? $id : $uuid, $reference ? '=' : 'CONTAINS');
        if ($query->range(0, 1)->execute()) {
          return TRUE;
        }
      }
    }
    if ($targetType === 'file' && ($file = $manager->getStorage('file')->load($id))) {
      return (bool) \Drupal::service('file.usage')->listUsage($file);
    }
    return FALSE;
  }

}
