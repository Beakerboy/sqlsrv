<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

require_once __DIR__ . '/CITestSuiteBase.php';

/**
 * Discovers tests for the kernel test suite.
 */
final class FailingTestsTestSuite extends CITestSuiteBase {

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

}
