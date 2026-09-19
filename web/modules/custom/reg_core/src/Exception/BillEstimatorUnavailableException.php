<?php

namespace Drupal\reg_core\Exception;

/**
 * Raised when no single approved tariff schedule can be selected safely.
 */
final class BillEstimatorUnavailableException extends \RuntimeException {}
