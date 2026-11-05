<?php

namespace Drupal\reg_core\Plugin\Validation\Constraint;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Procurement\TenderPublicationValidation;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Attaches publication errors to the visible tender status widget.
 */
class TenderPublicationConstraintValidator extends ConstraintValidator {

  public function validate(mixed $value, Constraint $constraint): void {
    if ($value instanceof FieldItemListInterface) {
      $node = $value->getEntity();
      if ($node instanceof NodeInterface && TenderPublicationValidation::invalid($node)) {
        $this->context->buildViolation($constraint->message)->addViolation();
      }
    }
  }

}
