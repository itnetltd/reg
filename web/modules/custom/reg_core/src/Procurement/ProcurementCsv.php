<?php

namespace Drupal\reg_core\Procurement;

/**
 * Strict CSV reader. Column mapping is reviewed in the preview form. */
final class ProcurementCsv {

  public const COLUMNS = [
    'import_key' => 'Stable import key',
    'activity_number' => 'Original activity number',
    'department' => 'Department',
    'category' => 'Procurement category (goods, non_consultancy, consultancy, works)',
    'title' => 'Activity title',
    'estimated_budget' => 'Estimated budget',
    'fy_budget' => 'Budget for financial year',
    'funding_source' => 'Source of funds',
    'method_code' => 'Source method code',
    'document_prep' => 'Document preparation date',
    'publication' => 'Publication date',
    'bid_opening' => 'Bid opening date',
    'evaluation_start' => 'Evaluation start date',
    'evaluation_end' => 'Evaluation end date',
    'provisional_notice' => 'Provisional notification date',
    'contract_signing' => 'Contract-signing date',
    'supervising_firm' => 'Supervising firm requirement',
    'prior_study' => 'Prior-study information',
    'source_row' => 'Original spreadsheet row number',
  ];

  public const SOURCE_HEADERS = [
    'import_key' => 'import_key',
    'activity_number' => 'source_item_number',
    'department' => 'department_as_in_sheet',
    'category' => 'category_as_in_sheet',
    'title' => 'activity_title',
    'estimated_budget' => 'estimated_cost_total_rwf',
    'fy_budget' => 'estimated_cost_fy_rwf',
    'funding_source' => 'source_of_funds',
    'method_code' => 'procurement_method',
    'document_prep' => 'planned_document_preparation_date',
    'publication' => 'planned_publication_date',
    'bid_opening' => 'planned_bid_opening_date',
    'evaluation_start' => 'planned_evaluation_start_date',
    'evaluation_end' => 'planned_evaluation_end_date',
    'provisional_notice' => 'planned_provisional_notification_date',
    'contract_signing' => 'planned_contract_signing_date',
    'supervising_firm' => 'supervising_firm_required',
    'prior_study' => 'other_prior_study_information',
    'source_row' => 'source_row',
  ];

  public const FIELDS = [
    'import_key' => 'field_reg_source_id',
    'activity_number' => 'field_reg_plan_reference',
    'department' => 'field_reg_department',
    'category' => 'field_reg_proc_category_key',
    'title' => 'title',
    'full_title' => 'field_reg_description',
    'estimated_budget' => 'field_reg_estimated_budget',
    'fy_budget' => 'field_reg_fy_budget',
    'funding_source' => 'field_reg_funding_source',
    'method_code' => 'field_reg_method_code',
    'document_prep' => 'field_reg_document_prep',
    'publication' => 'field_reg_planned_publication',
    'bid_opening' => 'field_reg_bid_opening',
    'evaluation_start' => 'field_reg_evaluation_start',
    'evaluation_end' => 'field_reg_evaluation_end',
    'provisional_notice' => 'field_reg_provisional_notice',
    'contract_signing' => 'field_reg_expected_contract',
    'supervising_firm' => 'field_reg_supervising_firm',
    'prior_study' => 'field_reg_prior_study',
    'source_row' => 'field_reg_source_row',
    'source_category' => 'field_reg_source_category',
  ];

