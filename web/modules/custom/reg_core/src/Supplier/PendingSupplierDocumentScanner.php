<?php

namespace Drupal\reg_core\Supplier;

use Drupal\file\FileInterface;

/** Safe default: files remain pending until an approved scanner is integrated. */
final class PendingSupplierDocumentScanner implements SupplierDocumentScannerInterface {
  public function queue(FileInterface $file, string $context): string { return 'pending'; }
}
