<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\reg_core\Social\SocialPostRepository;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** Tests approval/access and official-account guards without publishing fixtures. */
#[CoversClass(SocialPostRepository::class)]
final class SocialPostRepositoryTest extends TestCase {

  public function testLatestPostRejectsDraftDeniedAndUnrelatedAccount(): void {
    $records = [
      1 => $this->post(1, 'draft', TRUE, 'https://www.tiktok.com/@reg_rwanda/video/1'),
      2 => $this->post(2, 'published', FALSE, 'https://www.tiktok.com/@reg_rwanda/video/2'),
      3 => $this->post(3, 'published', TRUE, 'https://www.tiktok.com/@unrelated/video/3'),
      4 => $this->post(4, 'published', TRUE, 'https://www.tiktok.com/@reg_rwanda/video/4'),
    ];
    $conditions = [];
    $sorts = [];
    $query = $this->createMock(QueryInterface::class);
    $query->expects(self::once())->method('accessCheck')->with(TRUE)->willReturnSelf();
    $query->method('condition')->willReturnCallback(static function (...$args) use (&$conditions, $query) { $conditions[] = $args; return $query; });
    $query->method('sort')->willReturnCallback(static function (...$args) use (&$sorts, $query) { $sorts[] = $args; return $query; });
    $query->method('range')->willReturnSelf();
    $query->method('execute')->willReturn(array_keys($records));
    $storage = $this->createMock(NodeStorageInterface::class);
    $storage->method('getQuery')->willReturn($query);
    $storage->method('loadMultiple')->willReturn($records);
    $manager = $this->createMock(EntityTypeManagerInterface::class);
    $manager->method('getStorage')->with('node')->willReturn($storage);
    $language = $this->createMock(LanguageInterface::class);
    $language->method('getId')->willReturn('en');
    $languages = $this->createMock(LanguageManagerInterface::class);
    $languages->method('getCurrentLanguage')->willReturn($language);
    $dates = $this->createMock(DateFormatterInterface::class);
    $dates->method('format')->willReturn('03 Nov 2026');
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('platforms.tiktok.url')->willReturn('https://www.tiktok.com/@reg_rwanda');
    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('reg_core.social_media')->willReturn($config);
    $repository = new SocialPostRepository($manager, $languages, $dates, $factory);
    $result = $repository->latestHomepagePost('tiktok');
    self::assertSame(4, $result['item']['id']);
    self::assertSame('[unit fixture only]', $result['item']['text']);
    self::assertContains('node:4', $result['cache_tags']);
    $conditions = array_map(static fn($c) => array_values($c), $conditions);
    $pairs = array_map(static fn($c) => array_slice($c, 0, 2), $conditions);
    self::assertContains(['status', NodeInterface::PUBLISHED], $pairs);
    self::assertContains(['field_reg_social_homepage', 1], $pairs);
    self::assertContains(['field_reg_social_platform', 'tiktok'], $pairs);
    self::assertSame([['field_reg_social_published_at', 'DESC'], ['nid', 'DESC']], array_map(static fn($c) => array_slice($c, 0, 2), $sorts));
    self::assertSame('<=', array_values(array_filter($conditions, static fn($c) => $c[0] === 'field_reg_social_published_at'))[0][2]);
  }

  private function post(int $id, string $state, bool $access, string $url): NodeInterface {
    $values = [
      'moderation_state' => $state,
      'field_reg_social_platform' => 'tiktok',
      'field_reg_social_post_url' => $url,
      'field_reg_social_post_text' => '[unit fixture only]',
      'field_reg_social_published_at' => '2026-11-03T10:00:00',
      'field_reg_social_source' => 'manual',
    ];
    $fields = [];
    foreach ($values as $key => $value) {
      $field = $this->createMock(FieldItemListInterface::class);
      $field->method('isEmpty')->willReturn(FALSE);
      $field->method('__get')->willReturn($value);
      $fields[$key] = $field;
    }
    $node = $this->createMock(NodeInterface::class);
    $node->method('id')->willReturn($id);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('access')->willReturn($access);
    $node->method('hasTranslation')->willReturn(FALSE);
    $node->method('hasField')->willReturnCallback(static fn($name) => isset($fields[$name]));
    $node->method('get')->willReturnCallback(static fn($name) => $fields[$name]);
    $node->method('getCacheTags')->willReturn(['node:' . $id]);
    return $node;
  }

}
