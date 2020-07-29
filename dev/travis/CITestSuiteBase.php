<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

require_once __DIR__ . '/TestSuiteBase.php';

/**
 * Base class for Drupal test suites.
 */
abstract class CITestSuiteBase extends TestSuiteBase {

  /**
   * Regex patterns to split up core Kernel extensions.
   *
   * @var array
   */
  protected static $coreExtensionPatterns = [
    '[a-g]',
    '[h-q]',
    '[r-z]',
  ];

  /**
   * The number of tests can can run on the CI in the alloted time.
   *
   * Need to ocassionally verify that the array_sum > the total number of
   * tests.
   *
   * @var array
   */
  protected static $functionalSizes = [
      17, 34, 25, 30, 30,
      25, 25, 25, 30, 25,
      25, 15, 25, 25, 25,
      25, 25, 25, 30, 25,
      25, 25, 25, 25, 25,
      25, 25, 25, 25, 25,
      25, 25, 25, 20, 20,
      25, 25, 25, 25, 25,
      25, 25, 25, 25, 25,
      30, 25, 25, 25, 25,
      25, 25, 25, 10, 25,
      25, 25, 25, 25,
  ];

}
