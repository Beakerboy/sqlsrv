<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

require_once __DIR__ . '/TestSuiteBase.php';

/**
 * Discovers tests for the kernel test suite.
 */
final class CoreExtensions1FunctionalTestSuite extends TestSuiteBase {

  /**
   * Factory method which loads up a suite with all kernel tests.
   *
   * @return static
   *   The test suite.
   */
  public static function suite() {
    $root = dirname(__DIR__, 6);
    $suite = new static('functional');
    $suite->addExtensionTestsBySuiteNamespaceAndChunk($root, 'Functional', 0);
    return $suite;
  }

}
