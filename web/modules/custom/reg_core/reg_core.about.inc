<?php

/**
 * @file
 * Idempotent structured About REG content model and official seed content.
 */

use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\image\Entity\ImageStyle;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\language\Entity\ContentLanguageSettings;
use Drupal\media\Entity\Media;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\reg_core\Navigation\NavigationDefaults;
use Drupal\workflows\Entity\Workflow;

/**
 * Builds and seeds the complete About REG editorial section.
 */
function reg_core_apply_about_section(bool $sync_navigation = FALSE): void {
  \Drupal::service('module_installer')->install([
    'language',
    'content_translation',
    'media',
    'media_library',
    'image',
    'workflows',
    'content_moderation',
    'menu_link_content',
  ], TRUE);

  $types = [
    'reg_about_page' => ['About page', 'Official, translated page content for the About REG section.'],
    'reg_value' => ['REG value', 'One ordered REG core value and its approved public explanation.'],
    'reg_leader' => ['Leader', 'A current Board or Executive Management leadership record.'],
    'reg_employee_recognition' => ['Employee recognition', 'An approved employee-recognition record with source traceability.'],
  ];
  foreach ($types as $id => [$label, $description]) {
    $type = NodeType::load($id);
    if (!$type) {
      $type = NodeType::create([
        'type' => $id,
        'name' => $label,
        'description' => $description,
        'new_revision' => TRUE,
        'preview_mode' => 1,
        'display_submitted' => FALSE,
      ]);
      $type->save();
    }
  }

  reg_core_ensure_body_field_storage();
  foreach (['reg_about_page' => 'Page content', 'reg_leader' => 'Biography'] as $bundle => $label) {
    if (!FieldConfig::loadByName('node', $bundle, 'body')) {
      node_add_body_field(NodeType::load($bundle), $label);
    }
  }

  foreach (reg_core_about_field_definitions() as $bundle => $fields) {
    $weight = 5;
    foreach ($fields as $field_name => $definition) {
      reg_core_ensure_field($bundle, $field_name, $definition, $weight);
      $weight += 2;
    }
  }

  $base = \Drupal::service('entity_field.manager')->getBaseFieldDefinitions('node');
  foreach ([
    'reg_about_page' => ['Page title', TRUE],
    'reg_value' => ['Value name', TRUE],
    'reg_leader' => ['Full name', FALSE],
    'reg_employee_recognition' => ['Employee name', FALSE],
    'reg_partner' => ['Partner name', FALSE],
  ] as $bundle => [$label, $translatable]) {
    $override = BaseFieldOverride::loadByName('node', $bundle, 'title')
      ?: BaseFieldOverride::createFromBaseFieldDefinition($base['title'], $bundle);
    $override->setLabel($label)->setTranslatable($translatable)->save();
  }

  if (!ConfigurableLanguage::load('rw')) {
    ConfigurableLanguage::createFromLangcode('rw')->save();
  }
  foreach (array_keys($types) as $bundle) {
    ContentLanguageSettings::loadByEntityTypeBundle('node', $bundle)
      ->setDefaultLangcode('site_default')
      ->setLanguageAlterable(TRUE)
      ->setThirdPartySetting('content_translation', 'enabled', TRUE)
      ->save();
  }

  reg_core_configure_about_workflow([...array_keys($types), 'reg_partner']);
  reg_core_ensure_about_image_styles();
  reg_core_hide_about_editorial_fields();
  reg_core_apply_about_pages();
  reg_core_apply_about_values();
  reg_core_apply_about_leadership();
  reg_core_apply_employee_recognition();
  reg_core_classify_existing_partners();
  if ($sync_navigation) {
    reg_core_sync_about_navigation();
  }
  reg_core_create_roles();
}

/**
 * Returns structured fields for About content and the existing Partner type.
 */
