<?php

namespace Drupal\reg_core\Translation;

use Drupal\user\Entity\Role;
use Drupal\workflows\Entity\Workflow;

/**
 * Separate, least-privilege translator role. No general entity edit grants.
 */
final class TranslationOnlyRole {

  public const ID = 'reg_translation_only';

  /**
   * Creates the role from current public content and moderation configuration.
   *
   * Only moderated, translation-enabled bundles with a safe draft/review
   * transition are granted. Unmoderated content must be configured for review
   * before this role can translate it. Other roles and users are not changed.
   */
  public static function apply(): void {
    $public = array_keys(\Drupal::service('reg_core.translation_status_repository')->workspaceBundleOptions(FALSE));
    $permissions = [
      'access content',
      'view the administration theme',
      'view reg translation report',
      'create content translations',
      'update content translations',
      'use text format basic_html',
      'use text format restricted_html',
    ];
    foreach (Workflow::loadMultiple() as $workflow) {
      if (!$workflow->status() || $workflow->get('type') !== 'content_moderation') {
        continue;
      }
      $settings = $workflow->get('type_settings') ?? [];
      $bundles = array_intersect($public, $settings['entity_types']['node'] ?? []);
      if (!$bundles) {
        continue;
      }
      $safe = self::safeTransitions($settings);
      if (!$safe) {
        continue;
      }
      foreach ($bundles as $bundle) {
        $permissions[] = "translate $bundle node";
      }
      foreach ($safe as $transition) {
        $permissions[] = 'use ' . $workflow->id() . ' transition ' . $transition;
      }
    }
    // Do not grant missing permissions, broad translation privileges,
    // admin overview access, publication, deletion, or original node edits.
    $available = \Drupal::service('user.permissions')->getPermissions();
    $permissions = array_values(array_intersect(array_unique($permissions), array_keys($available)));
    $role = Role::load(self::ID) ?: Role::create([
      'id' => self::ID,
      'label' => 'REG Translation Only',
    ]);
    $role->set('is_admin', FALSE);
    $role->set('permissions', $permissions);
    $role->save();
  }

  /**
   * Only allow transitions to explicitly non-published, non-default drafts.
   */
  public static function safeTransitions(array $settings): array {
    $safe = [];
    foreach ($settings['transitions'] ?? [] as $id => $transition) {
      if (!in_array($id, ['create_new_draft', 'submit_for_review'], TRUE)) {
        continue;
      }
      $state = $settings['states'][$transition['to'] ?? ''] ?? [];
      if (array_key_exists('published', $state) && !$state['published']
        && array_key_exists('default_revision', $state) && !$state['default_revision']) {
        $safe[] = $id;
      }
    }
    return $safe;
  }

}
