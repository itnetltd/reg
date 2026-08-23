<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Form\SupplierReviewForm;
use Drupal\reg_core\Form\SupplierAdminFilterForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Procurement supplier-review administration. */
final class SupplierAdminController extends ControllerBase {
  public function __construct(private readonly EntityTypeManagerInterface $regEntityTypeManager, private readonly FormBuilderInterface $regFormBuilder, private readonly DateFormatterInterface $dateFormatter) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('entity_type.manager'), $container->get('form_builder'), $container->get('date.formatter')); }
  public function listing(Request $request): array {
    $q = trim((string) $request->query->get('q')); $status = trim((string) $request->query->get('status')); $country = trim((string) $request->query->get('country')); $category = (int) $request->query->get('category'); $storage = $this->regEntityTypeManager->getStorage('node'); $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_profile')->sort('changed', 'DESC')->range(0, 500); if ($status) $query->condition('field_reg_supplier_status', $status); if ($country) $query->condition('field_reg_country', $country); if ($category) $query->condition('field_reg_supplier_categories.target_id', $category); $rows = [];
    $category_options = []; foreach ($this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree('reg_supplier_category') as $term) $category_options[$term->tid] = $term->name;
    foreach ($storage->loadMultiple($query->execute()) as $org) { $haystack = mb_strtolower($org->label() . ' ' . $org->get('field_reg_tin')->value . ' ' . $org->get('field_reg_registration_no')->value); if ($q && !str_contains($haystack, mb_strtolower($q))) continue; $categories = implode(', ', array_map(static fn($term) => $term->label(), $org->get('field_reg_supplier_categories')->referencedEntities())); $submitted = (string) $org->get('field_reg_submitted_at')->value; $rows[] = [['data' => Link::fromTextAndUrl($org->label(), Url::fromRoute('reg_core.supplier_admin_review', ['supplier' => $org->id()]))->toRenderable()], (string) $org->get('field_reg_tin')->value, (string) $org->get('field_reg_country')->value, $categories, $submitted ? $this->dateFormatter->format(strtotime($submitted . ' UTC'), 'short') : '—', ucfirst((string) $org->get('field_reg_supplier_status')->value), $this->dateFormatter->format($org->getChangedTime(), 'short'), ['data' => Link::fromTextAndUrl($this->t('Review'), Url::fromRoute('reg_core.supplier_admin_review', ['supplier' => $org->id()]))->toRenderable()]]; }
    return ['filters' => $this->regFormBuilder->getForm(SupplierAdminFilterForm::class, $q, $status, $country, $category, $category_options), 'table' => ['#type' => 'table', '#header' => [$this->t('Supplier'), $this->t('TIN'), $this->t('Country'), $this->t('Categories'), $this->t('Submitted'), $this->t('Status'), $this->t('Updated'), $this->t('Action')], '#rows' => $rows, '#empty' => $this->t('No supplier organizations found.')], '#cache' => ['contexts' => ['url.query_args'], 'tags' => ['node_list:reg_supplier_profile']]];
  }
  public function review(NodeInterface $supplier): array { if ($supplier->bundle() !== 'reg_supplier_profile') throw new NotFoundHttpException(); return $this->regFormBuilder->getForm(SupplierReviewForm::class, $supplier); }
}