function reg_core_about_field_definitions(): array {
  $summary = [
    'type' => 'string_long',
    'label' => 'Summary',
    'required' => FALSE,
    'translatable' => TRUE,
    'widget' => 'string_textarea',
    'formatter' => 'basic_string',
  ];
  $source = [
    'field_reg_source_site' => reg_core_list_field('Source site', [
      'official_reg' => 'Official REG website',
      'reg_internal' => 'REG internal approved source',
    ], TRUE, FALSE),
    'field_reg_source_url' => [
      'type' => 'link',
      'label' => 'Official source URL',
      'required' => FALSE,
      'translatable' => FALSE,
      'widget' => 'link_default',
      'formatter' => 'link',
    ],
    'field_reg_source_label' => reg_core_string_field('Source label', FALSE, FALSE),
    'field_reg_source_id' => reg_core_string_field('Stable source ID', FALSE, TRUE),
    'field_reg_source_hash' => reg_core_string_field('Imported source hash', FALSE, FALSE),
    'field_reg_imported_date' => reg_core_date_field('Imported date', FALSE),
    'field_reg_last_verified' => reg_core_date_field('Last verified date', FALSE),
    'field_reg_content_owner' => reg_core_string_field('Responsible content owner', FALSE),
    'field_reg_review_status' => reg_core_list_field('Source review status', [
      'verified' => 'Verified / current',
      'needs_review' => 'Needs Communications review',
    ], TRUE, FALSE),
    'field_reg_review_notes' => array_replace(reg_core_long_text_field('Internal review notes'), ['translatable' => FALSE]),
  ];
  $active_order = [
    'field_reg_order' => reg_core_integer_field('Display order', FALSE),
    'field_reg_active' => reg_core_boolean_field('Active', FALSE),
  ];

  return [
    'reg_about_page' => array_merge([
      'field_reg_about_key' => reg_core_list_field('About page identifier', [
        'about' => 'About landing page',
        'history' => 'History',
        'vision_mission_values' => 'Vision, Mission & Values',
        'group' => 'REG Group',
        'edcl' => 'EDCL',
        'eucl' => 'EUCL',
        'leadership' => 'Leadership',
        'board' => 'Board of Directors',
        'executive_management' => 'Executive Management',
        'partners' => 'Partners',
        'our_people' => 'Our People',
      ], TRUE, FALSE),
      'field_reg_summary' => $summary,
      'field_reg_vision' => array_replace($summary, ['label' => 'Vision']),
      'field_reg_mission' => array_replace($summary, ['label' => 'Mission']),
      'field_reg_featured_image' => array_replace(reg_core_media_reference_field('Optional page image', 1, FALSE, 'image'), ['translatable' => FALSE]),
      'field_reg_meta_title' => reg_core_string_field('Meta title'),
      'field_reg_meta_description' => array_replace($summary, ['label' => 'Meta description']),
      'field_reg_canonical_url' => [
        'type' => 'link',
        'label' => 'Optional canonical URL override',
        'required' => FALSE,
        'translatable' => FALSE,
        'widget' => 'link_default',
        'formatter' => 'link',
      ],
    ], $active_order, $source),
    'reg_value' => array_merge([
      'field_reg_description' => reg_core_long_text_field('Approved value description'),
      'field_reg_value_icon' => reg_core_list_field('Icon', [
        'customer' => 'Customer oriented',
        'integrity' => 'Integrity',
        'respect' => 'Respect for people',
        'teamwork' => 'Team work',
        'efficiency' => 'Efficiency',
        'sustainability' => 'Sustainability',
      ], TRUE, FALSE),
    ], $active_order, $source),
    'reg_leader' => array_merge([
      'field_reg_leadership_group' => reg_core_list_field('Leadership group', [
        'board' => 'Board of Directors',
        'executive' => 'Executive Management',
      ], TRUE, FALSE),
      'field_reg_role_title' => reg_core_string_field('Official role title'),
      'field_reg_entity' => reg_core_list_field('REG company', reg_core_company_options(), TRUE, FALSE),
      'field_reg_portrait' => array_replace(reg_core_media_reference_field('Official portrait', 1, FALSE, 'image'), ['translatable' => FALSE]),
      'field_reg_leader_start' => reg_core_date_field('Role start date', FALSE),
      'field_reg_leader_end' => reg_core_date_field('Role end date', FALSE),
    ], $active_order, $source),
    'reg_employee_recognition' => array_merge([
      'field_reg_employee_role' => reg_core_string_field('Role / job title'),
      'field_reg_entity' => reg_core_list_field('REG company', reg_core_company_options(), TRUE, FALSE),
      'field_reg_portrait' => array_replace(reg_core_media_reference_field('Official portrait', 1, FALSE, 'image'), ['translatable' => FALSE]),
      'field_reg_recognition_label' => reg_core_string_field('Recognition label'),
      'field_reg_recognition_period' => reg_core_string_field('Recognition period', FALSE),
    ], $active_order, $source),
    'reg_partner' => array_merge([
      'field_reg_partner_type' => reg_core_list_field('Partner type', [
        'stakeholder' => 'Stakeholder',
        'development_partner' => 'Development partner',
      ], TRUE, FALSE),
    ], $source),
  ];
}

/**
 * Configures Draft, Review, Published and Archived governance.
 */
