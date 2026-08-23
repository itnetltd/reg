<?php

namespace Drupal\reg_core\Content;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Idempotently copies the approved official FAQ seed into Drupal content.
 */
final class OfficialFaqImporter {

  public function __construct(
    private readonly EntityTypeManagerInterface $regEntityTypeManager,
    private readonly ConfigFactoryInterface $regConfigFactory,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Imports missing records without overwriting administrator-managed text.
   *
   * @return array{created: int, tagged_existing: int, unchanged: int}
   *   Import counts.
   */
  public function import(): array {
    $records = OfficialFaqContent::records();
    $storage = $this->regEntityTypeManager->getStorage('node');
    $sourceIds = array_column($records, 'source_id');
    $questions = array_column($records, 'question');

    $bySource = [];
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'reg_faq')
      ->condition('field_reg_source_id', $sourceIds, 'IN')
      ->execute();
    foreach ($storage->loadMultiple($ids) as $node) {
      if ($node instanceof NodeInterface) {
        $bySource[(string) $node->get('field_reg_source_id')->value] = $node;
      }
    }

    $byQuestion = [];
    $questionIds = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'reg_faq')
      ->condition('title', $questions, 'IN')
      ->execute();
    foreach ($storage->loadMultiple($questionIds) as $node) {
      if ($node instanceof NodeInterface) {
        $byQuestion[mb_strtolower(trim($node->label()))] = $node;
      }
    }

    $counts = ['created' => 0, 'tagged_existing' => 0, 'unchanged' => 0];
    $reviewDays = max(1, (int) ($this->regConfigFactory->get('reg_core.settings')->get('governance.faq_review_days') ?: 180));
    $reviewDate = gmdate('Y-m-d\\TH:i:s', $this->time->getRequestTime() + ($reviewDays * 86400));

    foreach ($records as $record) {
      $existing = $bySource[$record['source_id']] ?? NULL;
      if ($existing instanceof NodeInterface) {
        $counts['unchanged']++;
        continue;
      }

      $questionKey = mb_strtolower(trim($record['question']));
      $existing = $byQuestion[$questionKey] ?? NULL;
      if ($existing instanceof NodeInterface) {
        // Claim only migration metadata; never replace an editor's answer,
        // question, categorization, links, priority, or publication state.
        $existing->set('field_reg_source_id', $record['source_id']);
        if ($existing->get('field_reg_source_label')->isEmpty()) {
          $existing->set('field_reg_source_label', OfficialFaqContent::SOURCE_LABEL);
        }
        if ($existing->get('field_reg_source_url')->isEmpty()) {
          $existing->set('field_reg_source_url', ['uri' => OfficialFaqContent::SOURCE_URL]);
        }
        if ($existing->get('field_reg_review_status')->isEmpty()) {
          $existing->set('field_reg_review_status', $record['review_status']);
        }
        if ($existing->get('field_reg_review_notes')->isEmpty() && $record['review_notes'] !== '') {
          $existing->set('field_reg_review_notes', $record['review_notes']);
        }
        if ($existing->get('field_reg_source_excerpt')->isEmpty()) {
          $existing->set('field_reg_source_excerpt', $record['source_excerpt']);
        }
        $existing->setNewRevision(TRUE);
        $existing->setRevisionLogMessage('Attached official REG FAQ migration traceability metadata without replacing editor content.');
        $existing->save();
        $bySource[$record['source_id']] = $existing;
        $counts['tagged_existing']++;
        continue;
      }

      $sourceHash = hash('sha256', json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
      $node = $storage->create([
        'type' => 'reg_faq',
        'langcode' => 'en',
        'uid' => 1,
        'title' => $record['question'],
        'field_reg_answer' => ['value' => $record['answer'], 'format' => 'plain_text'],
        'field_reg_faq_category' => $record['category'],
        'field_reg_faq_keywords' => $record['keywords'],
        'field_reg_related_url' => $record['actions'],
        'field_reg_review_date' => $reviewDate,
        'field_reg_content_owner' => 'Customer Service',
        'field_reg_faq_priority' => $record['priority'],
        'field_reg_faq_escalation' => $record['escalation'],
        'field_reg_source_url' => ['uri' => OfficialFaqContent::SOURCE_URL],
        'field_reg_source_label' => OfficialFaqContent::SOURCE_LABEL,
        'field_reg_source_id' => $record['source_id'],
        'field_reg_source_hash' => $sourceHash,
        'field_reg_source_excerpt' => ['value' => $record['source_excerpt'], 'format' => 'plain_text'],
        'field_reg_review_status' => $record['review_status'],
        'field_reg_review_notes' => $record['review_notes'],
        'status' => 1,
        'moderation_state' => 'published',
      ]);
      $node->save();
      $bySource[$record['source_id']] = $node;
      $byQuestion[$questionKey] = $node;
      $counts['created']++;
    }

    return $counts;
  }

}
