<?php

namespace Drupal\reg_core\Procurement;

/**
 * Shared rules for public CTAs and the existing supplier bid workflow. */
final class TenderSubmissionPolicy {

  /**
   * Requires both procurement enablement and explicit security approval.
   */
  public static function plansEnabled(): bool {
    return (bool) \Drupal::config('reg_core.settings')->get('public_information.procurement_plan_integration');
  }

  public static function onlineEnabled(): bool {
    $config = \Drupal::config('reg_core.settings');
    return (bool) $config->get('public_information.reg_online_bidding')
      && (bool) $config->get('public_information.enable_tender_submission')
      && (bool) $config->get('public_information.tender_security_approved');
  }

  /**
   * Validates an approved tender-specific HTTPS destination, never a portal root. */
  public static function umucyoUrl(string $url): bool {
    $parts = parse_url($url);
    return $parts && ($parts['scheme'] ?? '') === 'https'
      && in_array(strtolower($parts['host'] ?? ''), ['umucyo.gov.rw', 'www.umucyo.gov.rw'], TRUE)
      && !isset($parts['user']) && !isset($parts['pass'])
      && (!isset($parts['port']) || $parts['port'] === 443)
      && (trim($parts['path'] ?? '', '/') !== '' || !empty($parts['query']));
  }

}