function reg_core_configure_about_workflow(array $bundles): void {
  $workflow = Workflow::load('reg_about_editorial');
  if (!$workflow) {
    $workflow = Workflow::create([
      'id' => 'reg_about_editorial',
      'label' => 'REG About Editorial',
      'type' => 'content_moderation',
      'type_settings' => [
        'states' => [
          'draft' => ['label' => 'Draft', 'published' => FALSE, 'default_revision' => FALSE, 'weight' => -10],
          'needs_review' => ['label' => 'Needs Review', 'published' => FALSE, 'default_revision' => FALSE, 'weight' => 0],
          'published' => ['label' => 'Published', 'published' => TRUE, 'default_revision' => TRUE, 'weight' => 10],
          'archived' => ['label' => 'Archived', 'published' => FALSE, 'default_revision' => TRUE, 'weight' => 20],
        ],
        'transitions' => [
          'create_new_draft' => ['label' => 'Create New Draft', 'from' => ['draft', 'needs_review', 'published', 'archived'], 'to' => 'draft', 'weight' => 0],
          'submit_for_review' => ['label' => 'Submit for Review', 'from' => ['draft', 'needs_review'], 'to' => 'needs_review', 'weight' => 5],
          'publish' => ['label' => 'Publish', 'from' => ['needs_review', 'published'], 'to' => 'published', 'weight' => 10],
          'return_to_draft' => ['label' => 'Return to Draft', 'from' => ['needs_review'], 'to' => 'draft', 'weight' => 15],
          'archive' => ['label' => 'Archive', 'from' => ['published', 'archived'], 'to' => 'archived', 'weight' => 20],
        ],
        'entity_types' => [],
        'default_moderation_state' => 'draft',
      ],
    ]);
  }
  $moderation = $workflow->getTypePlugin();
  if ($moderation instanceof ContentModerationInterface) {
    foreach ($bundles as $bundle) {
      $moderation->addEntityTypeAndBundle('node', $bundle);
    }
  }
  $workflow->save();
}

/**
 * Creates bounded About derivatives for portraits and optional features.
 */
function reg_core_ensure_about_image_styles(): void {
  foreach ([
    'reg_about_portrait' => ['REG About portrait 640x760', 640, 760],
    'reg_about_feature' => ['REG About feature 1200x720', 1200, 720],
  ] as $name => [$label, $width, $height]) {
    if (ImageStyle::load($name)) {
      continue;
    }
    $style = ImageStyle::create(['name' => $name, 'label' => $label]);
    $style->addImageEffect([
      'id' => 'image_scale_and_crop',
      'weight' => 0,
      'data' => ['width' => $width, 'height' => $height, 'anchor' => 'center-top', 'upscale' => FALSE],
    ]);
    $style->save();
  }
}

/**
 * Removes source/governance fields from generic public node displays.
 */
function reg_core_hide_about_editorial_fields(): void {
  $fields = [
    'field_reg_source_site',
    'field_reg_source_url',
    'field_reg_source_label',
    'field_reg_source_id',
    'field_reg_source_hash',
    'field_reg_imported_date',
    'field_reg_last_verified',
    'field_reg_content_owner',
    'field_reg_review_status',
    'field_reg_review_notes',
  ];
  foreach (['reg_about_page', 'reg_value', 'reg_leader', 'reg_employee_recognition', 'reg_partner'] as $bundle) {
    $display = EntityViewDisplay::load("node.$bundle.default");
    if (!$display) {
      continue;
    }
    foreach ($fields as $field) {
      $display->removeComponent($field);
    }
    $display->save();
  }
}

/**
 * Creates the official About pages once, preserving later editor changes.
 */
