<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Block\BlockManagerInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\reg_core\News\NewsRepositoryInterface;
use Drupal\reg_core\PublicInformation\PublicInformationRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Composes the Media Center landing from shared structured content. */
final class MediaCenterController implements ContainerInjectionInterface {

  public function __construct(
    private readonly NewsRepositoryInterface $news,
    private readonly PublicInformationRepositoryInterface $publications,
    private readonly BlockManagerInterface $blockManager,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('reg_core.news_repository'),
      $container->get('reg_core.public_information_repository'),
      $container->get('plugin.manager.block'),
    );
  }

  public function landing(): array {
    $corporate = $this->news->archive(['section' => 'corporate'], 0, 4);
    $sports = $this->news->archive(['section' => 'sports'], 0, 3);
    $types = $this->publications->taxonomyOptions('reg_publication_type');
    $documents = [];
    foreach ([
      'press' => 'Press Release',
      'publications' => '',
      'newsletters' => 'Newsletter',
    ] as $key => $label) {
      $filters = ['sort' => 'newest'];
      if ($label !== '' && ($id = array_search($label, $types, TRUE))) $filters['category'] = (int) $id;
      $documents[$key] = array_slice($this->publications->search(['reg_publication'], $filters), 0, 3);
    }
    $social = $this->blockManager->createInstance('reg_core_social_media_follow', [])->build();
    return [
      '#theme' => 'reg_media_center',
      '#corporate' => $corporate['items'],
      '#sports' => $sports['items'],
      '#documents' => $documents,
      '#social' => $social,
      '#attached' => ['library' => ['reg_core/newsroom']],
      '#cache' => [
        'contexts' => ['languages:language_content', 'languages:language_interface', 'user.node_grants:view'],
        'tags' => array_values(array_unique(array_merge($corporate['cache_tags'], $sports['cache_tags'], ['node_list:reg_publication']))),
        'max-age' => 300,
      ],
    ];
  }

}
