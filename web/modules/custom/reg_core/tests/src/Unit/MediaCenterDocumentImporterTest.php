<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Migration\MediaCenterDocumentImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tests controlled legacy Media Center document normalization. */
final class MediaCenterDocumentImporterTest extends TestCase {

  private function importer(): MediaCenterDocumentImporter {
    return (new \ReflectionClass(MediaCenterDocumentImporter::class))->newInstanceWithoutConstructor();
  }

  #[DataProvider('languageCases')]
  public function testLanguage(string $text, string $language, bool $certain): void {
    self::assertSame([$language, $certain], $this->importer()->language($text));
  }

  public static function languageCases(): array {
    return [
      'English' => ['Press Release Upgrade TID English.pdf', 'en', TRUE],
      'Kinyarwanda' => ['ITANGAZO RIGENEWE ITANGAZAMAKURU', 'rw', TRUE],
      'French' => ['Rapport French version', 'fr', TRUE],
      'uncertain' => ['REG document 2024', 'en', FALSE],
    ];
  }

  #[DataProvider('categoryCases')]
  public function testCategory(string $type, string $url, string $title, string $expected): void {
    self::assertSame($expected, $this->importer()->category($type, $url, $title));
  }

  public static function categoryCases(): array {
    return [
      'press release' => ['press-releases', '/media-center/press-releases/', 'REG statement', 'press_release'],
      'safeguard' => ['publications', '/media-center/publications/category/esia/', 'Impact assessment', 'safeguard'],
      'report' => ['publications', '/media-center/publications/', 'Annual Report 2024', 'report'],
      'generic publication' => ['publications', '/media-center/publications/', 'Customer information', 'publication'],
    ];
  }

  public function testLegacyCategoryContributesToMapping(): void {
    self::assertSame('safeguard', $this->importer()->category(
      'publications',
      '/media-center/publications/',
      'Neutral document title',
      'RAP/ARAP',
    ));
  }

  public function testOriginalDateParsing(): void {
    $importer = $this->importer();
    self::assertSame('2024-07-18', $importer->dateFromText('Published 18 July 2024'));
    self::assertSame('2023-12-05', $importer->dateFromText('2023-12-05'));
    self::assertNull($importer->dateFromText('No original date supplied'));
  }

  #[DataProvider('sourceCases')]
  public function testSourceAllowlist(string $url, string $type, bool $asset, bool $expected): void {
    self::assertSame($expected, $this->importer()->allowed($url, $type, $asset));
  }

  public static function sourceCases(): array {
    return [
      'press listing' => ['https://www.reg.rw/media-center/press-releases/', 'press-releases', FALSE, TRUE],
      'publication category' => ['https://www.reg.rw/media-center/publications/category/esia/', 'publications', FALSE, TRUE],
      'detail' => ['https://www.reg.rw/media-center/details/news/example/', 'press-releases', FALSE, TRUE],
      'PDF' => ['https://www.reg.rw/fileadmin/user_upload/example.pdf', 'publications', TRUE, TRUE],
      'image excluded' => ['https://www.reg.rw/fileadmin/user_upload/example.jpg', 'publications', TRUE, FALSE],
      'announcements excluded' => ['https://www.reg.rw/media-center/announcements/', 'publications', FALSE, FALSE],
      'newsletters excluded' => ['https://www.reg.rw/media-center/newsletter/', 'press-releases', FALSE, FALSE],
      'company laws excluded' => ['https://www.reg.rw/media-center/company-laws/', 'publications', FALSE, FALSE],
      'external excluded' => ['https://example.com/media-center/publications/', 'publications', FALSE, FALSE],
    ];
  }

}
