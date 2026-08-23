<?php

namespace Drupal\reg_core\Dms;

/**
 * Reads public outage data from the authoritative distribution system.
 */
interface DmsClientInterface {

  /**
   * Fetches the current raw outage payload.
   *
   * @return array<string|int, mixed>
   *   The decoded DMS response.
   *
   * @throws \Drupal\reg_core\Dms\DmsException
   *   When configuration, transport, or payload validation fails.
   */
  public function fetchOutages(): array;

  /**
   * Tests the configured endpoint without returning secrets or outage data.
   *
   * @return array{success: bool, status_code: int|null, message: string}
   *   A credential-safe connectivity result.
   */
  public function testConnectivity(): array;

}

