<?php

namespace Drupal\reg_core\Media;

use Drupal\media_directories_browser\Service\MediaService;

/**
 * Adds public upload gates to the installed browser, retaining its UI/API.
 */
class SecureMediaService extends MediaService {

  public function createFromFiles(array $files, string $media_type_id, ?string $directory_uuid, array $metadata): array {
    if (count($files) > 20) { throw new \InvalidArgumentException('Upload at most 20 files per request.'); }
    $errors = [];
    $created = [];
    foreach (array_values($files) as $index => $file) {
      try {
        if (!$file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || !$file->isValid()) { throw new \InvalidArgumentException('Invalid file upload.'); }
        $values = $metadata[$index] ?? [];
        $this->assertMetadataFields($media_type_id, $values);
        $this->assertPublicMetadata($values);
        $problems = UploadPolicy::errors($file->getRealPath(), $file->getClientOriginalName());
        if ($problems) {
          throw new \InvalidArgumentException(implode(' ', $problems));
        }
        $result = parent::createFromFiles([$file], $media_type_id, $directory_uuid, [$values]);
        if ($result['created']) {
          $created[$index] = reset($result['created']);
        }
        foreach ($result['errors'] as $error) {
          $error['index'] = $index;
          $errors[] = $error;
        }
      }
      catch (\InvalidArgumentException $e) {
        $errors[] = ['index' => $index, 'filename' => $file->getClientOriginalName(), 'message' => $e->getMessage()];
      }
    }
    return ['created' => $created, 'errors' => $errors];
  }

  public function createFromUrl(string $media_type_id, string $url, ?string $directory_uuid, array $item_metadata): array {
    $this->assertMetadataFields($media_type_id, $item_metadata);
    $this->assertPublicMetadata($item_metadata);
    return parent::createFromUrl($media_type_id, $url, $directory_uuid, $item_metadata);
  }

  /** Prevent API metadata from overriding owners, publication, files or folders. */
  private function assertMetadataFields(string $bundle, array $values): void {
    $type = $this->entityTypeManager->getStorage('media_type')->load($bundle);
    if (!$type) { throw new \InvalidArgumentException('Unknown Media type.'); }
    $source = $type->getSource()->getConfiguration()['source_field'] ?? '';
    $fields = $this->fieldManager->getFieldDefinitions('media', $bundle);
    foreach (array_keys($values) as $key) {
      if ($key === 'name' || in_array($key, [$source . ':alt', $source . ':title', $source . ':description', 'focal_point'], TRUE)) { continue; }
      if (!isset($fields[$key]) || $fields[$key]->getFieldStorageDefinition()->isBaseField() || $key === $source) {
        throw new \InvalidArgumentException('This metadata field cannot be changed through the Media Browser.');
      }
    }
  }
  private function assertPublicMetadata(array &$values): void {
    if (empty($values['field_reg_public_asset']) || !in_array($values['field_reg_public_asset'], [TRUE, 1, '1'], TRUE)) {
      throw new \InvalidArgumentException('Confirm that this upload is a public asset with no confidential or personal data.');
    }
    $policy = \Drupal::service('reg_core.public_media_policy');
    $allowed = $policy->isAdministrator(\Drupal::currentUser()) ? array_keys(PublicMediaPolicy::SCOPES) : $policy->scopes(\Drupal::currentUser());
    $scopes = $values['field_reg_asset_scope'] ?? [];
    $scopes = is_array($scopes) ? $scopes : [$scopes];
    if (!$scopes) {
      $scopes = array_slice($allowed, 0, 1);
    }
    if (!$scopes || array_diff($scopes, $allowed)) {
      throw new \InvalidArgumentException('Select an authorized public asset scope.');
    }
    $values['field_reg_asset_scope'] = $scopes;
  }

  public function updateMedia(string $uuid, array $field_values): void {
    $media = $this->loadForOperation($uuid, 'update');
    $this->assertMetadataFields($media->bundle(), $field_values);
    if (!\Drupal::service('reg_core.public_media_policy')->isAdministrator(\Drupal::currentUser()) && array_key_exists('field_reg_asset_scope', $field_values)) {
      $requested = array_filter((array) $field_values['field_reg_asset_scope']);
      $current = \Drupal::service('reg_core.public_media_policy')->assetScopes($media);
      if (array_diff($requested, $current)) {
        throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException('Only a Media administrator may reclassify an existing asset.');
      }
      unset($field_values['field_reg_asset_scope']);
    }
    parent::updateMedia($uuid, $field_values);
  }
  protected function serializeMediaItems(array $media_entities): array {
    return parent::serializeMediaItems(array_filter($media_entities, static fn($media) => $media->access('view')));
  }

}