  /**
   * Reads UTF-8 CSV without interpreting formulas or silently truncating rows. */
  public static function read(string $path): array {
    $h = fopen($path, 'rb');
    if (!$h) {
      throw new \RuntimeException('Cannot read private CSV.');
    }
    try {
      $headers = fgetcsv($h, 0, ',', '"', '');
      if (!$headers) {
        throw new \RuntimeException('Empty CSV.');
      }
      $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);
      $headers = array_map('trim', $headers);
      foreach ($headers as $header) {
        if (!mb_check_encoding($header, 'UTF-8') || str_contains($header, "\0")) {
          throw new \RuntimeException('CSV headers must be valid UTF-8 without NUL bytes.');
        }
      }
      if (count($headers) !== count(array_unique($headers)) || in_array('', $headers, TRUE)) {
        throw new \RuntimeException('Blank or duplicate CSV headers.');
      }
      $rows = [];
      while (($row = fgetcsv($h, 0, ',', '"', '')) !== FALSE) {
        if ($row === [NULL]) {
          continue;
        }
        if (count($row) !== count($headers)) {
          throw new \RuntimeException('CSV record ' . (count($rows) + 2) . ' has an incorrect column count.');
        }
        foreach ($row as $value) {
          if (!mb_check_encoding((string) $value, 'UTF-8') || str_contains((string) $value, "\0")) {
            throw new \RuntimeException('CSV must be valid UTF-8 without NUL bytes.');
          }
        }
        $rows[] = array_combine($headers, $row);
        if (count($rows) > 1000) {
          throw new \RuntimeException('Maximum 1,000 activities per import.');
        }
      }
      if (!$rows) {
        throw new \RuntimeException('CSV contains no activities.');
      }
      return ['headers' => $headers, 'rows' => $rows];
    }
    finally {fclose($h);
    }
  }

  /**
   * Normalizes only reviewed mappings; blanks remain NULL, codes stay literal. */
  public static function normalize(array $csv, array $mapping, array $categories = []): array {
    $rows = [];
    $keys = [];
    foreach ($csv['rows'] as $i => $raw) {
      $data = [];
      $errors = [];
      $warnings = [];
      foreach (self::COLUMNS as $key => $label) {
        $header = $mapping[$key] ?? '';
        if ($header === '' || !array_key_exists($header, $raw)) {
          throw new \RuntimeException('Map every source field, including blank-valued columns, before preview.');
        }
        $value = trim((string) $raw[$header]);
        $data[$key] = $value === '' ? NULL : $value;
      }
      $data['full_title'] = $data['title'];
      if (mb_strlen($data['title'] ?? '') > 255) {
        $data['title'] = mb_substr($data['title'], 0, 252) . '...';
        $warnings[] = 'Activity title exceeds the Drupal 255-character display label; full title preserved in Description.';
      }
      $data['source_category'] = $data['category'];
      $data['category'] = $categories[$data['category'] ?? ''] ?? $data['category'];
      foreach (['import_key', 'activity_number', 'category', 'title', 'source_row'] as $key) {
        if ($data[$key] === NULL) {
          $errors[] = "Required: $key";
        }
      }
      foreach ([
        'import_key',
        'activity_number',
        'department',
        'title',
        'funding_source',
        'method_code',
        'source_category',
      ] as $key) {
        if (mb_strlen($data[$key] ?? '') > 255) {
          $errors[] = "Too long: $key";
        }
      }
      if ($data['import_key'] !== NULL && isset($keys[$data['import_key']])) {
        $errors[] = 'Duplicate import key in this CSV.';
        $rows[$keys[$data['import_key']]]['errors'][] = 'Duplicate import key in this CSV.';
      }
      $keys[$data['import_key'] ?? ''] = $i;
      // Match the existing Drupal category keys.
      if (!in_array($data['category'], [
        'goods',
        'non_consultancy',
        'goods_non_consultancy',
        'consultancy',
        'works',
        'other',
      ], TRUE)) {
        $errors[] = 'Category must use an existing Drupal category key; review source mapping first.';
      }
      foreach (['estimated_budget', 'fy_budget'] as $key) {
        if ($data[$key] !== NULL && !preg_match('/^\d{1,16}(\.\d{1,2})?$/D', $data[$key])) {
          $errors[] = "Invalid decimal: $key";
        }
      }
      foreach ([
        'document_prep',
        'publication',
        'bid_opening',
        'evaluation_start',
        'evaluation_end',
        'provisional_notice',
        'contract_signing',
      ] as $key) {
        if ($data[$key] === NULL) {
          $warnings[] = "Missing: $key";
          continue;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $data[$key]);
        if (!$date || $date->format('Y-m-d') !== $data[$key]) {
          $errors[] = "Invalid ISO date: $key";
        }
      }
      if ($data['evaluation_start'] && $data['evaluation_end'] && $data['evaluation_end'] < $data['evaluation_start']) {
        $errors[] = 'Evaluation end precedes start.';
      }
      if ($data['source_row'] !== NULL && (!ctype_digit($data['source_row']) || (int) $data['source_row'] < 1 || (int) $data['source_row'] > 2147483647)) {
        $errors[] = 'Invalid original spreadsheet row number.';
      }
      if ($data['method_code'] === NULL) {
        $warnings[] = 'Missing procurement method.';
      }
      elseif (!in_array($data['method_code'], ['NCB', 'SS', 'RFQ', 'ICB'], TRUE)) {
        $warnings[] = 'Unrecognized source method code retained without expansion.';
      }
      if ($data['funding_source'] === NULL) {
        $warnings[] = 'Missing funding source.';
      }
      if ($data['department'] === NULL) {
        $warnings[] = 'Missing confirmed department; no department inferred.';
      }
      if ($data['activity_number'] === '87') {
        $warnings[] = 'Activity 87 requires explicit administrator review of method, funding and milestones.';
      }
      if (in_array($data['activity_number'], ['100', '101', '102', '103'], TRUE)) {
        $warnings[] = 'Source department heading absent for activities 100-103; HR subtotal is not confirmation.';
      }
      foreach ([
        'source_plan_key' => 'EUCL-FY2025-2026',
        'financial_year' => '2025/2026',
        'plan_owner' => 'EUCL',
      ] as $identity => $expected) {
        if (array_key_exists($identity, $raw) && trim($raw[$identity]) !== $expected) {
          $errors[] = 'Source plan identity mismatch: ' . $identity;
        }
      }
      if (!empty($raw['qa_flags'])) {
        foreach (explode('|', $raw['qa_flags']) as $flag) {
          $warnings[] = trim($flag);
        }
      }
      $data['source_metadata'] = array_diff_key($raw, array_flip(array_values($mapping)));
      $rows[] = ['record' => $i + 2, 'data' => $data, 'errors' => $errors, 'warnings' => $warnings];
    }
    return $rows;
  }

}
