<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filterable editorial overview for official branch contacts.
 */
final class BranchAdminController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * Builds the branch management listing.
   */
  public function overview(Request $request): array {
    $filters = [
      'q' => mb_substr(trim(strip_tags((string) $request->query->get('q', ''))), 0, 120),
      'district' => max(0, (int) $request->query->get('district', 0)),
      'entity' => in_array($request->query->get('entity', ''), ['reg', 'eucl', 'edcl'], TRUE) ? (string) $request->query->get('entity') : '',
      'review_status' => in_array($request->query->get('review_status', ''), ['imported_requires_validation', 'verified', 'needs_update'], TRUE) ? (string) $request->query->get('review_status') : '',
    ];
    $storage = $this->regEntityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_branch')
      ->sort('title', 'ASC')
      ->range(0, 500)
      ->execute();
    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface || !$node->access('update')) {
        continue;
      }
      $manager = $this->value($node, 'field_reg_manager_name');
      $email = $this->value($node, 'field_reg_email');
      $districtId = (int) ($node->get('field_reg_district_ref')->target_id ?? 0);
      $entity = $this->value($node, 'field_reg_entity');
      $review = $this->value($node, 'field_reg_branch_review_status');
      $haystack = mb_strtolower(implode(' ', [$node->label(), $manager, $email]));
      if (($filters['q'] !== '' && !str_contains($haystack, mb_strtolower($filters['q'])))
        || ($filters['district'] && $districtId !== $filters['district'])
        || ($filters['entity'] !== '' && $entity !== $filters['entity'])
        || ($filters['review_status'] !== '' && $review !== $filters['review_status'])) {
        continue;
      }
      $hasCoordinates = $this->value($node, 'field_reg_latitude') !== '' && $this->value($node, 'field_reg_longitude') !== '';
      $rows[] = [
        'branch' => (string) $node->label(),
        'manager' => $manager,
        'ph' => $this->value($node, 'field_reg_phone_ph'),
        'te' => $this->value($node, 'field_reg_phone_te'),
        'email' => $email,
        'location' => $hasCoordinates ? (string) $this->t('Coordinates available') : (string) $this->t('Coordinates pending'),
        'last_verified' => $this->value($node, 'field_reg_last_verified') ?: (string) $this->t('Not verified'),
        'published' => $node->isPublished() ? (string) $this->t('Yes') : (string) $this->t('No'),
        'edit_url' => Url::fromRoute('entity.node.edit_form', ['node' => $node->id()])->toString(),
      ];
    }

    return [
      '#theme' => 'reg_branch_admin',
      '#rows' => $rows,
      '#filters' => $filters,
      '#districts' => $this->districtOptions(),
      '#entities' => ['reg' => 'REG', 'eucl' => 'EUCL', 'edcl' => 'EDCL'],
      '#review_statuses' => [
        'imported_requires_validation' => $this->t('Imported — Requires REG Validation'),
        'verified' => $this->t('Verified by REG'),
        'needs_update' => $this->t('Needs update'),
      ],
      '#add_url' => Url::fromRoute('node.add', ['node_type' => 'reg_branch'])->toString(),
      '#clear_url' => Url::fromRoute('reg_core.branches_admin')->toString(),
      '#attached' => ['library' => ['reg_core/branch_admin']],
      '#cache' => [
        'contexts' => ['url.query_args', 'user.permissions'],
        'tags' => ['node_list:reg_branch', 'taxonomy_term_list:reg_district'],
      ],
    ];
  }

  /**
   * Returns one safe plain field value.
   */
  private function value(NodeInterface $node, string $field): string {
    return !$node->hasField($field) || $node->get($field)->isEmpty()
      ? ''
      : trim(strip_tags((string) $node->get($field)->value));
  }

  /**
   * Returns district filter options.
   */
  private function districtOptions(): array {
    $options = [];
    foreach ($this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree('reg_district') as $term) {
      $options[(int) $term->tid] = $term->name;
    }
    asort($options, SORT_NATURAL | SORT_FLAG_CASE);
    return $options;
  }

}
