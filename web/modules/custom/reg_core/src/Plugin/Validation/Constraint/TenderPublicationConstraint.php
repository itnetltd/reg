<?php

namespace Drupal\reg_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Requires an explicit tender lifecycle when publishing.
 */
#[Constraint(id: 'RegTenderPublication', label: new TranslatableMarkup('REG tender publication'), type: 'string')]
class TenderPublicationConstraint extends SymfonyConstraint {

  public string $message = 'Select a tender status before publishing. Choose Open, Closed, Evaluation, Awarded or Cancelled as appropriate.';

}
