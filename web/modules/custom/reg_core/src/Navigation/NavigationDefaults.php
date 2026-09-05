<?php

namespace Drupal\reg_core\Navigation;

/**
 * Provides deployable defaults for REG's public Drupal menus.
 *
 * Links without a route are retained as disabled information-architecture
 * entries. Editors can assign a real destination and enable them later without
 * a code release.
 */
final class NavigationDefaults {

  /**
   * Returns the main navigation hierarchy.
   */
  public static function main(): array {
    return [
      self::item('about-reg', 'About REG', 'Ibyerekeye REG', 'reg_core.about', TRUE, [
        self::item('about-overview', 'Overview', 'Incamake', 'reg_core.about', TRUE),
        self::item('about-history', 'History', 'Amateka', 'reg_core.about_history', TRUE),
        self::item('about-vision', 'Vision, Mission & Values', "Icyerekezo, inshingano n'indangagaciro", 'reg_core.about_vision', TRUE),
        self::item('about-group', 'REG Group', 'Itsinda rya REG', 'reg_core.about_group', TRUE, [
          self::item('about-edcl', 'EDCL', 'EDCL', 'reg_core.about_edcl', TRUE),
          self::item('about-eucl', 'EUCL', 'EUCL', 'reg_core.about_eucl', TRUE),
        ]),
        self::item('about-leadership', 'Leadership', 'Ubuyobozi', 'reg_core.about_leadership', TRUE, [
          self::item('about-board', 'Board of Directors', "Inama y'Ubutegetsi", 'reg_core.about_board', TRUE),
          self::item('about-executive', 'Executive Management', 'Ubuyobozi Bukuru', 'reg_core.about_executive', TRUE),
        ]),
        self::item('about-branches', 'Branches', 'Amashami', 'reg_core.branches', TRUE),
        self::item('about-partners', 'Partners', 'Abafatanyabikorwa', 'reg_core.about_partners', TRUE),
        self::item('about-people', 'Our People', 'Abakozi bacu', 'reg_core.about_people', TRUE),
      ]),
      self::item('what-we-do', 'What We Do', 'Ibyo dukora', NULL, FALSE, [
        self::item('work-overview', 'Overview', 'Incamake'),
        self::item('work-electricity-system', 'Electricity System', "Sisitemu y'amashanyarazi", NULL, FALSE, [
          self::item('work-generation', 'Generation', 'Gutunganya amashanyarazi', NULL, FALSE, [
            self::item('work-hydropower', 'Hydropower', 'Amashanyarazi akomoka ku mazi'),
            self::item('work-solar', 'Solar', "Ingufu z'izuba"),
            self::item('work-methane-gas', 'Methane Gas', 'Gazi metane'),
            self::item('work-peat', 'Peat', 'Nyiramugengeri'),
            self::item('work-thermal', 'Thermal', "Ingufu z'ubushyuhe"),
            self::item('work-geothermal', 'Geothermal', "Ingufu z'ubushyuhe bwo mu butaka"),
          ]),
          self::item('work-transmission', 'Transmission', 'Gutwara amashanyarazi'),
          self::item('work-distribution', 'Distribution', 'Gukwirakwiza amashanyarazi'),
          self::item('work-electricity-access', 'Electricity Access', "Kugera ku mashanyarazi", NULL, FALSE, [
            self::item('work-on-grid', 'On-grid', 'Ku muyoboro'),
            self::item('work-off-grid', 'Off-grid', "Hanze y'umuyoboro"),
          ]),
        ]),
        self::item('work-energy-solutions', 'Energy Solutions', "Ibisubizo by'ingufu", NULL, FALSE, [
          self::item('work-mini-grids', 'Mini-grids', 'Imiyoboro mito'),
          self::item('work-solar-home-systems', 'Solar Home Systems', "Imirasire y'izuba yo mu ngo"),
          self::item('work-biomass-clean-cooking', 'Biomass & Clean Cooking', 'Ibicanwa bikomoka ku bimera no guteka neza'),
          self::item('work-petroleum', 'Petroleum', 'Ibikomoka kuri peteroli'),
        ]),
        self::item('work-projects', 'Projects', 'Imishinga'),
        self::item('work-programs', 'Programs', 'Gahunda', NULL, FALSE, [
          self::item('work-rbf-window-5', 'RBF Window 5', 'RBF Window 5'),
          self::item('work-clean-cooking-rbf', 'Clean Cooking RBF', 'Clean Cooking RBF'),
          self::item('work-productive-use-energy', 'Productive Use of Energy', "Imikoreshereze y'ingufu ibyara umusaruro"),
        ]),
        self::item('work-investment', 'Investment', 'Ishoramari', NULL, FALSE, [
          self::item('work-opportunities', 'Opportunities', "Amahirwe y'ishoramari"),
          self::item('work-incentives', 'Incentives', 'Ibyorohereza abashoramari'),
          self::item('work-investment-procedures', 'Investment Procedures', "Uburyo bw'ishoramari"),
          self::item('work-independent-power-producers', 'Independent Power Producers', 'Abigenga batanga amashanyarazi'),
        ]),
      ]),
      self::item('customer-services', 'Customer Services', "Serivisi z'abakiliya", 'reg_core.customer_services', TRUE, [
        self::item('customer-online', 'Online Services', 'Serivisi zo kuri interineti', 'reg_core.services', TRUE, [], ['mobile_priority' => 2]),
        self::item('customer-outages', 'Power Outages', "Ibura ry'amashanyarazi", 'reg_core.outages', TRUE, [], ['mobile_priority' => 1]),
        self::item('customer-connection', 'New Connection', 'Gusaba umuriro mushya', 'reg_core.services', TRUE),
        self::item('customer-tariffs', 'Tariffs', "Ibiciro by'amashanyarazi", 'reg_core.faq', TRUE, [], ['query' => ['category' => 'tariffs']]),
        self::item('customer-estimator', 'Bill Estimator', 'Kubara fagitire', 'reg_core.bill_estimator', TRUE),
        self::item('customer-report', 'Report a Problem', 'Menyesha ikibazo', 'reg_core.report_problem', TRUE),
        self::item('customer-branches', 'Branch Locator', 'Shaka ishami', 'reg_core.branches', TRUE),
        self::item('customer-charter', 'Customer Charter', "Amasezerano y'umukiliya"),
        self::item('customer-energy', 'Energy Saving Tips', 'Inama zo kuzigama ingufu', 'reg_core.faq', TRUE, [], ['query' => ['category' => 'energy']]),
        self::item('customer-safety', 'Electrical Safety', "Umutekano w'amashanyarazi", 'reg_core.faq', TRUE, [], ['query' => ['category' => 'safety']]),
        self::item('customer-faq', 'FAQ', 'Ibibazo bikunze kubazwa', 'reg_core.faq', TRUE),
        self::item('customer-contact', 'Contact / Call 2727', 'Twandikire / Hamagara 2727', 'reg_core.contact', TRUE),
      ], ['layout' => 'mega', 'mobile_priority' => 1]),
      self::item('public-information', 'Public Information', 'Amakuru rusange', 'reg_core.public_information', TRUE, [
        self::item('public-tenders', 'Tenders & Procurement', "Amasoko n'itangwa ry'amasoko", 'reg_core.tenders', TRUE, [
          self::item('public-tenders-current', 'Current Tenders', 'Amasoko ariho', 'reg_core.tenders_current', TRUE),
          self::item('public-tenders-awarded', 'Awarded Tenders', 'Amasoko yatanzwe', 'reg_core.tenders_awarded', TRUE),
          self::item('public-procurement-plans', 'Procurement Plans', "Gahunda z'amasoko"),
          self::item('public-tenders-archive', 'Archived Tenders', 'Amasoko yabitswe', 'reg_core.tenders_archive', TRUE),
        ]),
        self::item('public-jobs', 'Jobs & Careers', "Akazi n'imyuga", 'reg_core.jobs', TRUE, [
          self::item('public-jobs-current', 'Current Vacancies', "Imyanya y'akazi ihari", 'reg_core.jobs_current', TRUE),
          self::item('public-jobs-results', 'Recruitment Results', 'Ibyavuye mu gushaka abakozi', 'reg_core.jobs_results', TRUE),
          self::item('public-jobs-archive', 'Archived Vacancies', "Imyanya y'akazi yabitswe", 'reg_core.jobs_archive', TRUE),
        ]),
        self::item('public-publications', 'Publications', 'Inyandiko', 'reg_core.publications', TRUE),
        self::item('public-policies', 'Policies & Regulations', "Politiki n'amabwiriza"),
        self::item('public-safeguards', 'Safeguards', 'Ingamba zo kurengera'),
        self::item('public-safety', 'Safety', 'Umutekano', 'reg_core.faq', TRUE, [], ['query' => ['category' => 'safety']]),
      ], ['layout' => 'mega']),
      self::item('media-center', 'Media Center', 'Itangazamakuru', 'reg_core.video_media_center', TRUE, [
        self::item('media-news', 'News', 'Amakuru', NULL, TRUE, [
          self::item('media-news-corporate', 'Corporate News', 'Amakuru ya REG', 'reg_core.news', TRUE),
          self::item('media-news-sports', 'Sports News', "Amakuru y'imikino", 'reg_core.sports_news', TRUE),
        ]),
        self::item('media-press', 'Press Releases', "Itangazo ku banyamakuru", 'reg_core.press_releases', TRUE),
        self::item('media-announcements', 'Announcements', 'Amatangazo', 'reg_core.announcements', TRUE),
        self::item('media-publications', 'Publications', 'Inyandiko', 'reg_core.publications', TRUE),
        self::item('media-newsletters', 'Newsletters', "Ibinyamakuru bya REG", 'reg_core.newsletters', TRUE),
        self::item('media-gallery', 'Photo Gallery', 'Amafoto', 'reg_core.media_gallery', TRUE),
        self::item('media-videos', 'Videos', 'Amashusho', 'reg_core.videos', TRUE),
        self::item('media-social', 'Social Media', 'Imbuga nkoranyambaga', 'reg_core.media_social', TRUE),
      ]),
      self::item('sports', 'Sports', 'Imikino', 'reg_core.sports', TRUE, [
        self::item('sports-home', 'Sports Home', "Ahabanza h'imikino", 'reg_core.sports', TRUE),
        self::item('sports-basketball-men', 'Basketball Men', "Basketball y'abagabo", 'reg_core.sports_team_basketball_men', TRUE),
        self::item('sports-basketball-women', 'Basketball Women', "Basketball y'abagore", 'reg_core.sports_team_basketball_women', TRUE),
        self::item('sports-volleyball-men', 'Volleyball Men', "Volleyball y'abagabo", 'reg_core.sports_team_volleyball_men', TRUE),
        self::item('sports-fixtures', 'Fixtures', "Ingengabihe y'imikino", 'reg_core.sports_fixtures', TRUE),
        self::item('sports-results', 'Results', "Ibyavuye mu mikino", 'reg_core.sports_results', TRUE),
        self::item('sports-standings', 'Standings', 'Urutonde', 'reg_core.sports_standings', TRUE),
        self::item('sports-players', 'Players', 'Abakinnyi', 'reg_core.sports_players', TRUE),
        self::item('sports-news', 'Sports News', "Amakuru y'imikino", 'reg_core.sports_news', TRUE),
        self::item('sports-gallery', 'Gallery', 'Amafoto', 'reg_core.sports_gallery', TRUE),
        self::item('sports-videos', 'Videos', 'Amashusho', 'reg_core.sports_videos', TRUE),
      ], ['layout' => 'mega']),
      self::item('contact', 'Contact', 'Twandikire', 'reg_core.contact', TRUE, [
        self::item('contact-reg', 'Contact REG', 'Twandikire REG', 'reg_core.contact', TRUE),
        self::item('contact-branches', 'Branch Locator', 'Shaka ishami', 'reg_core.branches', TRUE),
        self::item('contact-fault', 'Report a Fault', "Menyesha ikibazo cy'amashanyarazi", 'reg_core.report_problem', TRUE),
        self::item('contact-complaint', 'Submit a Complaint', 'Tanga ikirego', 'reg_core.complaints', TRUE),
        self::item('contact-call', 'Call Center 2727', "Ikigo cy'itumanaho 2727", 'reg_core.contact', TRUE),
        self::item('contact-office', 'Office Details', 'Aho ibiro biherereye', 'reg_core.contact', TRUE),
      ]),
    ];
  }

