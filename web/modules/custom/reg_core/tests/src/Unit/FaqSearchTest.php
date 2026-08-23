<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Service\FaqSearch;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests deterministic FAQ relevance weighting.
 */
#[CoversClass(FaqSearch::class)]
#[Group('reg_core')]
final class FaqSearchTest extends UnitTestCase {

  /**
   * Confirms question and keyword matches outrank body-only matches.
   */
  public function testQuestionAndKeywordRanking(): void {
    $question = FaqSearch::calculateScore([
      'question' => 'How do I check a power outage?',
      'keywords' => '',
      'category' => 'outages',
      'category_label' => 'Power Outages',
      'answer' => 'Use the outage center.',
    ], 'power outage');
    $keyword = FaqSearch::calculateScore([
      'question' => 'Where can I get help?',
      'keywords' => 'power outage interruption',
      'category' => 'complaints',
      'category_label' => 'Faults and Complaints',
      'answer' => 'Contact REG.',
    ], 'power outage');
    $body = FaqSearch::calculateScore([
      'question' => 'Where can I get help?',
      'keywords' => '',
      'category' => 'complaints',
      'category_label' => 'Faults and Complaints',
      'answer' => 'You can check a power outage in the Outage Center.',
    ], 'power outage');

    self::assertGreaterThan($keyword, $question);
    self::assertGreaterThan($body, $keyword);
    self::assertSame(0, FaqSearch::calculateScore([
      'question' => 'How to apply for a job',
      'keywords' => 'recruitment',
      'category' => 'jobs',
      'category_label' => 'Jobs',
      'answer' => 'Open recruitment.',
    ], 'transformer fault'));
  }

}