function reg_core_apply_about_pages(): void {
  $history_url = 'https://www.reg.rw/about-us/history/';
  $pages = [
    'about' => [
      'title' => 'About REG',
      'summary' => 'Rwanda Energy Group develops, coordinates and supports Rwanda’s energy infrastructure and services through REG Holding, EDCL and EUCL.',
      'body' => '<p>Rwanda Energy Group Limited (REG) was incorporated to expand, maintain and operate energy infrastructure in Rwanda through its two subsidiaries: the Energy Development Corporation Limited (EDCL) and the Energy Utility Corporation Limited (EUCL).</p><p>The group structure gives focused attention to efficient utility operations and to the timely, cost-efficient implementation of energy development projects. REG Holding coordinates the group, monitors strategic plans and supports effective service delivery and project execution.</p>',
      'source' => $history_url,
      'meta_title' => 'About Rwanda Energy Group (REG)',
      'meta_description' => 'Learn about Rwanda Energy Group, its purpose, group structure, leadership, branches, partners and people.',
    ],
    'history' => [
      'title' => 'Our History',
      'summary' => 'The reforms and corporatization process that established REG, EDCL and EUCL.',
      'body' => '<p>The Government of Rwanda undertook reforms in the energy and water sector, separating energy from water operations. The objectives were to create sector-focused and efficient operations, attract investment, improve planning and accountability, and increase public access to services.</p><p>Government adopted a corporatization model to implement the reforms. The law repealing EWSA Law No. 97/2013 of 31 January 2014 paved the way for two corporate entities, incorporated in July 2014 with 100% Government shareholding.</p><p>Rwanda Energy Group Limited (REG) and its subsidiaries, the Energy Utility Corporation Limited (EUCL) and the Energy Development Corporation Limited (EDCL), were entrusted with energy development and utility service delivery. The Water and Sanitation Corporation (WASAC) separately received the mandate for water and sanitation infrastructure and services.</p><h2>REG Holding</h2><p>REG was incorporated to expand, maintain and operate the country’s energy infrastructure through EUCL and EDCL. The holding structure provides overall coordination and supports effective energy and investment planning. One Board of Directors supervises the group, sets the strategic agenda and monitors delivery through appropriate business plans.</p><p>REG Holding coordinates implementation of Government policy, monitors strategic plans, supports service improvement and project execution, and manages key external stakeholder relationships.</p><h2>Historical policy context</h2><p>The legacy source refers to EDPRS II and its then-current access and network growth targets. Those references are retained as historical context and are not presented as current commitments.</p>',
      'source' => $history_url,
      'review' => 'needs_review',
      'review_notes' => 'Legacy source contains EDPRS II and time-bound national target references. Retained as historical context; Communications should confirm any reuse.',
      'meta_title' => 'History of Rwanda Energy Group',
      'meta_description' => 'Read the official history of REG and the establishment of EDCL and EUCL through Rwanda’s energy-sector reforms.',
    ],
    'vision_mission_values' => [
      'title' => 'Vision, Mission & Values',
      'summary' => 'The purpose and core principles that guide Rwanda Energy Group.',
      'body' => '',
      'vision' => 'To be the leading regional provider of innovative and sustainable energy solutions for national development',
      'mission' => 'Developing and providing reliable and affordable energy while creating value for our stakeholders',
      'source' => 'https://www.reg.rw/about-us/vision-mission-values/',
      'meta_title' => 'REG Vision, Mission and Values',
      'meta_description' => 'Explore the official vision, mission and six core values of Rwanda Energy Group.',
    ],
    'group' => [
      'title' => 'REG Group',
      'summary' => 'REG Holding coordinates the group while EDCL leads energy development and EUCL provides utility services.',
      'body' => '<p>REG is supervised by one Board of Directors. The subsidiaries are independent corporate entities under REG Holding, with roles designed to support autonomy, accountability and efficiency.</p><p>REG Holding provides group coordination and monitoring. EDCL focuses on energy development and access projects, while EUCL operates and maintains utility infrastructure and delivers electricity services to end users.</p>',
      'source' => $history_url,
      'meta_title' => 'REG Group: REG, EDCL and EUCL',
      'meta_description' => 'Understand the roles of REG Holding, Energy Development Corporation Limited and Energy Utility Corporation Limited.',
    ],
    'edcl' => [
      'title' => 'Energy Development Corporation Limited (EDCL)',
      'summary' => 'EDCL develops generation, transmission and energy-access projects for Rwanda.',
      'body' => '<p>The Energy Development Corporation Limited (EDCL) is mandated to increase investment in new energy generation projects in a timely and cost-efficient manner, develop transmission infrastructure to evacuate new plants and deliver energy to distribution nodes, and plan and execute energy-access projects.</p><h2>Key mandates</h2><ul><li>Develop and implement the least-cost energy development plan in line with Government policy and strategic objectives.</li><li>Direct the Electricity Access Roll-Out Program through an implementation plan and strategy aligned with the overall sector strategy.</li><li>Undertake economic and technical studies for power plants, transmission and distribution networks, oil and gas infrastructure, and primary and social energy-resource projects.</li><li>Establish appropriate project and contract management policies and procedures.</li><li>Advise on the development of social energy projects.</li></ul><p>This ring-fenced approach is intended to strengthen accountability for development resources while opening space for increased private-sector participation.</p>',
      'source' => 'https://www.reg.rw/about-us/subsidiaries/edcl/',
      'review' => 'needs_review',
      'review_notes' => 'Legacy source references EDPRS and historic national targets. Public summary avoids treating those dated targets as current.',
      'meta_title' => 'EDCL | REG Group',
      'meta_description' => 'Learn about EDCL’s mandate for energy generation, transmission infrastructure, access projects and project development.',
    ],
    'eucl' => [
      'title' => 'Energy Utility Corporation Limited (EUCL)',
      'summary' => 'EUCL operates and maintains electricity infrastructure and provides utility services to customers.',
      'body' => '<p>The Energy Utility Corporation Limited (EUCL) was incorporated to provide energy utility services through the operation and maintenance of existing generation plants, transmission and distribution networks, and the retail supply of electricity to end users.</p><h2>Utility responsibilities</h2><ul><li>Optimize generation capacity and economic dispatch to meet short- and long-term supply requirements.</li><li>Improve operational efficiency through loss reduction, billing and collection efficiency, network reliability and service quality.</li><li>Improve customer service.</li><li>Support network growth and connections within electrified areas.</li></ul><p>EUCL’s core processes include policy and business planning, marketing planning and development, distribution planning within electrified areas, and operation and maintenance of power plants and transmission and distribution networks. The utility also supports power-purchase and power-sales agreements with independent power producers and regional utilities.</p>',
      'source' => 'https://www.reg.rw/about-us/subsidiaries/eucl/',
      'review' => 'needs_review',
      'review_notes' => 'Legacy source references historic EDPRS connection targets. Public summary retains the mandate but does not present dated targets as current.',
      'meta_title' => 'EUCL | REG Group',
      'meta_description' => 'Learn about EUCL’s mandate for electricity generation, networks, retail supply, efficiency and customer service.',
    ],
    'leadership' => [
      'title' => 'Leadership',
      'summary' => 'The Board of Directors and Executive Management currently listed by Rwanda Energy Group.',
      'body' => '<p>REG is supervised by a Board of Directors that sets the strategic agenda for the group. Executive Management leads implementation across REG Holding, EDCL and EUCL.</p>',
      'source' => 'https://www.reg.rw/about-us/board-of-directors/',
      'meta_title' => 'REG Leadership',
      'meta_description' => 'Meet the current Board of Directors and Executive Management listed by Rwanda Energy Group.',
    ],
    'board' => [
      'title' => 'Board of Directors',
      'summary' => 'Current Board members as listed on the official REG website.',
      'body' => '',
      'source' => 'https://www.reg.rw/about-us/board-of-directors/',
      'meta_title' => 'REG Board of Directors',
      'meta_description' => 'View the current Rwanda Energy Group Board of Directors and Board Secretary.',
    ],
    'executive_management' => [
      'title' => 'Executive Management',
      'summary' => 'Current executive leaders of REG Holding, EDCL and EUCL.',
      'body' => '',
      'source' => 'https://www.reg.rw/about-us/executive-management/',
      'meta_title' => 'REG Executive Management',
      'meta_description' => 'Meet the current executive leaders of Rwanda Energy Group, EDCL and EUCL.',
    ],
    'partners' => [
      'title' => 'Partners & Stakeholders',
      'summary' => 'Institutions and development partners currently represented on the official REG partners page.',
      'body' => '<p>REG works with public stakeholders and development partners in support of reliable, affordable and sustainable energy development. Records shown here are managed in Drupal and retain their official source reference.</p>',
      'source' => 'https://www.reg.rw/about-us/partners/',
      'meta_title' => 'REG Partners and Stakeholders',
      'meta_description' => 'View Rwanda Energy Group stakeholders and development partners managed through the REG content system.',
    ],
    'our_people' => [
      'title' => 'Our People',
      'summary' => 'Employees recognized by REG, EDCL and EUCL for the 2024/2025 year.',
      'body' => '',
      'source' => 'https://www.reg.rw/about-us/best-employees-year-20242025/',
      'meta_title' => 'Our People | REG Employee Recognition',
      'meta_description' => 'Meet the REG, EDCL and EUCL employees recognized for the 2024/2025 year.',
    ],
  ];

  $order = 0;
  foreach ($pages as $key => $page) {
    reg_core_about_seed_node('reg_about_page', 'reg-about-page:' . $key, [
      'title' => $page['title'],
      'body' => ['value' => $page['body'], 'format' => 'basic_html'],
      'field_reg_about_key' => $key,
      'field_reg_summary' => $page['summary'],
      'field_reg_vision' => $page['vision'] ?? '',
      'field_reg_mission' => $page['mission'] ?? '',
      'field_reg_meta_title' => $page['meta_title'],
      'field_reg_meta_description' => $page['meta_description'],
      'field_reg_order' => $order,
      'field_reg_source_url' => ['uri' => $page['source']],
      'field_reg_review_status' => $page['review'] ?? 'verified',
      'field_reg_review_notes' => ['value' => $page['review_notes'] ?? '', 'format' => 'plain_text'],
    ]);
    $order += 10;
  }
}

