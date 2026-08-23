<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lists native social posts for Communications editors.
 */
final class SocialPostAdminController implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('string_translation'),
    );
  }

  /**
   * Displays the managed social-post collection.
   */
  public function overview(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'reg_social_post')
      ->sort('field_reg_social_published_at', 'DESC')
      ->sort('changed', 'DESC')
      ->range(0, 100)
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      $latest_revision_id = $storage->getLatestRevisionId($node->id());
      $latest = $latest_revision_id ? $storage->loadRevision($latest_revision_id) : NULL;
      if ($latest instanceof NodeInterface) {
        $node = $latest;
      }
      $state = $node->hasField('moderation_state') && !$node->get('moderation_state')->isEmpty()
        ? (string) $node->get('moderation_state')->value
        : ($node->isPublished() ? 'published' : 'draft');
      $post_url = !$node->get('field_reg_social_post_url')->isEmpty()
        ? (string) $node->get('field_reg_social_post_url')->uri
        : '';
      $rows[] = [
        'title' => $node->label(),
        'platform' => strtoupper((string) $node->get('field_reg_social_platform')->value),
        'publication_date' => !$node->get('field_reg_social_published_at')->isEmpty()
          ? $this->dateFormatter->format(strtotime((string) $node->get('field_reg_social_published_at')->value . ' UTC'), 'short')
          : $this->t('Not set'),
        'featured' => (bool) $node->get('field_reg_social_homepage')->value ? $this->t('Yes') : $this->t('No'),
        'source' => ucfirst((string) $node->get('field_reg_social_source')->value),
        'moderation' => ucwords(str_replace('_', ' ', $state)),
        'view' => $post_url !== ''
          ? ['data' => Link::fromTextAndUrl($this->t('View post'), Url::fromUri($post_url, ['attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]))->toRenderable()]
          : '—',
        'edit' => ['data' => Link::fromTextAndUrl($this->t('Edit'), Url::fromRoute('entity.node.edit_form', ['node' => $node->id()], ['query' => ['destination' => '/admin/content/social-posts']]))->toRenderable()],
      ];
    }

    return [
      'actions' => [
        '#type' => 'container',
        'add' => [
          '#type' => 'link',
          '#title' => $this->t('ADD SOCIAL POST'),
          '#url' => Url::fromRoute('node.add', ['node_type' => 'reg_social_post']),
          '#attributes' => ['class' => ['button', 'button--primary', 'button--action']],
        ],
      ],
      'help' => ['#markup' => '<p>' . $this->t('Manage approved native social posts. The homepage shows at most two published, featured X posts.') . '</p>'],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Administrative title'),
          $this->t('Platform'),
          $this->t('Publication date'),
          $this->t('Homepage'),
          $this->t('Source'),
          $this->t('Editorial state'),
          $this->t('Original'),
          $this->t('Edit'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No social posts have been added.'),
        '#sticky' => TRUE,
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

}
