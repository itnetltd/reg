<?php

namespace Drupal\reg_core\Recruitment;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\node\NodeInterface;

/** Reversible recruitment planning switch; vacancy applications stay independent. */
final class RecruitmentPlanWorkflow {

  public const BUNDLES = ['reg_recruitment_plan', 'reg_recruitment_plan_item'];
  public const FIELDS = ['field_reg_recruit_plan_item', 'field_reg_financial_year', 'field_reg_unplanned', 'field_reg_exception_reason', 'field_reg_approval_ref', 'field_reg_approval_document'];

  public static function enabled(): bool {
    return (bool) \Drupal::config('reg_core.settings')->get('public_information.recruitment_plan_integration');
  }

  public static function access(): AccessResult {
    return AccessResult::allowedIf(self::enabled())->addCacheableDependency(\Drupal::config('reg_core.settings'));
  }

  /** Standalone vacancies retain application rules and existing relationships. */
  public static function standalonePresave(NodeInterface $node): void {
    $original = $node->isNew() ? NULL : $node->getOriginal();
    if ($original && !$node->get('field_reg_recruit_plan_item')->equals($original->get('field_reg_recruit_plan_item'))) {
      throw new EntityStorageException('Enable Annual Recruitment Plan Integration before changing an existing recruitment plan relationship.');
    }
    if ($node->isNew()) {
      $method = (string) $node->get('field_reg_application_method')->value;
      if ($method === '') {
        throw new EntityStorageException('New vacancies require an application method.');
      }
      if (in_array($method, ['mifotra', 'external'], TRUE) && $node->get('field_reg_application_url')->isEmpty()) {
        throw new EntityStorageException('An authoritative external application URL is required.');
      }
    }
  }

}