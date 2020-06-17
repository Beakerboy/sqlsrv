<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

require_once __DIR__ . '/TestSuiteBase.php';

/**
 * Discovers tests for the kernel test suite.
 */
final class FailingTestsTestSuite extends TestSuiteBase {

  /**
   * Factory method which loads up a suite with all core kernel tests.
   *
   * @return static
   *   The test suite.
   */
  public static function suite() {
    $root = self::getDrupalRoot();
    $suite = new static('kernel');
    $suite->addFailingTests($root);
    return $suite;
  }

  /**
   * Find and add tests to the suite for core and any extensions.
   *
   * @param string $root
   *   Path to the root of the Drupal installation.
   */
  protected function addFailingTests($root) {
    $failing_classes = [];
    foreach ($this->failingClasses as $failing_class) {
      $filename = $root . $failing_class;
      if (file_exists($filename)) {
        $failing_classes[] = $filename;
      }
    }
    $this->addTestFiles($failing_classes);
  }

  /**
   * Find and add tests to the suite for core and any extensions.
   *
   * @param string $root
   *   Path to the root of the Drupal installation.
   * @param string $suite_namespace
   *   SubNamespace used to separate test suite. Examples: Unit, Functional.
   * @param string $pattern
   *   REGEXP pattern to apply to file name.
   */
  protected function addExtensionTestsBySuiteNamespace($root, $suite_namespace, $pattern) {
    // Do nothing.
  }

}
