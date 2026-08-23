<?php

namespace Drupal\reg_core\Content;

/**
 * Initial migration records from the official REG branch directory.
 *
 * These records are used only to seed editable Drupal branch content. Public
 * pages always read the resulting reg_branch nodes.
 */
final class OfficialBranchContent {

  public const SOURCE_LABEL = 'REG Official Branch Directory';

  public const SOURCE_URL = 'https://www.reg.rw/about-us/branches-and-managers-contacts/';

  public const REVIEW_STATUS = 'imported_requires_validation';

  /**
   * Returns the official source records supplied for the initial migration.
   */
  public static function records(): array {
    return [
      self::record('Bugesera', 'MUNYANZIZA Jasson', '0788330017', '0788575647', 'jmunyanziza@eucl.reg.rw'),
      self::record('Burera', 'MAJYAMBERE Jean de Dieu', '0788330027', '0788698136', 'jdmajyambere@eucl.reg.rw'),
      self::record('Gakenke', 'DUSENGIMANA Damien', '0788330028', '0788646444', 'ddusengimana@eucl.reg.rw'),
      self::record('Gatsibo', 'MUGABO Jackson', '0788330018', '0788488274', 'jmugabo@eucl.reg.rw'),
      self::record('Gicumbi', 'HAVUGIMANA Théogène', '0788330029', '0785977804', 'thavugimana@eucl.reg.rw'),
      self::record('Gisagara', 'BAKENERINZUNGU Dominique', '0788330055', '0788597113', 'dbakenerinzungu@eucl.reg.rw'),
      self::record('Huye', 'KAYIBANDA Omar', '0788330056', '0788849900', 'okayibanda@eucl.reg.rw'),
      self::record('Jabana', 'KARINGANIRE Innocent', '0788330011', '0788528456', 'ikaringanire@eucl.reg.rw'),
      self::record('Kacyiru', 'INGABIRE Ernest', '0788330012', '0788616628', 'eringabire@eucl.reg.rw'),
      self::record('Kamonyi', 'KABARE Jean Paul', '0788330057', '0788761884', 'jpkabare@eucl.reg.rw'),
      self::record('Kanombe', 'NYIRINGANGO Jean de Dieu', '0788330013', '0787455215', 'jdnyiringango@eucl.reg.rw'),
      self::record('Karongi', 'KIIZA Francis', '0788330032', '0788666453', 'fkiiza@eucl.reg.rw'),
      self::record('Kayonza', 'GASERUKA David', '0788330019', '0783702772', 'dgaseruka@eucl.reg.rw'),
      self::record('Kicukiro', 'MUNYANEZA Jean Bosco', '0788330014', '0788515282', 'jbmunyaneza@eucl.reg.rw'),
      self::record('Kirehe', 'MUPENZI Théogène', '0788330023', '0788440961', 'tmupenzi@eucl.reg.rw'),
      self::record('Muhanga', 'KALISA Rosine', '0788330058', '0788553013', 'rkalisa@eucl.reg.rw'),
      self::record('Musanze', 'BATANGANA Regis', '0788330030', '0788566226', 'rbatangana@eucl.reg.rw'),
      self::record('Ngoma', 'MANIRAGUHA Jean Pierre', '0788330024', '0788493651', 'jpmaniraguha@eucl.reg.rw'),
      self::record('Ngororero', 'MUHAYIMANA Celestin', '0788330034', '0788797778', 'cmuhayimana@eucl.reg.rw'),
      self::record('Nyabihu', 'MUTSINDASHYAKA Martin', '0788330035', '0788405515', 'mmutsindashyaka@eucl.reg.rw'),
      self::record('Nyagatare', 'NIYONKURU Benoit', '0788330025', '0783545746', 'bniyonkuru@eucl.reg.rw'),
      self::record('Nyamagabe', 'NIYOTWIZERA Christophe', '0788330059', '0783482647', 'cniyotwizera@eucl.reg.rw'),
      self::record('Nyamasheke', 'RUGABA Maurice', '0788330036', '0788687633', 'mrugaba@eucl.reg.rw'),
      self::record('Nyanza', 'MUKASETI Rosine', '0788330060', '0788520131', 'rmukaseti@eucl.reg.rw'),
      self::record('Nyarugenge', 'NSABIMANA Joel Elvis', '0788330015', '0788600505', 'jonsabimana@eucl.reg.rw'),
      self::record('Nyaruguru', 'GASIGWA Landfried', '0788330061', '0785270537', 'lgasigwa@eucl.reg.rw'),
      self::record('Remera', 'NYIRARUKUNDO Alphonsine', '0788330016', '0788306891', 'anyirarukundo@eucl.reg.rw'),
      self::record('Rubavu', 'MITALI KALINDA Adolphe', '0788330037', '0788612190', 'amkalinda@eucl.reg.rw'),
      self::record('Ruhango', 'NKUNDABAKUZE Fulgence', '0788330062', '0785750605', 'fnkundabakuze@eucl.reg.rw'),
      self::record('Rulindo', 'RUTABAYIRU Ruterana Janvier', '0788330031', '0788350169', 'jrutabayiru@eucl.reg.rw'),
      self::record('Rusizi', 'NZAYINAMBAHO Tuyizere Jacques', '0788330038', '0783298150', 'jjnzayinambaho@eucl.reg.rw'),
      self::record('Rutsiro', 'BAHORANIMANA Barnabé', '0788330039', '0788744355', 'bbahoranimana@eucl.reg.rw'),
      self::record('Rwamagana', 'HABIMANA Marcel', '0788330026', '0788474543', 'mhabimana@eucl.reg.rw'),
    ];
  }

  /**
   * District mappings explicitly approved in the migration brief.
   */
  public static function districtMappings(): array {
    $districts = [
      'Bugesera', 'Burera', 'Gakenke', 'Gatsibo', 'Gicumbi', 'Gisagara',
      'Huye', 'Kamonyi', 'Karongi', 'Kayonza', 'Kirehe', 'Muhanga',
      'Musanze', 'Ngoma', 'Ngororero', 'Nyabihu', 'Nyagatare', 'Nyamagabe',
      'Nyamasheke', 'Nyanza', 'Nyarugenge', 'Nyaruguru', 'Rubavu', 'Ruhango',
      'Rulindo', 'Rusizi', 'Rutsiro', 'Rwamagana',
    ];
    return array_combine($districts, $districts);
  }

  /**
   * Builds one normalized migration record.
   */
  private static function record(string $branch, string $manager, string $ph, string $te, string $email): array {
    $sourceId = 'reg_branch_' . strtolower(str_replace(' ', '_', $branch));
    return [
      'source_id' => $sourceId,
      'branch' => $branch,
      'manager' => $manager,
      'ph' => $ph,
      'te' => $te,
      'email' => $email,
      'source_excerpt' => sprintf(
        "%s\nManager: %s\nPH: %s\nTE: %s\nEmail: %s",
        $branch,
        $manager,
        $ph,
        $te,
        $email,
      ),
    ];
  }

}
