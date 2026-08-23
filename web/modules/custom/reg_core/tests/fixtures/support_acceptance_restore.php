<?php

/**
 * @file
 * Restores safe customer-support defaults after routing acceptance tests.
 */

\Drupal::configFactory()->getEditable('reg_core.settings')
  ->set('support.complaint_mode', 'external')
  ->set('support.complaint_url', '')
  ->set('support.complaint_integration_enabled', FALSE)
  ->save(TRUE);
print "Customer-support acceptance configuration restored.\n";
