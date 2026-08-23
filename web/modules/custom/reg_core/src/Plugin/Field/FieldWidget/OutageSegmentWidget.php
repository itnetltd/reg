<?php

namespace Drupal\reg_core\Plugin\Field\FieldWidget;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Editor widget for one repeatable outage schedule window.
 */
#[FieldWidget(
  id: 'reg_outage_segment_widget',
  label: new TranslatableMarkup('Outage window editor'),
  field_types: ['reg_outage_segment'],
)]
final class OutageSegmentWidget extends WidgetBase implements ContainerFactoryPluginInterface {

  public function __construct($plugin_id, $plugin_definition, $field_definition, array $settings, array $third_party_settings, private readonly EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($plugin_id, $plugin_definition, $configuration['field_definition'], $configuration['settings'], $configuration['third_party_settings'], $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $item = $items[$delta] ?? NULL;
    $element['#type'] = 'fieldset';
    $element['#title'] = $this->t('Window @number', ['@number' => $delta + 1]);
    $element['#attributes']['class'][] = 'reg-outage-window';
    $element['start'] = $this->dateElement($this->value($item, 'start'), $this->t('Start date and time'), TRUE);
    $element['end'] = $this->dateElement($this->value($item, 'end'), $this->t('End date and time'), TRUE);
    $element['feeder'] = ['#type' => 'textfield', '#title' => $this->t('Feeder'), '#default_value' => $this->value($item, 'feeder'), '#maxlength' => 255];
    $element['substation'] = ['#type' => 'textfield', '#title' => $this->t('Substation'), '#default_value' => $this->value($item, 'substation'), '#maxlength' => 255];
    $element['districts'] = ['#type' => 'select', '#title' => $this->t('Districts'), '#multiple' => TRUE, '#options' => $this->termOptions('reg_district'), '#default_value' => $this->ids($this->value($item, 'districts')), '#description' => $this->t('Select every district named in this window.')];
    $element['sectors'] = ['#type' => 'select', '#title' => $this->t('Sectors'), '#multiple' => TRUE, '#options' => $this->termOptions('reg_sector'), '#default_value' => $this->ids($this->value($item, 'sectors')), '#description' => $this->t('Use the exact wording below when only part of a sector is affected.')];
    $element['affected_area'] = ['#type' => 'textarea', '#title' => $this->t('Affected areas / localities'), '#default_value' => $this->value($item, 'affected_area'), '#rows' => 3, '#required' => TRUE];
    $element['notes'] = ['#type' => 'textarea', '#title' => $this->t('Window notes'), '#default_value' => $this->value($item, 'notes'), '#rows' => 2];
    $element['restoration_status'] = ['#type' => 'select', '#title' => $this->t('Restoration status'), '#options' => ['' => $this->t('- Not confirmed -'), 'awaiting_confirmation' => $this->t('Scheduled window ended — awaiting confirmation'), 'restored' => $this->t('Restored')], '#default_value' => $this->value($item, 'restoration_status')];
    $element['actual_restoration'] = $this->dateElement($this->value($item, 'actual_restoration'), $this->t('Actual restoration time'), FALSE);
    $element['restored_early'] = ['#type' => 'checkbox', '#title' => $this->t('Restored early'), '#default_value' => (bool) $this->value($item, 'restored_early')];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function massageFormValues(array $values, array $form, FormStateInterface $form_state): array {
    foreach ($values as &$value) {
      foreach (['start', 'end', 'actual_restoration'] as $key) {
        if ($value[$key] instanceof DrupalDateTime) {
          $value[$key]->setTimezone(new \DateTimeZone('UTC'));
          $value[$key] = $value[$key]->format('Y-m-d\\TH:i:s');
        }
        else {
          $value[$key] = '';
        }
      }
      foreach (['districts', 'sectors'] as $key) {
        $value[$key] = implode(',', array_values(array_filter((array) ($value[$key] ?? []))));
      }
      $value['restored_early'] = empty($value['restored_early']) ? 0 : 1;
    }
    return $values;
  }

  private function dateElement(string $value, TranslatableMarkup $title, bool $required): array {
    return ['#type' => 'datetime', '#title' => $title, '#default_value' => $value !== '' ? new DrupalDateTime($value, 'UTC') : NULL, '#required' => $required];
  }

  private function value(mixed $item, string $property): string {
    return $item ? trim((string) $item->get($property)->getValue()) : '';
  }

  private function ids(string $value): array {
    return $value === '' ? [] : array_values(array_filter(array_map('intval', explode(',', $value))));
  }

  private function termOptions(string $vocabulary): array {
    $options = [];
    foreach ($this->entityTypeManager->getStorage('taxonomy_term')->loadTree($vocabulary) as $term) {
      $options[(int) $term->tid] = $term->name;
    }
    natcasesort($options);
    return $options;
  }

}
