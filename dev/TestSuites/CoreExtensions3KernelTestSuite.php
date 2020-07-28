<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

require_once __DIR__ . '/CITestSuiteBase.php';

/**
 * Discovers tests for the kernel test suite.
 */
final class CoreExtensions3KernelTestSuite extends CITestSuiteBase {

  /**
   * Factory method which loads up a suite with all kernel tests.
   *
   * @return static
   *   The test suite.
   */
  public static function suite() {
    return self::getCoreExtensionSuite(3);
  }

}