/**
 * Creates the six official core-value records once.
 */
function reg_core_apply_about_values(): void {
  $source = 'https://www.reg.rw/about-us/vision-mission-values/';
  $values = [
    ['Customer Oriented', 'Serving our customers and ensuring their satisfaction through our culture of excellence', 'customer'],
    ['Integrity', 'We are open, fair and honest with our stakeholders', 'integrity'],
    ['Respect for people', 'We respect, empower, recognize and ensure the continuous development of our people.', 'respect'],
    ['Team work', 'We work together reinforcing our professional capabilities as individuals, teams and operating units', 'teamwork'],
    ['Efficiency', 'We innovatively achieve more with fewer resources without compromising quality', 'efficiency'],
    ['Sustainability', 'We provide sustainable solutions in a safe environment', 'sustainability'],
  ];
  foreach ($values as $weight => [$title, $description, $icon]) {
    reg_core_about_seed_node('reg_value', 'reg-value:' . $icon, [
      'title' => $title,
      'field_reg_description' => ['value' => $description, 'format' => 'plain_text'],
      'field_reg_value_icon' => $icon,
      'field_reg_order' => $weight * 10,
      'field_reg_source_url' => ['uri' => $source],
    ]);
  }
}

/**
 * Creates current official Board and Executive records with Drupal Media.
 */
