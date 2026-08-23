<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\reg_core\Navigation\NavigationDefaults;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Validates deployable REG public navigation defaults.
 */
#[CoversClass(NavigationDefaults::class)]
#[Group('reg_core')]
final class NavigationDefaultsTest extends TestCase {

  /**
   * Ensures stable IDs, translations, and the supported hierarchy depth.
   */
  public function testMainNavigationStructure(): void {
    $seen = [];
    $this->assertNotEmpty(NavigationDefaults::main());
    $this->assertDefinitions(NavigationDefaults::main(), 1, $seen);
    $this->assertContains('main:customer-services', array_map(
      static fn(string $id): string => 'main:' . $id,
      $seen,
    ));
    $this->assertContains('work-hydropower', $seen);
    $this->assertContains('work-independent-power-producers', $seen);
  }

  /**
   * Ensures utility services use routes or an administrator setting.
   */
  public function testUtilityNavigationHasNoHardCodedExternalUrls(): void {
    $definitions = NavigationDefaults::utility();
    $this->assertSame([
      'Safety',
      'Online Services',
      'Power Outages',
      'Tenders',
      'Jobs',
      'GIS Portal',
      'Contact',
    ], array_column($definitions, 'title'));

    foreach ($definitions as $definition) {
      $this->assertArrayHasKey('rw', $definition['translations']);
      $this->assertFalse(
        is_string($definition['route']) && str_starts_with($definition['route'], 'http'),
      );
    }
    $gis = $definitions[5];
    $this->assertFalse($gis['enabled']);
    $this->assertSame('links.gis', $gis['options']['config_key']);
  }

  /**
   * Ensures the approved What We Do information architecture cannot drift.
   */
  public function testWhatWeDoInformationArchitecture(): void {
    $what_we_do = array_values(array_filter(
      NavigationDefaults::main(),
      static fn(array $item): bool => $item['id'] === 'what-we-do',
    ))[0];

    $this->assertSame([
      'Overview',
      'Electricity System',
      'Energy Solutions',
      'Projects',
      'Programs',
      'Investment',
    ], array_column($what_we_do['children'], 'title'));

    $electricity_system = $what_we_do['children'][1];
    $this->assertSame([
      'Generation',
      'Transmission',
      'Distribution',
      'Electricity Access',
    ], array_column($electricity_system['children'], 'title'));
    $this->assertSame([
      'Hydropower',
      'Solar',
      'Methane Gas',
      'Peat',
      'Thermal',
      'Geothermal',
    ], array_column($electricity_system['children'][0]['children'], 'title'));
    $this->assertSame([
      'On-grid',
      'Off-grid',
    ], array_column($electricity_system['children'][3]['children'], 'title'));

    $this->assertSame([
      'Mini-grids',
      'Solar Home Systems',
      'Biomass & Clean Cooking',
      'Petroleum',
    ], array_column($what_we_do['children'][2]['children'], 'title'));
    $this->assertSame([
      'RBF Window 5',
      'Clean Cooking RBF',
      'Productive Use of Energy',
    ], array_column($what_we_do['children'][4]['children'], 'title'));
    $this->assertSame([
      'Opportunities',
      'Incentives',
      'Investment Procedures',
      'Independent Power Producers',
    ], array_column($what_we_do['children'][5]['children'], 'title'));
  }

  /**
   * Recursively validates definition shape and depth.
   */
  private function assertDefinitions(array $definitions, int $depth, array &$seen): void {
    $this->assertLessThanOrEqual(4, $depth);
    foreach ($definitions as $definition) {
      $this->assertNotContains($definition['id'], $seen);
      $seen[] = $definition['id'];
      $this->assertNotSame('', trim($definition['title']));
      $this->assertNotSame('', trim($definition['translations']['rw'] ?? ''));
      if ($definition['enabled'] && $definition['route'] === NULL) {
        $this->assertNotEmpty($definition['children'], 'Enabled no-link entries must act as submenu headings.');
      }
      if ($definition['children']) {
        $this->assertDefinitions($definition['children'], $depth + 1, $seen);
      }
    }
  }

}
