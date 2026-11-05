<?php

namespace Drupal\reg_core\Plugin\Validation\Constraint;

use Drupal\media\MediaInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class PublicMediaConstraintValidator extends ConstraintValidator {

  public function validate(mixed $value, Constraint $constraint): void {
    if ($value instanceof MediaInterface) {
      foreach (\Drupal::service('reg_core.public_media_policy')->errors($value, \Drupal::currentUser()) as $message) {
        $this->context->buildViolation($message)->atPath('field_reg_public_asset')->addViolation();
      }
    }
  }

}
