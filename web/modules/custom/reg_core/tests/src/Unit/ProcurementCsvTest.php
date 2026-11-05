<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Procurement\ProcurementCsv;
use PHPUnit\Framework\TestCase;

/**
 * CSV edge cases; synthetic fixtures are not official procurement activities. */
final class ProcurementCsvTest extends TestCase {

  /**
   * Builds a clearly synthetic parser fixture.
   */
  private function row(): array {
    return array_replace(array_fill_keys(array_keys(ProcurementCsv::COLUMNS), ''), [
      'import_key' => 'QA-87',
      'activity_number' => '87',
      'title' => 'QA only',
      'category' => 'goods',
      'source_row' => '92',
    ]);
  }

  /**
   * Normalizes synthetic test rows using exact field headers.
   */
  private function normalize(array $rows): array {
    return ProcurementCsv::normalize([
      'rows' => $rows,
    ], array_combine(array_keys(ProcurementCsv::COLUMNS), array_keys(ProcurementCsv::COLUMNS)));
  }

  /**
   * Verifies missing values and review warnings.
   */
  public function testMissingValuesAndReviewWarnings(): void {
    $row = $this->normalize([$this->row()])[0];
    self::assertSame([], $row['errors']);
    foreach ([
      'estimated_budget',
      'fy_budget',
      'funding_source',
      'method_code',
      'document_prep',
      'department',
    ] as $key) {
      self::assertNull($row['data'][$key]);
    }
    self::assertStringContainsString('Activity 87', implode(' ', $row['warnings']));
    self::assertStringContainsString('Missing procurement method', implode(' ', $row['warnings']));
  }

  /**
   * Verifies source values and explicit zero are preserved.
   */
  public function testSourceValuesAndExplicitZeroArePreserved(): void {
    foreach (['REG', 'EUCL', 'REG/EUCL', 'EUCL/REG'] as $funding) {
      $raw = array_replace($this->row(), [
        'funding_source' => $funding,
        'method_code' => 'NCB',
        'estimated_budget' => '0',
        'publication' => '2026-02-28',
      ]);
      $row = $this->normalize([$raw])[0];
      self::assertSame([], $row['errors']);
      self::assertSame($funding, $row['data']['funding_source']);
      self::assertSame('NCB', $row['data']['method_code']);
      self::assertSame('0', $row['data']['estimated_budget']);
      self::assertSame('2026-02-28', $row['data']['publication']);
    }
  }

  /**
   * Verifies invalid dates budgets and duplicates are blocked.
   */
  public function testInvalidDatesBudgetsAndDuplicatesAreBlocked(): void {
    $raw = array_replace($this->row(), [
      'publication' => '2026-02-30',
      'fy_budget' => '1,000',
      'evaluation_start' => '2026-03-03',
      'evaluation_end' => '2026-03-02',
    ]);
    $rows = $this->normalize([$raw, $raw]);
    self::assertStringContainsString('Invalid ISO date', implode(' ', $rows[0]['errors']));
    self::assertStringContainsString('Invalid decimal', implode(' ', $rows[0]['errors']));
    self::assertStringContainsString('precedes', implode(' ', $rows[0]['errors']));
    foreach ($rows as $row) {
      self::assertStringContainsString('Duplicate import key', implode(' ', $row['errors']));
    }
  }

  /**
   * Verifies missing department is not inferred from hr subtotal.
   */
  public function testMissingDepartmentIsNotInferredFromHrSubtotal(): void {
    foreach ([100, 101, 102, 103] as $number) {
      $row = $this->normalize([array_replace($this->row(), ['activity_number' => (string) $number])])[0];
      self::assertNull($row['data']['department']);
      self::assertStringContainsString('HR subtotal is not confirmation', implode(' ', $row['warnings']));
    }
  }

  /**
   * Verifies explicit category mapping retains original label.
   */
  public function testExplicitCategoryMappingRetainsOriginalLabel(): void {
    $raw = array_replace($this->row(), ['category' => 'Consultancy Services']);
    $row = ProcurementCsv::normalize([
      'rows' => [
        $raw,
      ],
    ], array_combine(array_keys(ProcurementCsv::COLUMNS), array_keys(ProcurementCsv::COLUMNS)), [
      'Consultancy Services' => 'consultancy',
    ])[0];
    self::assertSame('consultancy', $row['data']['category']);
    self::assertSame('Consultancy Services', $row['data']['source_category']);
  }

  /**
   * Verifies bom quoted multiline and malformed column count.
   */
  public function testBomQuotedMultilineAndMalformedColumnCount(): void {
    $path = tempnam(sys_get_temp_dir(), 'reg-csv');
    try {
      file_put_contents($path, "\xEF\xBB\xBFkey,title\r\nQA,\"Two\nlines\"\r\n");
      $csv = ProcurementCsv::read($path);
      self::assertSame(['key', 'title'], $csv['headers']);
      self::assertSame("Two\nlines", $csv['rows'][0]['title']);
      file_put_contents($path, "key,title\nQA,Title,Extra\n");
      $this->expectException(\RuntimeException::class);
      ProcurementCsv::read($path);
    }
    finally {unlink($path);
    }
  }

  /**
   * Preserves a title exceeding Drupal's shared node label storage. */
  public function testLongTitleIsRetainedInExistingDescription(): void {
    $title = str_repeat('Long source activity ', 20);
    $row = $this->normalize([array_replace($this->row(), ['title' => $title])])[0];
    self::assertSame([], $row['errors']);
    self::assertSame(trim($title), $row['data']['full_title']);
    self::assertSame(255, mb_strlen($row['data']['title']));
    self::assertStringContainsString('full title preserved', implode(' ', $row['warnings']));
  }

  /**
   * A cross-plan source is rejected rather than silently assigned to EUCL. */
  public function testSourcePlanIdentityMustMatch(): void {
    $row = $this->normalize([array_replace($this->row(), ['source_plan_key' => 'OTHER-PLAN'])])[0];
    self::assertStringContainsString('identity mismatch', implode(' ', $row['errors']));
  }

}
