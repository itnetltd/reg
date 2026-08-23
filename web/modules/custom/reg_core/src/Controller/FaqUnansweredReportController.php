<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reports aggregated unanswered FAQ searches to authorized editors.
 */
final class FaqUnansweredReportController extends ControllerBase {

  public function __construct(
    private readonly Connection $regDatabase,
    private readonly DateFormatterInterface $regDateFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the privacy-filtered unanswered-query report.
   */
  public function report(): array {
    $query = $this->regDatabase->select('reg_core_faq_unanswered', 'u')
      ->fields('u', ['phrase', 'langcode', 'occurrences', 'first_searched', 'last_searched'])
      ->orderBy('last_searched', 'DESC')
      ->extend(PagerSelectExtender::class)
      ->limit(50);
    $rows = [];
    foreach ($query->execute() as $record) {
      $rows[] = [
        $record->phrase,
        $record->langcode,
        (int) $record->occurrences,
        $this->regDateFormatter->format((int) $record->first_searched, 'short'),
        $this->regDateFormatter->format((int) $record->last_searched, 'short'),
      ];
    }

    return [
      'privacy' => [
        '#markup' => '<p>' . $this->t('This report contains only sanitized aggregate phrases. IP addresses, contact details, account numbers, and other request metadata are not recorded here.') . '</p>',
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('Question/Search phrase'),
          $this->t('Language'),
          $this->t('Count'),
          $this->t('First searched'),
          $this->t('Last searched'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No unanswered FAQ searches have been recorded.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#cache' => ['max-age' => 0],
    ];
  }

}
