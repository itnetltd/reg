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
      'bilingual outage' => ["Ibura ry'amashanyarazi / Planned power outage", 'multi', TRUE],
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
      'archived announcement' => ['announcements', '/media-center/announcements/', 'Planned outage notice', 'archived_announcement'],
      'newsletter' => ['newsletters', '/media-center/newsletter/', 'REG Newsletter', 'newsletter'],
      'corporate legal' => ['company-laws', '/media-center/company-laws/', 'Law establishing REG', 'corporate_legal'],
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

  public function testNewsletterIssueNumber(): void {
    $importer = $this->importer();
    self::assertSame('23', $importer->issueNumber('REG Newsletter - Issue No 23'));
    self::assertSame('7A', $importer->issueNumber('Newsletter Issue #7A'));
    self::assertSame('', $importer->issueNumber('REG quarterly newsletter'));
  }

  public function testHistoricalOutageClassification(): void {
    $importer = $this->importer();
    self::assertTrue($importer->historicalOutage('Planned power outage on 08/05/2025'));
    self::assertTrue($importer->historicalOutage("Ibura ry'amashanyarazi riteganyijwe"));
    self::assertFalse($importer->historicalOutage('Invitation to a stakeholder meeting'));
  }

  public function testTypo3PaginationHashIsPreserved(): void {
    $method = new \ReflectionMethod(MediaCenterDocumentImporter::class, 'canonical');
    $url = $method->invoke(
      $this->importer(),
      'https://www.reg.rw/media-center/publications/?tx_news_pi1%5B%40widget_0%5D%5BcurrentPage%5D=2&cHash=abc123',
      TRUE,
    );
    self::assertStringContainsString('currentPage%5D=2', $url);
    self::assertStringContainsString('cHash=abc123', $url);
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
      'announcement listing' => ['https://www.reg.rw/media-center/announcements/', 'announcements', FALSE, TRUE],
      'announcement detail' => ['https://www.reg.rw/media-center/announcements/announcements-details/news/example/', 'announcements', FALSE, TRUE],
      'newsletter listing' => ['https://www.reg.rw/media-center/newsletter/', 'newsletters', FALSE, TRUE],
      'company law listing' => ['https://www.reg.rw/media-center/company-laws/', 'company-laws', FALSE, TRUE],
      'image excluded' => ['https://www.reg.rw/fileadmin/user_upload/example.jpg', 'publications', TRUE, FALSE],
      'announcements excluded' => ['https://www.reg.rw/media-center/announcements/', 'publications', FALSE, FALSE],
      'newsletters excluded' => ['https://www.reg.rw/media-center/newsletter/', 'press-releases', FALSE, FALSE],
      'company laws excluded' => ['https://www.reg.rw/media-center/company-laws/', 'publications', FALSE, FALSE],
      'external excluded' => ['https://example.com/media-center/publications/', 'publications', FALSE, FALSE],
    ];
  }

}
