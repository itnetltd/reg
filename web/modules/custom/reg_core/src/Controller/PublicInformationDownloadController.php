<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\media\MediaInterface;
use Drupal\node\NodeInterface;
use Drupal\reg_core\PublicInformation\DocumentDownloadTrackerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Delivers approved public documents through an auditable route.
 */
final class PublicInformationDownloadController extends ControllerBase {

  private const FIELDS = [
    'reg_tender' => [
      'field_reg_tender_documents',
      'field_reg_tender_addenda',
      'field_reg_award_documents',
    ],
    'reg_job' => [
      'field_reg_job_documents',
      'field_reg_result_documents',
    ],
    'reg_publication' => ['field_reg_publication_document'],
  ];

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly FileSystemInterface $regFileSystem,
    private readonly DocumentDownloadTrackerInterface $tracker,
    private readonly ConfigFactoryInterface $regConfigFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('reg_core.document_download_tracker'),
      $container->get('config.factory'),
    );
  }

  /**
   * Returns a published file or a generic 404 for every invalid condition.
   */
  public function download(int $node, string $field_name, int $delta): BinaryFileResponse {
    $content = $this->regEntityTypeManager->getStorage('node')->load($node);
    if (!$content instanceof NodeInterface || !$content->isPublished() || !$content->access('view')) {
      throw new NotFoundHttpException();
    }
    if (!in_array($field_name, self::FIELDS[$content->bundle()] ?? [], TRUE) || $delta < 0 || !$content->hasField($field_name)) {
      throw new NotFoundHttpException();
    }

    $item = $content->get($field_name)->get($delta);
    $media = $item?->entity;
    if (!$media instanceof MediaInterface || !$media->isPublished() || !$media->access('view')) {
      throw new NotFoundHttpException();
    }
    $source_field = (string) ($media->getSource()->getConfiguration()['source_field'] ?? '');
    $file = $source_field !== '' ? $media->get($source_field)->entity : NULL;
    if (!$file) {
      throw new NotFoundHttpException();
    }

    $extension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));
    $allowed = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
    if ($this->regConfigFactory->get('reg_core.settings')->get('public_information.allow_zip_uploads')) {
      $allowed[] = 'zip';
    }
    if (!in_array($extension, $allowed, TRUE)) {
      throw new NotFoundHttpException();
    }

    $path = $this->regFileSystem->realpath($file->getFileUri());
    if (!$path || !is_file($path)) {
      throw new NotFoundHttpException();
    }

    $type = match ($content->bundle()) {
      'reg_tender' => 'tender',
      'reg_publication' => 'publication',
      default => '',
    };
    if ($type !== '') {
      $this->tracker->record($type, (int) $content->id(), (int) $media->id(), (int) $file->id(), $media->label());
    }

    $response = new BinaryFileResponse($path);
    $response->headers->set('Content-Type', $file->getMimeType() ?: 'application/octet-stream');
    $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $file->getFilename()));
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
