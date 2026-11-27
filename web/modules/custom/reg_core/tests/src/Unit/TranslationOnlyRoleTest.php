<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Translation\TranslationOnlyRole;
use PHPUnit\Framework\TestCase;

final class TranslationOnlyRoleTest extends TestCase {

  public function testOnlyNonDefaultUnpublishedReviewTransitions(): void {
    $settings = [
      'states' => [
        'draft' => ['published' => FALSE, 'default_revision' => FALSE],
        'needs_review' => ['published' => FALSE, 'default_revision' => FALSE],
        'published' => ['published' => TRUE, 'default_revision' => TRUE],
        'archived' => ['published' => FALSE, 'default_revision' => TRUE],
      ],
      'transitions' => [
        'create_new_draft' => ['to' => 'draft'],
        'submit_for_review' => ['to' => 'needs_review'],
        'publish' => ['to' => 'published'],
        'archive' => ['to' => 'archived'],
      ],
    ];
    self::assertSame(['create_new_draft', 'submit_for_review'], TranslationOnlyRole::safeTransitions($settings));
    $settings['transitions']['submit_for_review']['to'] = 'published';
    self::assertSame(['create_new_draft'], TranslationOnlyRole::safeTransitions($settings));
  }

}
