<?php

namespace Drupal\reg_core\Supplier;

use Drupal\file\FileInterface;

/** Contract for an approved asynchronous private-document malware scanner. */
interface SupplierDocumentScannerInterface {
  /** Queues a private file and returns pending, clean, or rejected. */
  public function queue(FileInterface $file, string $context): string;
}
