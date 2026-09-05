<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\FileRepositoryInterface;
use Drupal\reg_core\Migration\MediaCenterLegacyImporter;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

  public function testUnknownDateGetsDateReviewPriority(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'primaryReviewStatus');

    self::assertSame(
      'needs_date',
      $method->invoke($importer, ['needs_date', 'needs_language', 'needs_classification']),
    );
  }

  public function testOriginalDateExtraction(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'dateFromText');

    self::assertSame('2019-12-05', $method->invoke($importer, 'Kigali, December 05, 2019'));
    self::assertSame('2018-10-18', $method->invoke($importer, 'On the 18th October 2018'));
    self::assertSame('2018-10-17', $method->invoke($importer, '17 Oct 2018'));
    self::assertSame('2024-03-11', $method->invoke($importer, 'kuri uyu wa 11 Werurwe 2024'));
    self::assertNull($method->invoke($importer, '2026-02-31'));
    self::assertNull($method->invoke($importer, '03/04/2026'));
    self::assertNull($method->invoke($importer, 'Imported today without an original date'));
  }

  public function testValidLegacyDateIsPreserved(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $dom = new \DOMDocument();
    $dom->loadHTML('<div class="article"><div class="event_img_date">06<br>Aug</div></div>');
    $xpath = new \DOMXPath($dom);
    $method = new \ReflectionMethod($importer, 'legacyArticleDate');

    self::assertSame('2026-08-06', $method->invoke($importer, $xpath, 'Published 6 August 2026.', NULL));
  }

  public function testMissingStrongerMetadataDoesNotEraseExistingDate(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $dom = new \DOMDocument();
    $dom->loadHTML('<div class="article"><p>No publication metadata.</p></div>');
    $method = new \ReflectionMethod($importer, 'articleDateEvidence');

    $result = $method->invoke($importer, new \DOMXPath($dom), 'Legacy article', 'https://www.reg.rw/example', '2024-03-11', '', NULL, '');
    self::assertSame('2024-03-11', $result['parsed_date']);
    self::assertSame('existing_valid_date', $result['date_source']);
  }

  public function testArticleBadgeUsesExactArchiveYear(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $dom = new \DOMDocument();
    $dom->loadHTML('<div class="article"><div class="event_img_date">17<br>Oct</div></div>');
    $xpath = new \DOMXPath($dom);
    $method = new \ReflectionMethod($importer, 'articleDateEvidence');

    $result = $method->invoke($importer, $xpath, 'Archived article', 'https://www.reg.rw/example', NULL, '', [
      'raw' => '17 Oct',
      'date' => NULL,
      'year' => 2018,
      'month' => 10,
      'listing_url' => 'https://www.reg.rw/media-center/archive-news/archive/10/2018/',
    ], '');
    self::assertSame('17Oct + archive year 2018', $result['raw_date']);
    self::assertSame('2018-10-17', $result['parsed_date']);
    self::assertSame('article_heading_date_with_archive', $result['date_source']);
  }

  public function testStructuredArticleDateHasPriority(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $dom = new \DOMDocument();
    $dom->loadHTML('<script type="application/ld+json">{"@type":"NewsArticle","datePublished":"2026-08-06"}</script><div class="article"><div class="event_img_date">05 Aug 2025</div></div>');
    $xpath = new \DOMXPath($dom);
    $method = new \ReflectionMethod($importer, 'articleDateEvidence');

    $result = $method->invoke($importer, $xpath, 'Metadata article', 'https://www.reg.rw/example', NULL, 'On 18 October 2018 the project started.', NULL, '');
    self::assertSame('2026-08-06', $result['parsed_date']);
    self::assertSame('structured_data', $result['date_source']);
  }

  #[DataProvider('recentLegacyDateCases')]
  public function testRecentLegacyDatesCanBeParsed(string $raw, string $expected): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'dateFromText');

    self::assertSame($expected, $method->invoke($importer, $raw));
  }

  public static function recentLegacyDateCases(): array {
    return [
      ['6 August 2023', '2023-08-06'],
      ['06 Aug 2024', '2024-08-06'],
      ['August 6, 2025', '2025-08-06'],
      ['2026-08-06', '2026-08-06'],
    ];
  }

  public function testUnavailableDateRemainsNull(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $dom = new \DOMDocument();
    $dom->loadHTML('<div class="article"><div class="event_img_date">06<br>Aug</div></div>');
    $method = new \ReflectionMethod($importer, 'legacyArticleDate');

    self::assertNull($method->invoke($importer, new \DOMXPath($dom), 'No publication date is available.', NULL));
  }
  public function testUnconfirmedOldDateNeedsReview(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'suspiciousPublicationDate');

    self::assertTrue($method->invoke($importer, ['parsed_date' => '1994-06-01', 'date_source' => 'existing_valid_date']));
    self::assertFalse($method->invoke($importer, ['parsed_date' => '1994-06-01', 'date_source' => 'structured_data']));
    self::assertFalse($method->invoke($importer, ['parsed_date' => '1994-06-01', 'date_source' => 'existing_valid_date', 'trusted_date_confirmation' => TRUE]));
    self::assertFalse($method->invoke($importer, ['parsed_date' => '2014-06-01', 'date_source' => 'existing_valid_date']));
  }

  public function testSuspiciousDateIsRemovedAndReportedForReview(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $dom = new \DOMDocument();
    $dom->loadHTML('<div class=article><div class=event_img_date>01 Jun</div></div>');
    $method = new \ReflectionMethod($importer, 'newsRecord');

    $record = $method->invoke(
      $importer,
      ['source_url' => 'https://www.reg.rw/media-center/news-details/news/old-record/'],
      new \DOMXPath($dom),
      'Legacy record',
      '<p>Published 1 June 1994.</p>',
      'Published 1 June 1994.',
    );

    self::assertNull($record['date']);
    self::assertContains('needs_date', $record['issues']);
    self::assertSame('suspicious', $record['date_diagnostic']['confidence']);
    self::assertSame('yes', $record['date_diagnostic']['needs_review']);
  }
  #[DataProvider('sourceScopeCases')]
  public function testSourceScope(string $url, bool $asset, bool $allowed): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'allowed');

    self::assertSame($allowed, $method->invoke($importer, $url, $asset));
  }

  public static function sourceScopeCases(): array {
    return [
      'homepage' => ['https://www.reg.rw/', FALSE, TRUE],
      'HTML sitemap' => ['https://www.reg.rw/sitemap/', FALSE, TRUE],
      'news listing' => ['https://www.reg.rw/media-center/news-events/', FALSE, TRUE],
      'news pagination' => ['https://www.reg.rw/media-center/news-events/?tx_news_pi1%5BcurrentPage%5D=2', FALSE, TRUE],
      'news category listing' => ['https://www.reg.rw/media-center/news-events-by-category/category/distribution/', FALSE, TRUE],
      'news month archive' => ['https://www.reg.rw/media-center/archive-news/archive/08/2026/', FALSE, TRUE],
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

  public function testDiscoveryKeyPreservesPaginationButDropsCacheHash(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'discoveryKey');

    self::assertSame(
      'https://www.reg.rw/media-center/news-events/?tx_news_pi1%5BcurrentPage%5D=2',
      $method->invoke(
        $importer,
        'https://www.reg.rw/media-center/news-events/?tx_news_pi1%5BcurrentPage%5D=2&cHash=abc123',
      ),
    );
  }

  public function testYearCoverageIncludesUnknownDates(): void {
    $importer = (new \ReflectionClass(MediaCenterLegacyImporter::class))->newInstanceWithoutConstructor();
    $method = new \ReflectionMethod($importer, 'yearCoverage');

    $coverage = $method->invoke($importer, [
      ['date' => '2026-08-06'],
      ['date' => NULL],
      ['date' => '2025-12-01'],
      ['date' => '2026-05-18'],
    ]);
    self::assertSame(0, $coverage[2014]);
    self::assertSame(1, $coverage[2025]);
    self::assertSame(2, $coverage[2026]);
    self::assertSame(1, $coverage['unknown']);
  }


  public function testTransientFetchFailureThenSuccess(): void {
    $url = 'https://www.reg.rw/media-center/news-details/news/example/';
    $calls = 0;
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(static function () use (&$calls, $url): Response {
      $calls++;
      if ($calls === 1) {
        throw new ConnectException('cURL error 35: TLS connect error / unexpected EOF while reading', new Request('GET', $url), NULL, ['errno' => 35]);
      }
      return new Response(200, [], '<html>ok</html>');
    });

    $report = ['source_failures' => []];
    self::assertSame('<html>ok</html>', $this->invokeFetch($this->importerWithClient($client), $url, $report));
    self::assertSame(2, $calls);
    self::assertSame([], $report['source_failures']);
  }

  public function testTransientFetchFailureExhausted(): void {
    $url = 'https://www.reg.rw/media-center/news-details/news/example/';
    $calls = 0;
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(static function () use (&$calls, $url): never {
      $calls++;
      throw new ConnectException('cURL error 35: TLS connect error / unexpected EOF while reading', new Request('GET', $url), NULL, ['errno' => 35]);
    });

    $report = ['source_failures' => []];
    self::assertNull($this->invokeFetch($this->importerWithClient($client), $url, $report));
    self::assertSame(3, $calls);
    self::assertCount(1, $report['source_failures']);
  }

  public function testNotFoundFetchIsNotRetried(): void {
    $url = 'https://www.reg.rw/media-center/news-details/news/missing/';
    $calls = 0;
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(static function () use (&$calls, $url): never {
      $calls++;
      throw RequestException::create(new Request('GET', $url), new Response(404));
    });

    $report = ['source_failures' => []];
    self::assertNull($this->invokeFetch($this->importerWithClient($client), $url, $report));
    self::assertSame(1, $calls);
    self::assertCount(1, $report['source_failures']);
  }

  private function importerWithClient(ClientInterface $client): MediaCenterLegacyImporter {
    return new MediaCenterLegacyImporter(
      $client,
      $this->createStub(EntityTypeManagerInterface::class),
      $this->createStub(FileRepositoryInterface::class),
      $this->createStub(FileSystemInterface::class),
      $this->createStub(TimeInterface::class),
      $this->createStub(LoggerInterface::class),
    );
  }

  private function invokeFetch(MediaCenterLegacyImporter $importer, string $url, array &$report): ?string {
    $method = new \ReflectionMethod($importer, 'fetch');
    $arguments = [$url, &$report];
    return $method->invokeArgs($importer, $arguments);
  }

}
