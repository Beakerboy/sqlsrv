<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

require_once __DIR__ . '/TestSuiteBase.php';

/**
 * Discovers tests for the kernel test suite.
 */
final class CoreExtensions3KernelTestSuite extends TestSuiteBase {

  /**
   * Factory method which loads up a suite with all kernel tests.
   *
   * @return static
   *   The test suite.
   */
  public static function suite() {
    $root = dirname(dirname(dirname(dirname(dirname(dirname(__DIR__))))));
    $suite = new static('kernel');
    $suite->addExtensionTestsBySuiteNamespace($root, 'Kernel', self::$coreExtensionPatterns[2]);
    return $suite;
  }

}