  /**
   * Returns utility navigation defaults.
   */
  public static function utility(): array {
    return [
      self::item('utility-safety', 'Safety', 'Umutekano', 'reg_core.faq', TRUE, [], ['query' => ['category' => 'safety']]),
      self::item('utility-online', 'Online Services', 'Serivisi zo kuri interineti', 'reg_core.services', TRUE, [], ['mobile_priority' => 2]),
      self::item('utility-outages', 'Power Outages', "Ibura ry'amashanyarazi", 'reg_core.outages', TRUE, [], ['mobile_priority' => 1]),
      self::item('utility-tenders', 'Tenders', 'Amasoko', 'reg_core.tenders', TRUE),
      self::item('utility-jobs', 'Jobs', 'Akazi', 'reg_core.jobs', TRUE),
      self::item('utility-gis', 'GIS Portal', 'Urubuga rwa GIS', NULL, FALSE, [], ['config_key' => 'links.gis']),
      self::item('utility-contact', 'Contact', 'Twandikire', 'reg_core.contact', TRUE),
    ];
  }

  /**
   * Creates a consistently shaped menu definition.
   */
  private static function item(
    string $id,
    string $title,
    string $rw_title,
    ?string $route = NULL,
    bool $enabled = FALSE,
    array $children = [],
    array $options = [],
  ): array {
    return [
      'id' => $id,
      'title' => $title,
      'translations' => ['rw' => $rw_title],
      'route' => $route,
      'enabled' => $enabled,
      'children' => $children,
      'options' => $options,
    ];
  }

}
