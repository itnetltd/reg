<?php

namespace Drupal\reg_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\reg_core\News\NewsRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides GET filters for the dedicated News administration view.
 */
final class NewsAdminFilterForm extends FormBase {

  public function __construct(
    private readonly NewsRepositoryInterface $newsRepository,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(NewsRepositoryInterface::class),
      $container->get('language_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'reg_core_news_admin_filter_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $request = $this->getRequest();
    $options = $this->newsRepository->filterOptions();
    $categories = ['' => $this->t('- Any category -')];
    foreach ($options['categories'] as $category) {
      $categories[(int) $category['id']] = $category['label'];
    }
    $languages = ['' => $this->t('- Any language -')];
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      $languages[$langcode] = $language->getName();
    }

    $form['#method'] = 'get';
    $form['#attributes']['class'][] = 'reg-news-admin-filters';
    $form['title'] = [
      '#type' => 'search',
      '#title' => $this->t('Title'),
      '#default_value' => $request->query->get('title', ''),
    ];
    $form['category'] = [
      '#type' => 'select',
      '#title' => $this->t('Category'),
      '#options' => $categories,
      '#default_value' => $request->query->get('category', ''),
    ];
    $form['featured'] = [
      '#type' => 'select',
      '#title' => $this->t('Homepage featured'),
      '#options' => ['' => $this->t('- Any -'), '1' => $this->t('Yes'), '0' => $this->t('No')],
      '#default_value' => $request->query->get('featured', ''),
    ];
    $form['langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $languages,
      '#default_value' => $request->query->get('langcode', ''),
    ];
    $form['moderation_state'] = [
      '#type' => 'select',
      '#title' => $this->t('Moderation status'),
      '#options' => [
        '' => $this->t('- Any status -'),
        'draft' => $this->t('Draft'),
        'needs_review' => $this->t('Needs Review'),
        'published' => $this->t('Published'),
        'archived' => $this->t('Archived'),
      ],
      '#default_value' => $request->query->get('moderation_state', ''),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Filter')];
    $form['actions']['reset'] = [
      '#type' => 'link',
      '#title' => $this->t('Reset'),
      '#url' => \Drupal\Core\Url::fromRoute('reg_core.news_admin'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];
    foreach (['title', 'category', 'featured', 'langcode', 'moderation_state'] as $key) {
      $value = trim((string) $form_state->getValue($key));
      if ($value !== '') {
        $query[$key] = $value;
      }
    }
    $form_state->setRedirect('reg_core.news_admin', [], ['query' => $query]);
  }

}
