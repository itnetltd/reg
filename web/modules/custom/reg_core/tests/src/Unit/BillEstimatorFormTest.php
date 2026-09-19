<?php

namespace Drupal\Tests\reg_core\Unit;

use Drupal\Component\DependencyInjection\ReverseContainer;
use Drupal\Core\Form\FormState;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\reg_core\Analytics\AnalyticsEventTrackerInterface;
use Drupal\reg_core\Energy\BillEstimatorInterface;
use Drupal\reg_core\Form\BillEstimatorForm;
use Drupal\reg_core\Formatting\PublicNumberFormatter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Verifies repeat estimator submissions after Drupal form-cache restoration.
 */
#[CoversClass(BillEstimatorForm::class)]
final class BillEstimatorFormTest extends TestCase {

  /**
   * Tests three calculations with the form serialized between submissions.
   */
  public function testRepeatCalculationsAfterFormCacheRestoration(): void {
    $analytics = $this->createMock(AnalyticsEventTrackerInterface::class);
    $analytics->expects(self::exactly(4))->method('record')->willReturn(TRUE);

    $estimator = $this->createMock(BillEstimatorInterface::class);
    $estimator->method('getActiveSchedule')->willReturn(['tariff_type' => 'flat']);
    $estimator->method('calculate')->willReturnCallback(
      static fn(string $category, float $consumption, array $demand = []): array => [
        'category_id' => $category,
        'total' => $consumption,
      ],
    );

    $numberFormatter = new PublicNumberFormatter($this->createMock(LanguageManagerInterface::class));
    $container = new ContainerBuilder();
    $container->set('test.bill_estimator.analytics', $analytics);
    $container->set('test.bill_estimator.formatter', $numberFormatter);
    $container->set('test.bill_estimator.service', $estimator);
    $container->set('string_translation', $this->createMock(TranslationInterface::class));
    $container->set(ReverseContainer::class, new ReverseContainer($container));
    \Drupal::setContainer($container);

    try {
      $formObject = new BillEstimatorForm($analytics, $numberFormatter, $estimator);
      $submissions = [
        ['residential', 70.0],
        ['residential', 80.0],
        ['telecom_towers', 10.0],
      ];

      foreach ($submissions as [$category, $consumption]) {
        // Form API caches and restores the callback object on rebuilt forms.
        $formObject = unserialize(serialize($formObject));
        $formState = new FormState();
        $formState->setValues([
          'customer_category' => $category,
          'consumption' => $consumption,
          'peak_demand' => 0,
          'off_peak_demand' => 0,
          'shoulder_demand' => 0,
        ]);
        $form = [];
        $formObject->validateForm($form, $formState);
        self::assertSame([], $formState->getErrors());
        $formObject->submitForm($form, $formState);
        self::assertSame($category, $formState->get('result')['category_id']);
        self::assertSame($consumption, $formState->get('result')['total']);
        self::assertTrue($formState->isRebuilding());
      }

      // An invalid attempt after a valid result removes that stale result.
      $formObject = unserialize(serialize($formObject));
      $invalidState = new FormState();
      $invalidState->setValues([
        'customer_category' => 'residential',
        'consumption' => -1,
      ])->set('result', ['total' => 999999.0]);
      $form = ['result' => ['#markup' => 'Stale estimate']];
      $formObject->validateForm($form, $invalidState);
      self::assertNotSame([], $invalidState->getErrors());
      self::assertNull($invalidState->get('result'));
      self::assertArrayNotHasKey('result', $form);

      // A corrected submission after the validation error calculates normally.
      $formObject = unserialize(serialize($formObject));
      $recoveryState = new FormState();
      $recoveryState->setValues([
        'customer_category' => 'non_residential',
        'consumption' => 15.0,
        'peak_demand' => 0,
        'off_peak_demand' => 0,
        'shoulder_demand' => 0,
      ]);
      $formObject->validateForm($form, $recoveryState);
      self::assertSame([], $recoveryState->getErrors());
      $formObject->submitForm($form, $recoveryState);
      self::assertSame('non_residential', $recoveryState->get('result')['category_id']);
      self::assertSame(15.0, $recoveryState->get('result')['total']);
    }
    finally {
      \Drupal::unsetContainer();
    }
  }

}
