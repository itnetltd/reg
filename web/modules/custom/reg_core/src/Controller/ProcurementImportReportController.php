<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Downloads an authorized user's private preview without consuming its form. */
final class ProcurementImportReportController extends ControllerBase {

  /**
   * Returns the current user's validation report with spreadsheet-safe cells. */
  public function download(string $token): StreamedResponse {
    $rows = \Drupal::service('tempstore.private')->get('reg_procurement_reports')->get($token);
    if (!is_array($rows)) {
      throw new NotFoundHttpException();
    }
    $response = new StreamedResponse(static function () use ($rows) {
      $stream = fopen('php://output', 'wb');
      fputcsv($stream, ['CSV record', 'Source row', 'Import key', 'Action', 'Errors', 'Warnings'], ',', '"', '');
      foreach ($rows as $row) {
        $values = [
          $row['record'],
          $row['data']['source_row'],
          $row['data']['import_key'],
          $row['action'],
          implode(' | ',
        $row['errors']),
          implode(' | ',
        $row['warnings']),
        ];
        $values = array_map(static fn($v) => preg_match('/^[\s]*[=+@-]/u', (string) $v) ? "'" . $v : (string) $v, $values);
        fputcsv($stream, $values, ',', '"', '');
      }
      fclose($stream);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="procurement-validation.csv"');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

}
