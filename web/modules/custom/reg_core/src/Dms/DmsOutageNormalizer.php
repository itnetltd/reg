<?php

namespace Drupal\reg_core\Dms;

/**
 * Converts external DMS payloads into a strict public outage model.
 */
final class DmsOutageNormalizer {

  /**
   * Normalizes a DMS response containing one or more outages.
   *
   * Only explicitly approved public properties are returned. Internal asset,
   * feeder, switching, crew, topology, and network fields are discarded.
   *
   * @return array<int, array<string, mixed>>
   *   Normalized public outage records.
   */
  public function normalizeList(array $payload): array {
    $outages = [];
    foreach ($this->extractRecords($payload) as $record) {
      if (!is_array($record)) {
        continue;
      }
      $normalized = $this->normalize($record);
      if ($normalized['outage_id'] !== '') {
        $outages[] = $normalized;
      }
    }
    return $outages;
  }

  /**
   * Normalizes one external record to the stable public field contract.
   */
  public function normalize(array $record): array {
    $geojson = $this->geoJson($record);
    $latitude = $this->firstNumeric($record, ['latitude', 'publicLocation.latitude']);
    $longitude = $this->firstNumeric($record, ['longitude', 'publicLocation.longitude']);
    if (($geojson['type'] ?? '') === 'Point' && count($geojson['coordinates'] ?? []) >= 2) {
      $longitude ??= (float) $geojson['coordinates'][0];
      $latitude ??= (float) $geojson['coordinates'][1];
    }
    if ($latitude !== NULL && abs($latitude) > 90) {
      $latitude = NULL;
    }
    if ($longitude !== NULL && abs($longitude) > 180) {
      $longitude = NULL;
    }

    return [
      'outage_id' => $this->text($record, ['outage_id', 'outageId', 'publicOutageId', 'eventId', 'id']),
      'outage_type' => $this->machineValue($record, ['outage_type', 'outageType', 'publicType', 'type']),
      'status' => $this->machineValue($record, ['status', 'outageStatus', 'publicStatus']),
      'start_time' => $this->date($record, ['start_time', 'startTime', 'publicStartTime']),
      'expected_restoration' => $this->date($record, ['expected_restoration', 'expectedRestoration', 'estimatedRestorationTime']),
      'actual_restoration' => $this->date($record, ['actual_restoration', 'actualRestoration', 'restoredAt']),
      'district' => $this->text($record, ['district', 'publicLocation.district']),
      'sector' => $this->text($record, ['sector', 'publicLocation.sector']),
      'affected_area' => $this->text($record, ['affected_area', 'affectedArea', 'publicAffectedArea']),
      'public_reason' => $this->text($record, ['public_reason', 'publicReason', 'publicCause']),
      'customers_affected' => $this->nonNegativeInteger($record, ['customers_affected', 'customersAffected', 'publicCustomersAffected']),
      'latitude' => $latitude,
      'longitude' => $longitude,
      'geojson' => $geojson,
      'last_updated' => $this->date($record, ['last_updated', 'lastUpdated', 'publicLastUpdated', 'updatedAt']),
    ];
  }

  /**
   * Extracts a conventional collection envelope or accepts a list payload.
   */
  private function extractRecords(array $payload): array {
    if (array_is_list($payload)) {
      return $payload;
    }
    foreach (['outages', 'items', 'data', 'results'] as $key) {
      if (!isset($payload[$key]) || !is_array($payload[$key])) {
        continue;
      }
      $value = $payload[$key];
      if (isset($value['items']) && is_array($value['items'])) {
        return $value['items'];
      }
      return array_is_list($value) ? $value : [$value];
    }
    return [];
  }

  /**
   * Reads and strips markup from the first available scalar value.
   */
  private function text(array $record, array $paths): string {
    foreach ($paths as $path) {
      $value = $this->value($record, $path);
      if (is_scalar($value) && trim((string) $value) !== '') {
        return trim(strip_tags((string) $value));
      }
    }
    return '';
  }

  /**
   * Normalizes an enum-like value for predictable filtering and CSS classes.
   */
  private function machineValue(array $record, array $paths): string {
    $value = mb_strtolower($this->text($record, $paths));
    return preg_replace('/[^a-z0-9_-]+/', '_', $value) ?: '';
  }

  /**
   * Normalizes an external timestamp to ISO-8601.
   */
  private function date(array $record, array $paths): ?string {
    $value = $this->text($record, $paths);
    if ($value === '') {
      return NULL;
    }
    try {
      return (new \DateTimeImmutable($value))->format(DATE_ATOM);
    }
    catch (\Exception) {
      return NULL;
    }
  }

  /**
   * Returns a public customer count without accepting free-form impact text.
   */
  private function nonNegativeInteger(array $record, array $paths): ?int {
    foreach ($paths as $path) {
      $value = $this->value($record, $path);
      if (filter_var($value, FILTER_VALIDATE_INT) !== FALSE && (int) $value >= 0) {
        return (int) $value;
      }
    }
    return NULL;
  }

  /**
   * Returns geometry only, intentionally dropping all GeoJSON properties.
   */
  private function geoJson(array $record): ?array {
    $value = $this->value($record, 'geojson') ?? $this->value($record, 'geoJson') ?? $this->value($record, 'publicGeometry');
    if (is_string($value)) {
      try {
        $value = json_decode($value, TRUE, 32, JSON_THROW_ON_ERROR);
      }
      catch (\JsonException) {
        return NULL;
      }
    }
    if (!is_array($value) || !is_string($value['type'] ?? NULL)) {
      return NULL;
    }
    if (($value['type'] ?? '') === 'Feature' && is_array($value['geometry'] ?? NULL)) {
      $value = $value['geometry'];
    }
    $allowed = ['Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon', 'MultiPolygon'];
    $coordinates = $value['coordinates'] ?? NULL;
    if (!in_array($value['type'] ?? '', $allowed, TRUE) || !is_array($coordinates)) {
      return NULL;
    }
    $coordinate_count = 0;
    if (!$this->validCoordinates($coordinates, 0, $coordinate_count)) {
      return NULL;
    }
    return [
      'type' => $value['type'],
      'coordinates' => $coordinates,
    ];
  }

  /**
   * Validates bounded, list-only numeric GeoJSON coordinate structures.
   */
  private function validCoordinates(array $coordinates, int $depth, int &$coordinate_count): bool {
    if ($depth > 6 || !array_is_list($coordinates)) {
      return FALSE;
    }
    foreach ($coordinates as $value) {
      if (is_array($value)) {
        if (!$this->validCoordinates($value, $depth + 1, $coordinate_count)) {
          return FALSE;
        }
        continue;
      }
      if (!is_numeric($value) || ++$coordinate_count > 20000) {
        return FALSE;
      }
    }
    return $coordinate_count > 0;
  }

  /**
   * Reads a nested dot-delimited array path.
   */
  private function value(array $record, string $path): mixed {
    $value = $record;
    foreach (explode('.', $path) as $segment) {
      if (!is_array($value) || !array_key_exists($segment, $value)) {
        return NULL;
      }
      $value = $value[$segment];
    }
    return $value;
  }

  /**
   * Finds the first numeric value at approved public paths.
   */
  private function firstNumeric(array $record, array $paths): ?float {
    foreach ($paths as $path) {
      $value = $this->value($record, $path);
      if (is_numeric($value)) {
        return (float) $value;
      }
    }
    return NULL;
  }

}
