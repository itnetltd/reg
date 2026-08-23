<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Administrative aggregate document-download reports.
 */
final class PublicInformationReportController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $regDateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Displays tender aggregate download counts.
   */
  public function tenderDownloads(): array {
    return $this->report('tender');
  }

  /**
   * Displays publication aggregate download counts.
   */
  public function publicationDownloads(): array {
    return $this->report('publication');
  }

  /**
   * Builds a privacy-preserving aggregate report.
   */
  private function report(string $type): array {
    $query = $this->database->select('reg_core_document_download', 'd')
      ->fields('d', ['nid', 'media_id', 'document_label', 'downloads', 'first_downloaded', 'last_downloaded'])
      ->condition('content_type', $type)
      ->orderBy('downloads', 'DESC')
      ->orderBy('last_downloaded', 'DESC')
      ->extend(PagerSelectExtender::class)
      ->limit(50);
    $rows = [];
    foreach ($query->execute() as $record) {
      $rows[] = [
        'data' => [
          $record->document_label,
          (int) $record->nid,
          (int) $record->media_id,
          (int) $record->downloads,
          $this->regDateFormatter->format((int) $record->first_downloaded, 'short'),
          $this->regDateFormatter->format((int) $record->last_downloaded, 'short'),
        ],
      ];
    }

    return [
      'notice' => [
        '#markup' => '<p>' . $this->t('Counts are aggregated. This report does not store IP addresses, user IDs, or visitor-level download histories.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Document'),
          $this->t('Content ID'),
          $this->t('Media ID'),
          $this->t('Downloads'),
          $this->t('First download'),
          $this->t('Last download'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No downloads have been recorded.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
