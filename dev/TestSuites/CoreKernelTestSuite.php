<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

require_once __DIR__ . '/CITestSuiteBase.php';

/**
 * Discovers tests for the kernel test suite.
 */
final class CoreKernelTestSuite extends CITestSuiteBase {

  /**
   * Factory method which loads up a suite with all core kernel tests.
   *
   * @return static
   *   The test suite.
   */
  public static function suite() {
    $root = self::getDrupalRoot();
    $suite = new static('kernel');
    $suite->addCoreKernelTestsByName($root, self::$coreKernelPatterns[0]);
    return $suite;
  }

}
