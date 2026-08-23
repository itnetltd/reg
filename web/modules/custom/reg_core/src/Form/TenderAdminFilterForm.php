<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\reg_core\PublicInformation\PublicInformationRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides GET filters for the dedicated Tender administration page.
 */
final class TenderAdminFilterForm extends FormBase {

  public function __construct(
    private readonly PublicInformationRepositoryInterface $repository,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(PublicInformationRepositoryInterface::class),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_core_tender_admin_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $categories = ['' => $this->t('- Any category -')] + $this->repository->taxonomyOptions('reg_procurement_category');
    $languages = ['' => $this->t('- Any language -')];
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      $languages[$langcode] = $language->getName();
    }

    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'reg-tender-admin-filters';
    $form['title'] = ['#type' => 'search', '#title' => $this->t('Title or reference'), '#default_value' => $request->query->get('title', '')];
    $form['entity'] = [
      '#type' => 'select',
      '#title' => $this->t('Entity'),
      '#options' => ['' => $this->t('- Any entity -'), 'reg' => 'REG', 'eucl' => 'EUCL', 'edcl' => 'EDCL'],
      '#default_value' => $request->query->get('entity', ''),
    ];
    $form['category'] = ['#type' => 'select', '#title' => $this->t('Category'), '#options' => $categories, '#default_value' => $request->query->get('category', '')];
    $form['status'] = [
      '#type' => 'select',
      '#title' => $this->t('Tender status'),
      '#options' => ['' => $this->t('- Any status -'), 'active' => $this->t('Current / Open'), 'closed' => $this->t('Closed'), 'awarded' => $this->t('Awarded'), 'cancelled' => $this->t('Cancelled'), 'archived' => $this->t('Archived')],
      '#default_value' => $request->query->get('status', ''),
    ];
    $form['featured'] = ['#type' => 'select', '#title' => $this->t('Homepage featured'), '#options' => ['' => $this->t('- Any -'), '1' => $this->t('Yes'), '0' => $this->t('No')], '#default_value' => $request->query->get('featured', '')];
    $form['langcode'] = ['#type' => 'select', '#title' => $this->t('Language'), '#options' => $languages, '#default_value' => $request->query->get('langcode', '')];
    $form['moderation_state'] = [
      '#type' => 'select',
      '#title' => $this->t('Moderation status'),
      '#options' => ['' => $this->t('- Any status -'), 'draft' => $this->t('Draft'), 'needs_review' => $this->t('Needs Review'), 'published' => $this->t('Published'), 'archived' => $this->t('Archived')],
      '#default_value' => $request->query->get('moderation_state', ''),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Filter')];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => \Drupal\Core\Url::fromRoute('reg_core.tenders_admin'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];
    foreach (['title', 'entity', 'category', 'status', 'featured', 'langcode', 'moderation_state'] as $key) {
      $value = trim((string) $form_state->getValue($key));
      if ($value !== '') {
        $query[$key] = $value;
      }
    }
    $form_state->setRedirect('reg_core.tenders_admin', [], ['query' => $query]);
  }

}
