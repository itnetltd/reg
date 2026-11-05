<?php

namespace Drupal\reg_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(id: 'RegPublicFile', label: new TranslatableMarkup('REG public upload validation'))]
class PublicFileConstraint extends SymfonyConstraint {}
