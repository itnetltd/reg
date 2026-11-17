<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Media\UploadPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Regression tests for the simplified public PDF upload gate.
 */
#[CoversClass(UploadPolicy::class)]
final class UploadPolicyTest extends TestCase {

  public function testPdfWithScannedBytesIsNotRejectedByActiveContentHeuristic(): void {
    // This marker occurs inside a scan stream and is not PDF action syntax.
    $pdf = "%PDF-1.4\n1 0 obj\n<< /Length 15 >>\nstream\nscan bytes /JS!\nendstream\nendobj\n%%EOF\n";
    $this->withTemporaryFile($pdf, static function (string $path): void {
      self::assertSame([], UploadPolicy::errors($path, 'Public tender notice.pdf'));
    });
  }

  public function testInvalidMimeAndForbiddenFileNamesAreStillRejected(): void {
    $this->withTemporaryFile('This is not a PDF', static function (string $path): void {
      self::assertContains('The detected MIME type does not match the permitted file extension.', UploadPolicy::errors($path, 'Tender notice.pdf'));
    });

    $this->withTemporaryFile("%PDF-1.4\n%%EOF\n", static function (string $path): void {
      self::assertNotSame([], UploadPolicy::errors($path, 'candidate_cv.pdf'));
      self::assertNotSame([], UploadPolicy::errors($path, 'notice.php.pdf'));
    });
  }

  private function withTemporaryFile(string $contents, callable $assertion): void {
    $path = tempnam(sys_get_temp_dir(), 'reg-upload-');
    self::assertNotFalse($path);
    try {
      file_put_contents($path, $contents);
      $assertion($path);
    }
    finally {
      unlink($path);
    }
  }

}
