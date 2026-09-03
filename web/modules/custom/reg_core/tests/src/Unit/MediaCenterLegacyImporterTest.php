<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Migration\MediaCenterLegacyImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tests conservative legacy news classification. */
final class MediaCenterLegacyImporterTest extends TestCase {

  #[DataProvider('classificationCases')]
  public function testClassification(string $text, string $section, string $sport, bool $certain): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    self::assertSame([$section, $sport, $certain], $importer->classifyNews($text));
  }

  public static function classificationCases(): array {
    return [
      'volleyball' => ['REG Volleyball Club secured bronze in the championship final.', 'sports', 'Volleyball', TRUE],
      'basketball' => ['REG BBC beat UTB in the basketball tournament final.', 'sports', 'Basketball', TRUE],
      'corporate' => ['REG hosts an electricity project governance meeting in Kigali.', 'corporate', '', TRUE],
      'uncertain club' => ['REG Club announces a new initiative.', 'corporate', '', FALSE],
    ];
  }

}