function reg_core_apply_about_leadership(): void {
  $executive_source = 'https://www.reg.rw/about-us/executive-management/';
  $board_source = 'https://www.reg.rw/about-us/board-of-directors/';
  $media = [
    'maximilien' => reg_core_about_import_portrait('maximilien-byilingiro.jpeg', 'Maximilien Byilingiro official REG portrait'),
    'felix' => reg_core_about_import_portrait('felix-gakuba.jpg', 'Felix Gakuba official EDCL portrait'),
    'claver' => reg_core_about_import_portrait('claver-gakwavu.jpeg', 'Claver Gakwavu official EUCL portrait'),
    'didacienne' => reg_core_about_import_portrait('didacienne-mukanyiligira.jpg', 'Dr. Didacienne Mukanyiligira official REG Board portrait'),
    'viateur' => reg_core_about_import_portrait('viateur-mugenzi.png', 'Viateur Mugenzi official REG Board portrait'),
    'jean_claude' => reg_core_about_import_portrait('jean-claude-iliboneye.jpg', 'Jean Claude Iliboneye official REG Board portrait'),
    'charles' => reg_core_about_import_portrait('charles-kalinda.jpg', 'Charles Kalinda official REG Board portrait'),
    'clemence' => reg_core_about_import_portrait('clemence-rita-mutabazi.jpg', 'Clémence Rita Mutabazi official REG Board portrait'),
  ];
  $executives = [
    [
      'maximilien', 'Maximilien BYILINGIRO', 'Chief Executive Officer/REG', 'reg',
      '<p>Eng. Maximilien Byilingiro is a Rwandan engineer and energy-sector executive, serving as Chief Executive Officer of Rwanda Energy Group (REG) since June 2026.</p><p>He holds a Master of Science in Electrical Engineering and brings more than a decade of leadership experience in the energy and industrial sectors. His career includes complex operations, organizational transformation, high-performing teams, and electrical project engineering focused on power generation and transmission.</p><p>Before his appointment as CEO, he served as General Manager of Shema Power Lake Kivu, an independent power producer using Lake Kivu methane gas to generate electricity.</p>',
      'verified', '',
    ],
    [
      'felix', 'Felix GAKUBA', 'Managing Director/EDCL', 'edcl',
      '<p>Mr. Gakuba is an Electromechanics Engineer with more than 18 years of energy-sector experience in the Great Lakes region, spanning electrification programs, generation and transmission planning, and project management.</p><p>Before joining EDCL as Managing Director in 2018, he coordinated multi-funded regional and transnational infrastructure programs at the Economic Community of the Great Lakes Countries (CEPGL). Earlier, he joined ELECTROGAZ after graduating from the University of Burundi and spent ten years on line rehabilitation and construction, planning, and studies development.</p>',
      'verified', '',
    ],
    [
      'claver', 'Claver GAKWAVU', 'Acting Managing Director/EUCL', 'eucl',
      '<p>Claver Gakwavu is an Electrical Engineer from the Kigali Institute of Science and Technology, now part of the University of Rwanda College of Science and Technology. He holds a Master of Business Administration in Management from the Adventist University of Central Africa and a postgraduate diploma in project management principles from Maastricht School of Management in partnership with the Rwanda Institute of Administration and Management.</p><p>He has more than 15 years of energy-sector experience across power generation, transmission, distribution, retail, domestic and regional project management, energy trade and power-systems planning.</p>',
      'needs_review', 'Official page title says Acting Managing Director/EUCL while one legacy biography sentence says Managing Director. Public title preserves the current Acting designation.',
    ],
  ];
  foreach ($executives as $weight => [$asset, $name, $role, $entity, $bio, $review, $notes]) {
    reg_core_about_seed_node('reg_leader', 'reg-leader:executive:' . $asset, [
      'title' => $name,
      'body' => ['value' => $bio, 'format' => 'basic_html'],
      'field_reg_leadership_group' => 'executive',
      'field_reg_role_title' => $role,
      'field_reg_entity' => $entity,
      'field_reg_portrait' => $media[$asset] ? ['target_id' => $media[$asset]] : NULL,
      'field_reg_order' => $weight * 10,
      'field_reg_source_url' => ['uri' => $executive_source],
      'field_reg_review_status' => $review,
      'field_reg_review_notes' => ['value' => $notes, 'format' => 'plain_text'],
    ]);
  }

  $board = [
    ['didacienne', 'Dr. Didacienne MUKANYILIGIRA', 'Chairperson of the Board'],
    ['viateur', 'Viateur MUGENZI', 'Board Member'],
    ['jean_claude', 'Mr. Jean Claude ILIBONEYE', 'Board Member'],
    ['charles', 'Charles KALINDA', 'Board Member'],
    ['clemence', 'Clémence Rita MUTABAZI', 'Board Member'],
    ['maximilien', 'Maximilien BYILINGIRO', 'CEO and Secretary to the Board'],
  ];
  foreach ($board as $weight => [$asset, $name, $role]) {
    reg_core_about_seed_node('reg_leader', 'reg-leader:board:' . $asset, [
      'title' => $name,
      'body' => ['value' => '', 'format' => 'basic_html'],
      'field_reg_leadership_group' => 'board',
      'field_reg_role_title' => $role,
      'field_reg_entity' => 'reg',
      'field_reg_portrait' => $media[$asset] ? ['target_id' => $media[$asset]] : NULL,
      'field_reg_order' => $weight * 10,
      'field_reg_source_url' => ['uri' => $board_source],
    ]);
  }
}

