<?php

namespace Drupal\reg_core\Form;

use Drupal\file\FileInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\reg_core\Procurement\ProcurementCsv;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;

/**
 * Private upload, explicit mapping, dry-run preview and selected-row commit. */
final class ProcurementImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_procurement_csv_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;
    $form['#attributes']['class'][] = 'reg-procurement-import';
    $form['#attached']['library'][] = 'reg_core/procurement_import';
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Import EUCL Procurement Plan 2025/2026 into existing unpublished planning entities. Uploads remain private. Preview does not save content. Existing editor changes are preserved; conflicting edits require manual reconciliation. No tenders are created.') . '</p>',
    ];
    foreach ([
      'csv' => [
        $this->t('Source CSV (UTF-8, 29 columns)'),
        'csv',
      ],
      'workbook' => [
        $this->t('Original source workbook (if available)'),
        'xlsx xls',
      ],
    ] as $key => [
      $label,
      $extensions,
    ]) {
      $form[$key] = [
        '#type' => 'managed_file',
        '#title' => $label,
        '#upload_location' => 'private://procurement-plans/import',
        '#upload_validators' => [
          'FileExtension' => [
            'extensions' => $extensions,
          ],
          'FileSizeLimit' => [
            'fileLimit' => 25 * 1024 * 1024,
          ],
        ],
        '#default_value' => $form_state->getValue($key,
        []),
      ];
    }
    $form['load'] = ['#type' => 'submit', '#value' => $this->t('Read columns'), '#submit' => ['::readColumns']];
    $csv = $form_state->get('csv_data');
    if (!$csv) {
      return $form;
    }
    $form['mapping'] = [
      '#type' => 'details',
      '#title' => $this->t('Review source column mapping'),
      '#open' => !$form_state->get('preview'),
    ];
    $options = ['' => $this->t('- Select source column -')] + array_combine($csv['headers'], $csv['headers']);
    foreach (ProcurementCsv::COLUMNS as $key => $label) {
      $form['mapping'][$key] = [
        '#type' => 'select',
        '#title' => $this->t('@label',
      [
        '@label' => $label,
      ]),
        '#options' => $options,
        '#default_value' => in_array($key,
      $csv['headers'],
      TRUE) ? $key : (in_array(ProcurementCsv::SOURCE_HEADERS[$key] ?? '',
      $csv['headers'],
      TRUE) ? ProcurementCsv::SOURCE_HEADERS[$key] : ''),
      ];
    }
    $form['categories'] = [
      '#type' => 'textarea',
      '#default_value' => in_array('category_as_in_sheet', $csv['headers'], TRUE) ? "Goods and Non Consultancy services=goods_non_consultancy\nConsultancy Services=consultancy" : '',
      '#title' => $this->t('Reviewed category mapping (one source label=Drupal key per line)'),
      '#description' => $this->t('Leave blank if source values already use goods, non_consultancy, goods_non_consultancy, consultancy, works or other. Original labels are preserved separately. Do not infer an ambiguous category.'),
    ];
    $form['review_columns'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I reviewed the source mapping and any unmapped columns. No required activity data is omitted.'),
    ];
    $form['dry_run'] = [
      '#type' => 'submit',
      '#value' => $this->t('Validate and preview (dry run)'),
      '#submit' => [
        '::preview',
      ],
    ];
    if ($preview = $form_state->get('preview')) {
      $form['summary'] = [
        '#type' => 'container',
        'text' => [
          '#plain_text' => $this->t('@rows source records; @columns columns. Review warnings before selecting approved rows. Import errors cannot be selected.',
      [
        '@rows' => count($preview['rows']),
        '@columns' => count($csv['headers']),
      ]),
        ],
      ];
      $unused = array_diff($csv['headers'], array_values($form_state->get('mapping')));
      $form['unused'] = [
        '#type' => 'container',
        'text' => [
          '#plain_text' => $this->t('Unmapped source columns: @columns',
      [
        '@columns' => implode(', ',
      $unused),
      ]),
        ],
      ];
      $region_label = Html::escape((string) $this->t('Import validation results'));
      $form['rows'] = [
        '#type' => 'table',
        '#tree' => TRUE,
        '#prefix' => '<div class="reg-procurement-import__table" role="region" aria-label="' . $region_label . '" tabindex="0">',
        '#suffix' => '</div>',
        '#header' => [
          $this->t('Approve row'),
          $this->t('Source row / key'),
          $this->t('Activity'),
          $this->t('Action'),
          $this->t('Warnings / errors'),
        ],
        '#responsive' => TRUE,
      ];
      foreach ($preview['rows'] as $i => $row) {
        $form['rows'][$i]['selected'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Approve source row @row',
        [
          '@row' => $row['data']['source_row'] ?? $row['record'],
        ]),
          '#title_display' => 'invisible',
          '#disabled' => (bool) $row['errors'],
        ];
        $form['rows'][$i]['key'] = [
          '#plain_text' => ($row['data']['source_row'] ?? '?') . ' / ' . ($row['data']['import_key'] ?? '?'),
        ];
        $form['rows'][$i]['title'] = ['#plain_text' => $row['data']['activity_number'] . ': ' . $row['data']['title']];
        $form['rows'][$i]['action'] = ['#plain_text' => $row['action']];
        $form['rows'][$i]['messages'] = [
          '#plain_text' => implode(' | ',
        array_merge($row['errors'],
        $row['warnings'])),
        ];
      }
      $form['report'] = [
        '#type' => 'link',
        '#title' => $this->t('Download validation report'),
        '#url' => Url::fromRoute('reg_core.procurement_import_report',
      [
        'token' => $form_state->get('report_token'),
      ]),
      ];
      $form['commit'] = [
        '#type' => 'submit',
        '#value' => $this->t('Import selected approved rows'),
        '#submit' => [
          '::commit',
        ],
      ];
    }
    return $form;
  }

  /**
   * Uses explicit upload, preview and commit handlers.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Reads and pins private source headers without saving plan content.
   */
  public function readColumns(array &$form, FormStateInterface $form_state): void {
    $form_state->set('preview', NULL);
    try {
      $csv = $this->source($form_state, 'csv');
      $data = ProcurementCsv::read($csv->getFileUri());
      if (count($data['headers']) !== 29) {
        throw new \RuntimeException('Expected 29 source columns for this EUCL import.');
      }
      $form_state->set('csv_data', $data);
      $form_state->set('csv_hash', hash_file('sha256', $csv->getFileUri()));
      $form_state->set('csv_fid', $csv->id());
    }
    catch (\Throwable $e) {
      $form_state->set('csv_data', NULL);
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRebuild();
  }

  /**
   * Validates mapped rows and stores a private dry-run preview.
   */
  public function preview(array &$form, FormStateInterface $form_state): void {
    $form_state->set('preview', NULL);
    try {
      if (!$form_state->getValue('review_columns')) {
        throw new \RuntimeException('Review and acknowledge the source column mapping.');
      }
      $csv = $this->source($form_state, 'csv');
      if ($csv->id() != $form_state->get('csv_fid') || hash_file('sha256', $csv->getFileUri()) !== $form_state->get('csv_hash')) {
        throw new \RuntimeException('CSV changed; read columns again.');
      }
      $mapping = $form_state->getValue('mapping');
      if (count(array_filter($mapping)) !== count(array_unique(array_filter($mapping)))) {
        throw new \RuntimeException('Do not map one column to multiple source fields.');
      }
      $categories = [];
      foreach (preg_split('/\r?\n/', trim($form_state->getValue('categories', ''))) as $line) {
        if ($line === '') {
          continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) !== 2 || trim($parts[0]) === '' || isset($categories[trim($parts[0])])) {
          throw new \RuntimeException('Invalid or duplicate category mapping.');
        }
        $categories[trim($parts[0])] = trim($parts[1]);
      }
      $rows = ProcurementCsv::normalize(ProcurementCsv::read($csv->getFileUri()), $mapping, $categories);
      $form_state->set('categories', $form_state->getValue('categories', ''));
      $form_state->set('mapping', $mapping);
      $form_state->set('preview', \Drupal::service('reg_core.procurement_importer')->preview($rows));
      $token = bin2hex(random_bytes(20));
      \Drupal::service('tempstore.private')->get('reg_procurement_reports')->set($token, $form_state->get('preview')['rows']);
      $form_state->set('report_token', $token);
      $workbook_ids = $form_state->getValue('workbook', []);
      $workbook = $workbook_ids ? $this->source($form_state, 'workbook') : NULL;
      $form_state->set('workbook_fid', $workbook?->id());
      $form_state->set('workbook_hash', $workbook ? hash_file('sha256', $workbook->getFileUri()) : NULL);
      $this->messenger()->addStatus($this->t('Dry run complete. No plan, item or tender was saved.'));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
    $form_state->setRebuild();
  }

  /**
   * Commits only explicitly selected valid rows from the pinned preview.
   */
  public function commit(array &$form, FormStateInterface $form_state): void {
    try {
      if (!$form_state->get('preview') || $form_state->getValue('mapping') !== $form_state->get('mapping') || $form_state->getValue('categories', '') !== $form_state->get('categories') || !$form_state->getValue('review_columns')) {
        throw new \RuntimeException('Preview the reviewed mapping before import.');
      }
      $csv = $this->source($form_state, 'csv');
      $workbook = $form_state->getValue('workbook') ? $this->source($form_state, 'workbook') : NULL;
      if ($csv->id() != $form_state->get('csv_fid') || $workbook?->id() != $form_state->get('workbook_fid')) {
        throw new \RuntimeException('Uploads changed; preview again with the source workbook.');
      }
      $selected = [];
      foreach ($form_state->getValue('rows', []) as $i => $row) {
        if (!empty($row['selected'])) {
          $selected[] = $i;
        }
      }
      $counts = \Drupal::service('reg_core.procurement_importer')->commit($form_state->get('preview'), $selected, $csv, $workbook, $form_state->get('csv_hash'), $form_state->get('workbook_hash'));
      if (!$workbook) {
        $this->messenger()->addWarning($this->t('Original workbook unavailable. The CSV is retained privately; attach the original workbook to the plan when available.'));
      }
      $this->messenger()->addStatus($this->t('Inserted: @inserted. Updated: @updated. Skipped: @skipped. Warnings: @warnings. Errors: @errors.', array_combine(array_map(static fn($key) => '@' . $key, array_keys($counts)), array_values($counts))));
      $form_state->set('preview', NULL);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($this->t('Nothing imported: @error', ['@error' => $e->getMessage()]));
    }
    $form_state->setRebuild();
  }

  /**
   * Loads an authorized private source upload.
   */
  private function source(FormStateInterface $form_state, string $key): FileInterface {
    $ids = $form_state->getValue($key, []);
    $file = $ids ? File::load(reset($ids)) : NULL;
    if (!$file || !str_starts_with($file->getFileUri(), 'private://procurement-plans/import/') || !$file->access('view')) {
      throw new \RuntimeException('A readable private ' . $key . ' upload is required.');
    }
    return $file;
  }

}
