<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Translation\TranslationStatusRepository;
use PHPUnit\Framework\TestCase;

/** Checks status precedence for both directions of the bilingual worklist. */
final class TranslationWorkspaceTest extends TestCase {

  public function testMissingEnglishOrKinyarwandaTakesPriority(): void {
    self::assertSame('missing', TranslationStatusRepository::workspaceStatus(['missing', 'complete']));
    self::assertSame('missing', TranslationStatusRepository::workspaceStatus(['complete', 'missing']));
    self::assertSame('missing', TranslationStatusRepository::workspaceStatus(['missing', 'pending']));
  }

  public function testReviewOutdatedAndRestrictedAreNotComplete(): void {
    self::assertSame('pending', TranslationStatusRepository::workspaceStatus(['complete', 'pending']));
    self::assertSame('outdated', TranslationStatusRepository::workspaceStatus(['complete', 'outdated']));
    self::assertSame('restricted', TranslationStatusRepository::workspaceStatus(['complete', 'restricted']));
    self::assertSame('complete', TranslationStatusRepository::workspaceStatus(['complete', 'complete']));
  }

}
