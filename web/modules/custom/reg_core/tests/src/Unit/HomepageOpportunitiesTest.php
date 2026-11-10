<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\node\NodeInterface;
use Drupal\reg_core\PublicInformation\PublicInformationRepository;
use Drupal\Tests\UnitTestCase;

/** Exercises selection and time boundaries without saving database fixtures. */
final class HomepageOpportunitiesTest extends UnitTestCase {

  private const NOW = 1800000000;

  private function node(int $id, string $bundle, int $posted, ?int $closes, string $status = 'active', bool $published = TRUE, bool $allowed = TRUE): NodeInterface {
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('isPublished')->willReturn($published);
    $node->method('hasTranslation')->willReturn(FALSE);
    $node->method('access')->willReturn($allowed ? AccessResult::allowed() : AccessResult::forbidden());
    $node->method('getCacheTags')->willReturn(['node:' . $id]);
    $node->method('getCacheContexts')->willReturn([]);
    $node->method('getCacheMaxAge')->willReturn(-1);
    $values = [
      'field_reg_closing_date' => $closes === NULL ? '' : gmdate('Y-m-d\TH:i:s', $closes),
      $bundle === 'reg_tender' ? 'field_reg_tender_status' : 'field_reg_job_status' => $status,
      $bundle === 'reg_tender' ? 'field_reg_issue_date' : 'field_reg_posting_date' => gmdate('Y-m-d\TH:i:s', $posted),
    ];
    $node->method('hasField')->willReturnCallback(static fn ($name) => array_key_exists($name, $values));
    $node->method('get')->willReturnCallback(function ($name) use ($values) {
      $value = $this->createMock(TypedDataInterface::class);
      $value->method('getValue')->willReturn($values[$name]);
      $item = $this->createMock(FieldItemInterface::class);
      $item->method('get')->with('value')->willReturn($value);
      $list = $this->createMock(FieldItemListInterface::class);
      $list->method('isEmpty')->willReturn($values[$name] === '');
      $list->method('first')->willReturn($item);
      return $list;
    });
    return $node;
  }

  private function select(array $nodes, string $bundle, int $limit, CacheableMetadata $cache): array {
    $query = $this->createMock(QueryInterface::class);
    $query->expects(self::once())->method('accessCheck')->with(TRUE)->willReturnSelf();
    $query->method('condition')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($nodes));
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($nodes);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getCurrentLanguage')->willReturn($language);
    $clock = $this->createMock(TimeInterface::class);
    $clock->method('getRequestTime')->willReturn(self::NOW);
    $repo = new PublicInformationRepository($manager, $languages, $this->createMock(DateFormatterInterface::class), $this->createMock(FileUrlGeneratorInterface::class), $this->createMock(CacheBackendInterface::class), $clock);
    return (new \ReflectionMethod($repo, 'openHomepageNodes'))->invoke($repo, $bundle, $limit, $cache);
  }

  public function testLatestThreeRealOpenTendersAndExpiry(): void {
    $nodes = [];
    foreach ([1, 2, 3, 4] as $id) $nodes[$id] = $this->node($id, 'reg_tender', self::NOW - 100 + $id, self::NOW + ($id === 4 ? 25 : 1000));
    $nodes[5] = $this->node(5, 'reg_tender', self::NOW, self::NOW - 1);
    $nodes[6] = $this->node(6, 'reg_tender', self::NOW, self::NOW + 1000, 'closed');
    $nodes[7] = $this->node(7, 'reg_tender', self::NOW, NULL);
    $nodes[8] = $this->node(8, 'reg_tender', self::NOW, self::NOW + 1000, 'active', FALSE);
    $nodes[9] = $this->node(9, 'reg_tender', self::NOW, self::NOW + 1000, 'active', TRUE, FALSE);
    $cache = (new CacheableMetadata())->setCacheMaxAge(300);
    self::assertSame([$nodes[4], $nodes[3], $nodes[2]], $this->select($nodes, 'reg_tender', 3, $cache));
    self::assertSame(25, $cache->getCacheMaxAge());
    self::assertContains('node:5', $cache->getCacheTags());
  }

  public function testLatestVacancyAndNoPaddingForScarceTenders(): void {
    $older = $this->node(1, 'reg_job', self::NOW - 20, self::NOW + 1000);
    $newer = $this->node(2, 'reg_job', self::NOW - 10, self::NOW + 1000);
    self::assertSame([$newer], $this->select([1 => $older, 2 => $newer], 'reg_job', 1, new CacheableMetadata()));
    $tender = $this->node(3, 'reg_tender', self::NOW - 10, self::NOW + 1000);
    self::assertSame([$tender], $this->select([3 => $tender], 'reg_tender', 3, new CacheableMetadata()));
  }

  public function testDeadlineBoundaryAndScheduledPublication(): void {
    $expired = $this->node(1, 'reg_job', self::NOW - 50, self::NOW);
    $future = $this->node(2, 'reg_job', self::NOW + 40, self::NOW + 1000);
    $cache = (new CacheableMetadata())->setCacheMaxAge(300);
    self::assertSame([], $this->select([1 => $expired, 2 => $future], 'reg_job', 1, $cache));
    self::assertSame(40, $cache->getCacheMaxAge());
    self::assertSame([], $this->select([], 'reg_tender', 3, new CacheableMetadata()));
  }

}