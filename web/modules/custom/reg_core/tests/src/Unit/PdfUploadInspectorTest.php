<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Media\PdfUploadInspector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests PDF upload scanning with real and simulated PDF action markers.
 */
#[CoversClass(PdfUploadInspector::class)]
final class PdfUploadInspectorTest extends TestCase {

  public function testScannedBinaryAndLiteralTextAreNotPdfActions(): void {
    self::assertFalse($this->inspect($this->pdf("image bytes \x01 /JS /JavaScript <script ?>")));
    self::assertFalse($this->inspect($this->pdf('image bytes', '/Title (Published /JS notice)')));
  }

  public function testGenuineActiveActionsRemainRejected(): void {
    foreach ([
      '/JS (app.alert(1))',
      '/J#53 (app.alert(1))',
      '/OpenAction << /S /JavaScript >>',
      '/Launch << /F (dangerous.exe) >>',
      '/EmbeddedFile 3 0 R',
      '/Type /ObjStm',
      '/Encrypt 4 0 R',
    ] as $active) {
      self::assertTrue($this->inspect($this->pdf('image bytes', $active)), $active);
    }
  }

  public function testMalformedAndPolyglotPdfRemainRejected(): void {
    self::assertTrue($this->inspect('Not a PDF.'));
    self::assertTrue($this->inspect($this->pdf('image bytes') . '<?php echo 1;'));
    self::assertTrue($this->inspect(str_replace('endstream', 'endbroken', $this->pdf('image bytes'))));
  }

  private function inspect(string $bytes): bool {
    $filename = tempnam(sys_get_temp_dir(), 'reg-pdf-test-');
    self::assertNotFalse($filename);
    try {
      file_put_contents($filename, $bytes);
      return PdfUploadInspector::isUnsafe($filename);
    }
    finally {
      unlink($filename);
    }
  }

  private function pdf(string $stream, string $catalog = ''): string {
    return "%PDF-1.7\n1 0 obj\n<< /Type /Catalog $catalog >>\nendobj\n"
      . "2 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n"
      . $stream . "\nendstream\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
  }

}
