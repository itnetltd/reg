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

  public function testOriginalDateExtraction(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'dateFromText');

    self::assertSame('2019-12-05', $method->invoke($importer, 'Kigali, December 05, 2019'));
    self::assertSame('2018-10-18', $method->invoke($importer, 'On the 18th October 2018'));
    self::assertNull($method->invoke($importer, 'Imported today without an original date'));
  }

  #[DataProvider('sourceScopeCases')]
  public function testSourceScope(string $url, bool $asset, bool $allowed): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'allowed');

    self::assertSame($allowed, $method->invoke($importer, $url, $asset));
  }

  public static function sourceScopeCases(): array {
    return [
      'news listing' => ['https://www.reg.rw/media-center/news-events/', FALSE, TRUE],
      'news pagination' => ['https://www.reg.rw/media-center/news-events/?tx_news_pi1%5BcurrentPage%5D=2', FALSE, TRUE],
      'news detail' => ['https://www.reg.rw/media-center/news-details/news/example/', FALSE, TRUE],
      'news image' => ['https://www.reg.rw/fileadmin/user_upload/example.jpg', TRUE, TRUE],
      'press release excluded' => ['https://www.reg.rw/media-center/press-releases/', FALSE, FALSE],
      'publication excluded' => ['https://www.reg.rw/media-center/publications/', FALSE, FALSE],
      'announcement excluded' => ['https://www.reg.rw/media-center/announcements/', FALSE, FALSE],
      'newsletter excluded' => ['https://www.reg.rw/media-center/newsletter/', FALSE, FALSE],
      'company law excluded' => ['https://www.reg.rw/media-center/company-laws/', FALSE, FALSE],
      'external host excluded' => ['https://example.com/media-center/news-events/', FALSE, FALSE],
    ];
  }

}
