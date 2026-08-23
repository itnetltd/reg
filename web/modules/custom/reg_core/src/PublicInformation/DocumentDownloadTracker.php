<?php

namespace Drupal\reg_core\PublicInformation;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;

/**
 * Database-backed aggregate download tracker.
 */
final class DocumentDownloadTracker implements DocumentDownloadTrackerInterface {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AnalyticsEventTrackerInterface $analytics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function record(string $content_type, int $nid, int $media_id, int $file_id, string $label): void {
    if (!in_array($content_type, ['tender', 'publication'], TRUE)) {
      return;
    }

    $now = $this->time->getRequestTime();
    $key = hash('sha256', implode(':', [$content_type, $nid, $media_id, $file_id]));
    $this->database->merge('reg_core_document_download')
      ->key('download_key', $key)
      ->insertFields([
        'download_key' => $key,
        'content_type' => $content_type,
        'nid' => $nid,
        'media_id' => $media_id,
        'file_id' => $file_id,
        'document_label' => mb_substr(trim(strip_tags($label)), 0, 255),
        'downloads' => 1,
        'first_downloaded' => $now,
        'last_downloaded' => $now,
      ])
      ->updateFields([
        'document_label' => mb_substr(trim(strip_tags($label)), 0, 255),
        'last_downloaded' => $now,
      ])
      ->expression('downloads', 'downloads + 1')
      ->execute();
    $this->analytics->record($content_type . '_download', [
      'dimension_type' => 'bundle',
      'dimension_value' => $content_type,
      'entity_id' => $nid,
    ]);
  }

}