/**
 * Creates the three official 2024/2025 recognition records.
 */
function reg_core_apply_employee_recognition(): void {
  $source = 'https://www.reg.rw/about-us/best-employees-year-20242025/';
  $employees = [
    ['ange', 'Ange Placide NDWANYI', 'Internal Audit Specialist', 'reg', 'ange-placide-ndwanyi.jpeg'],
    ['slydio', 'Slydio NIYIBIZI', 'Contract Management Specialist', 'edcl', 'slydio-niyibizi.jpeg'],
    ['christian', 'Christian HAGENIMANA', 'Kacyiru Branch Technical Supervisor', 'eucl', 'christian-hagenimana.jpeg'],
  ];
  foreach ($employees as $weight => [$key, $name, $role, $entity, $asset]) {
    $portrait = reg_core_about_import_portrait($asset, $name . ' official REG employee-recognition portrait');
    reg_core_about_seed_node('reg_employee_recognition', 'reg-employee-recognition:2024-2025:' . $key, [
      'title' => $name,
      'field_reg_employee_role' => $role,
      'field_reg_entity' => $entity,
      'field_reg_portrait' => $portrait ? ['target_id' => $portrait] : NULL,
      'field_reg_recognition_label' => 'Best Employee',
      'field_reg_recognition_period' => '2024/2025',
      'field_reg_order' => $weight * 10,
      'field_reg_source_url' => ['uri' => $source],
    ]);
  }
}

/**
 * Adds official partner categories and metadata without replacing Media/URLs.
 */
function reg_core_classify_existing_partners(): void {
  $mappings = [
    'Rwanda Standard Board' => ['Rwanda Standards Board', 'stakeholder', 'rsb'],
    'Rwanda Environment Management Authority' => ['Rwanda Environment Management Authority', 'stakeholder', 'rema'],
    'RDB' => ['Rwanda Development Board (RDB)', 'stakeholder', 'rdb'],
    'Private Sector Federation of Rwanda' => ['Private Sector Federation of Rwanda', 'stakeholder', 'psf'],
    'Rwanda Utilities Regulatory Authority' => ['Rwanda Utilities Regulatory Authority', 'stakeholder', 'rura'],
    'opec fund' => ['OPEC Fund for International Development', 'development_partner', 'opec-fund'],
    'NDF' => ['Nordic Development Fund (NDF)', 'development_partner', 'ndf'],
    'jica' => ['Japan International Cooperation Agency (JICA)', 'development_partner', 'jica'],
    'The Saudi Fund for Development' => ['Saudi Fund for Development', 'development_partner', 'saudi-fund'],
    'Wb' => ['World Bank', 'development_partner', 'world-bank'],
  ];
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  foreach ($mappings as $legacy_title => [$name, $type, $source_id]) {
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'reg_partner')
      ->condition('title', $legacy_title)
      ->range(0, 1)
      ->execute();
    $node = $ids ? $storage->load(reset($ids)) : NULL;
    if (!$node) {
      continue;
    }
    if ($node->get('field_reg_source_id')->isEmpty()) {
      $node->setTitle($name);
      $node->set('field_reg_partner_type', $type);
      $node->set('field_reg_source_site', 'official_reg');
      $node->set('field_reg_source_url', ['uri' => 'https://www.reg.rw/about-us/partners/']);
      $node->set('field_reg_source_label', 'Official REG partners page');
      $node->set('field_reg_source_id', 'reg-partner:' . $source_id);
      $node->set('field_reg_source_hash', hash('sha256', $name . '|' . $type));
      $node->set('field_reg_imported_date', reg_core_about_today());
      $node->set('field_reg_last_verified', reg_core_about_today());
      $node->set('field_reg_content_owner', 'REG Communications');
      $node->set('field_reg_review_status', 'verified');
      $node->setNewRevision(TRUE);
      $node->setRevisionLogMessage('Classified existing Partner Media record using the official REG partners page.');
      if ($node->hasField('moderation_state')) {
        $node->set('moderation_state', 'published');
      }
      $node->setPublished()->save();
    }
  }
}

/**
 * Creates one official source record if its stable source ID does not exist.
 */
