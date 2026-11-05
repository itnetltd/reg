<?php

namespace Drupal\reg_core\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

#[Constraint(id: 'RegPublicMedia', label: new TranslatableMarkup('REG public Media policy'), type: 'entity:media')]
class PublicMediaConstraint extends SymfonyConstraint {}
