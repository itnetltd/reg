<?php

namespace Drupal\reg_core\Plugin\Validation\Constraint;

use Drupal\file\Plugin\Validation\Constraint\BaseFileConstraintValidator;
use Drupal\reg_core\Media\UploadPolicy;
use Symfony\Component\Validator\Constraint;

class PublicFileConstraintValidator extends BaseFileConstraintValidator {

  public function validate(mixed $value, Constraint $constraint): void {
    $file = $this->assertValueIsFile($value);
    foreach (UploadPolicy::errors($file->getFileUri(), $file->getFilename()) as $message) {
      $this->context->addViolation($message);
    }
  }

}