function reg_core_about_seed_node(string $bundle, string $source_id, array $values): ?Node {
  $storage = \Drupal::entityTypeManager()->getStorage('node');
  $ids = $storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('type', $bundle)
    ->condition('field_reg_source_id', $source_id)
    ->range(0, 1)
    ->execute();
  if ($ids) {
    $existing = $storage->load(reset($ids));
    return $existing instanceof Node ? $existing : NULL;
  }
  $values = array_filter($values, static fn(mixed $value): bool => $value !== NULL);
  $values += [
    'type' => $bundle,
    'langcode' => 'en',
    'uid' => 1,
    'status' => 1,
    'moderation_state' => 'published',
    'field_reg_active' => 1,
    'field_reg_source_site' => 'official_reg',
    'field_reg_source_label' => 'Official REG website',
    'field_reg_source_id' => $source_id,
    'field_reg_imported_date' => reg_core_about_today(),
    'field_reg_last_verified' => reg_core_about_today(),
    'field_reg_content_owner' => 'REG Communications',
    'field_reg_review_status' => 'verified',
  ];
  if (empty($values['field_reg_source_hash'])) {
    $values['field_reg_source_hash'] = hash('sha256', serialize($values));
  }
  $node = Node::create($values);
  $node->setNewRevision(TRUE);
  $node->setRevisionLogMessage('Initial import from the official REG website. English source only; no machine translation created.');
  $node->save();
  return $node;
}

/**
 * Imports one bundled official portrait into Drupal Media once.
 */
function reg_core_about_import_portrait(string $filename, string $alt): int {
  $module_path = \Drupal::service('extension.path.resolver')->getPath('module', 'reg_core');
  $source = DRUPAL_ROOT . '/' . $module_path . '/assets/about/' . $filename;
  if (!is_file($source)) {
    return 0;
  }
  $directory = 'public://reg-about';
  \Drupal::service('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
  $uri = $directory . '/' . $filename;
  $file_storage = \Drupal::entityTypeManager()->getStorage('file');
  $file_ids = $file_storage->getQuery()->accessCheck(FALSE)->condition('uri', $uri)->range(0, 1)->execute();
  $file = $file_ids ? $file_storage->load(reset($file_ids)) : NULL;
  if (!$file) {
    $data = file_get_contents($source);
    if ($data === FALSE) {
      return 0;
    }
    $file = \Drupal::service('file.repository')->writeData($data, $uri, FileExists::Replace);
    if (!$file) {
      return 0;
    }
    $file->setPermanent();
    $file->save();
  }
  $media_storage = \Drupal::entityTypeManager()->getStorage('media');
  $media_ids = $media_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('bundle', 'image')
    ->condition('field_media_image.target_id', $file->id())
    ->range(0, 1)
    ->execute();
  $media = $media_ids ? $media_storage->load(reset($media_ids)) : NULL;
  if (!$media) {
    $media = Media::create([
      'bundle' => 'image',
      'name' => pathinfo($filename, PATHINFO_FILENAME),
      'status' => 1,
      'uid' => 1,
      'field_media_image' => [
        'target_id' => $file->id(),
        'alt' => $alt,
        'title' => '',
      ],
    ]);
    $media->save();
  }
  return (int) $media->id();
}

/**
 * Replaces only the module-owned legacy About menu subtree once.
 */
function reg_core_sync_about_navigation(): void {
  $storage = \Drupal::entityTypeManager()->getStorage('menu_link_content');
  // Query the serialized stable identifier directly. LinkItem normalizes this
  // value differently across Drupal minor versions, while the stored marker is
  // deliberately stable and uniquely scopes deletion to module-owned links.
  $managed_ids = \Drupal::database()->select('menu_link_content_data', 'mlc')
    ->fields('mlc', ['id'])
    ->condition('menu_name', 'main')
    ->condition('link__options', '%main:about-%', 'LIKE')
    ->distinct()
    ->execute()
    ->fetchCol();
  if ($managed_ids) {
    $storage->delete($storage->loadMultiple($managed_ids));
  }
  $existing = [];
  $ids = $storage->getQuery()->accessCheck(FALSE)->condition('menu_name', 'main')->execute();
  foreach ($storage->loadMultiple($ids) as $link) {
    $options = $link->get('link')->first()?->getValue()['options'] ?? [];
    if (is_string($options)) {
      $decoded = @unserialize($options, ['allowed_classes' => FALSE]);
      $options = is_array($decoded) ? $decoded : [];
    }
    $key = $options['reg_core_navigation_id'] ?? '';
    if (is_string($key) && $key !== '') {
      $existing[$key] = $link;
    }
  }
  $about = array_values(array_filter(NavigationDefaults::main(), static fn(array $item): bool => $item['id'] === 'about-reg'));
  reg_core_ensure_navigation_links('main', $about, '', $existing, \Drupal::config('reg_core.settings'));
}

/**
 * Returns a storage-compatible current date.
 */
function reg_core_about_today(): string {
  return gmdate('Y-m-d', \Drupal::time()->getRequestTime());
}
