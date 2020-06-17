<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

require_once __DIR__ . '/TestSuiteBase.php';

/**
 * Discovers tests for the kernel test suite.
 */
final class CoreExtensions5FunctionalTestSuite extends TestSuiteBase {

  /**
   * Factory method which loads up a suite with all kernel tests.
   *
   * @return static
   *   The test suite.
   */
  public static function suite() {
    $root = self::getDrupalRoot();
    $suite = new static('functional');
    $suite->addExtensionTestsBySuiteNamespaceAndChunk($root, 'Functional', 4);
    return $suite;
  }

}
