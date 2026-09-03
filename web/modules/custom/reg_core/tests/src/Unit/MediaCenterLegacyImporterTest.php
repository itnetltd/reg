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
      'basketball without team name' => ['Basketball players prepare for the new season.', 'sports', 'Basketball', TRUE],
      'Kinyarwanda volleyball' => ['REG VC yegukanye igikombe cya shampiyona.', 'sports', 'Volleyball', TRUE],
      'generic sports result' => ['The players won the national championship final.', 'sports', '', TRUE],
      'corporate' => ['REG hosts an electricity project governance meeting in Kigali.', 'corporate', '', TRUE],
      'uncertain club' => ['REG Club announces a new initiative.', 'corporate', '', FALSE],
      'uncertain match' => ['The financing match is under review.', 'corporate', '', FALSE],
    ];
  }

  #[DataProvider('languageCases')]
  public function testLanguageDetection(string $text, string $language, bool $certain): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'language');

    self::assertSame([$language, $certain], $method->invoke($importer, $text));
  }

  public static function languageCases(): array {
    return [
      'English' => ['REG Basketball Club signed new players and coaches.', 'en', TRUE],
      'Kinyarwanda' => ['REG BBC yegukanye igikombe, abakinnyi n\'umutoza barishima.', 'rw', TRUE],
      'uncertain' => ['REG launches Nyabihu initiative.', 'en', FALSE],
    ];
  }

  public function testHighConfidenceTranslationCounterparts(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'likelyPair');

    self::assertTrue($method->invoke(
      $importer,
      'REG hosts EAPP governance meeting',
      'REG yakiriye inama y\'imiyoborere ya EAPP',
    ));
    self::assertTrue($method->invoke(
      $importer,
      'REG commissions 80 MW power plant',
      'REG yatangije uruganda rw\'amashanyarazi rwa 80MW',
    ));
    self::assertFalse($method->invoke(
      $importer,
      'REG launches a new electricity project',
      'REG BBC yegukanye igikombe cya shampiyona',
    ));
  }

  public function testClassificationReviewTakesPriority(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'primaryReviewStatus');

    self::assertSame(
      'needs_classification',
      $method->invoke($importer, ['needs_date', 'needs_language', 'needs_classification']),
    );
  }

  public function testOriginalDateExtraction(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'dateFromText');

    self::assertSame('2019-12-05', $method->invoke($importer, 'Kigali, December 05, 2019'));
    self::assertSame('2018-10-18', $method->invoke($importer, 'On the 18th October 2018'));
    self::assertNull($method->invoke($importer, 'Imported today without an original date'));
  }

  public function testLegacyDateBadgeOverridesBodyDay(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $dom = new \DOMDocument();
    $dom->loadHTML('<div class=event_img_date>17<br>Oct</div>');
    $xpath = new \DOMXPath($dom);
    $method = new \ReflectionMethod($importer, 'articleDate');

    self::assertSame(
      '2018-10-17',
      $method->invoke($importer, $xpath, 'On the 18th October 2018, the project was inaugurated yesterday.', NULL),
    );
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
