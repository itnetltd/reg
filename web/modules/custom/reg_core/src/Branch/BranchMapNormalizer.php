<?php

namespace Drupal\reg_core\Branch;

/**
 * Limits map markers to approved public branch information.
 */
final class BranchMapNormalizer {

  /**
   * Converts public branch records to provider-neutral marker data.
   */
  public function normalize(array $branches): array {
    $markers = [];
    foreach ($branches as $branch) {
      if (!is_numeric($branch['latitude'] ?? NULL) || !is_numeric($branch['longitude'] ?? NULL)) {
        continue;
      }
      $markers[] = [
        'id' => (int) $branch['id'],
        'name' => (string) $branch['title'],
        'district' => (string) $branch['district'],
        'address' => (string) $branch['address'],
        'telephone' => (string) $branch['telephone'],
        'opening_hours' => (string) $branch['opening_hours'],
        'url' => (string) $branch['url'],
        'latitude' => (float) $branch['latitude'],
        'longitude' => (float) $branch['longitude'],
      ];
    }
    return $markers;
  }

}
