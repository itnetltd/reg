<?php

namespace Drupal\reg_core\Recruitment;

use Drupal\user\Entity\Role;
use Drupal\workflows\Entity\Workflow;

/** Adds explicit withdrawal without changing public archive behavior or content. */
final class RecruitmentWorkflowSetup {

  public static function apply(): void {
    $workflow = Workflow::load('reg_public_information_editorial');
    if (!$workflow) return;
    $settings = $workflow->get('type_settings');
    $settings['states']['unpublished'] ??= ['label' => 'Unpublished', 'weight' => 30, 'published' => FALSE, 'default_revision' => TRUE];
    $settings['transitions']['unpublish_vacancy'] ??= ['label' => 'Unpublish vacancy', 'from' => ['published', 'archived', 'unpublished'], 'to' => 'unpublished', 'weight' => 30];
    $settings['transitions']['revise_unpublished_vacancy'] ??= ['label' => 'Revise unpublished vacancy', 'from' => ['unpublished'], 'to' => 'draft', 'weight' => 35];
    if ($settings !== $workflow->get('type_settings')) $workflow->set('type_settings', $settings)->save();
    foreach (['reg_hr_approver', 'reg_hr_officer', 'reg_hr_editor'] as $id) {
      if (!$role = Role::load($id)) continue;
      $permissions = ['use reg_public_information_editorial transition revise_unpublished_vacancy'];
      if ($id === 'reg_hr_approver') $permissions[] = 'use reg_public_information_editorial transition unpublish_vacancy';
      foreach ($permissions as $permission) $role->grantPermission($permission);
      $role->save();
    }
  }

}