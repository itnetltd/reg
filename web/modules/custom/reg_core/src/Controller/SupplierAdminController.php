<?php

namespace Drupal\reg_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\reg_core\Form\SupplierAdminFilterForm;
use Drupal\reg_core\Form\SupplierReviewForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Procurement supplier-review administration. */
final class SupplierAdminController extends ControllerBase {
  public function __construct(private readonly EntityTypeManagerInterface $regEntityTypeManager, private readonly FormBuilderInterface $regFormBuilder, private readonly DateFormatterInterface $dateFormatter) {}
  public static function create(ContainerInterface $container): static { return new static($container->get('entity_type.manager'), $container->get('form_builder'), $container->get('date.formatter')); }

  public function listing(Request $request): array {
    $q = trim((string) $request->query->get('q')); $status = trim((string) $request->query->get('status')); $country = trim((string) $request->query->get('country')); $category = (int) $request->query->get('category'); $supplier_type = trim((string) $request->query->get('supplier_type')); $registered = trim((string) $request->query->get('registered'));
    $storage = $this->regEntityTypeManager->getStorage('node');
    $query = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_profile')->sort('changed', 'DESC')->range(0, 500);
    if ($status) $query->condition('field_reg_supplier_status', $status);
    if ($country) $query->condition('field_reg_country', $country);
    if ($category) $query->condition('field_reg_supplier_categories.target_id', $category);
    if ($supplier_type) $query->condition('field_reg_company_type', $supplier_type);
    if ($registered) $query->condition('field_reg_submitted_at', $registered . 'T00:00:00', '>=');
    $category_options = [];
    foreach ($this->regEntityTypeManager->getStorage('taxonomy_term')->loadTree('reg_supplier_category') as $term) $category_options[$term->tid] = $term->name;
    $rows = [];
    foreach ($storage->loadMultiple($query->execute()) as $org) {
      $haystack = mb_strtolower($org->label() . ' ' . $org->get('field_reg_tin')->value . ' ' . $org->get('field_reg_registration_no')->value);
      if ($q && !str_contains($haystack, mb_strtolower($q))) continue;
      $categories = implode(', ', array_map(static fn($term) => $term->label(), $org->get('field_reg_supplier_categories')->referencedEntities()));
      $membership_ids = $storage->getQuery()->accessCheck(FALSE)->condition('type', 'reg_supplier_membership')->condition('field_reg_supplier_org.target_id', $org->id())->range(0, 1)->execute();
      $account = $membership_ids ? $storage->load(reset($membership_ids))?->get('field_reg_member_user')->entity : NULL;
      $submitted = (string) $org->get('field_reg_submitted_at')->value;
      $rows[] = [
        ['data' => Link::fromTextAndUrl($org->label(), Url::fromRoute('reg_core.supplier_admin_review', ['supplier' => $org->id()]))->toRenderable()],
        (string) $org->get('field_reg_tin')->value, (string) $org->get('field_reg_registration_no')->value,
        ucfirst(str_replace('_', ' ', (string) $org->get('field_reg_company_type')->value)),
        trim((string) $org->get('field_reg_contact_first_name')->value . ' ' . (string) $org->get('field_reg_contact_last_name')->value),
        (string) $org->get('field_reg_email')->value, (string) $org->get('field_reg_telephone')->value, $categories,
        $submitted ? $this->dateFormatter->format(strtotime($submitted . ' UTC'), 'short') : '—',
        $account && (bool) $account->get('field_reg_email_verified')->value ? $this->t('Yes') : $this->t('No'),
        ucfirst(str_replace('_', ' ', (string) $org->get('field_reg_supplier_status')->value)),
        ['data' => Link::fromTextAndUrl($this->t('View / Review'), Url::fromRoute('reg_core.supplier_admin_review', ['supplier' => $org->id()]))->toRenderable()],
      ];
    }
    return [
      'filters' => $this->regFormBuilder->getForm(SupplierAdminFilterForm::class, $q, $status, $country, $category, $category_options, $supplier_type, $registered),
      'table' => ['#type' => 'table', '#header' => [$this->t('Company'), $this->t('TIN'), $this->t('Registration Number'), $this->t('Supplier Type'), $this->t('Primary Contact'), $this->t('Email'), $this->t('Phone'), $this->t('Categories'), $this->t('Registration Date'), $this->t('Email Verified'), $this->t('Status'), $this->t('Actions')], '#rows' => $rows, '#empty' => $this->t('No supplier organizations found.')],
      '#cache' => ['contexts' => ['url.query_args'], 'tags' => ['node_list:reg_supplier_profile']],
    ];
  }

  public function review(NodeInterface $supplier): array { if ($supplier->bundle() !== 'reg_supplier_profile') throw new NotFoundHttpException(); return $this->regFormBuilder->getForm(SupplierReviewForm::class, $supplier); }
}
