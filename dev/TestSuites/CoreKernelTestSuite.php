<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

use Drupal\Core\Test\TestDiscovery;

require_once __DIR__ . '/TestSuiteBase.php';

/**
 * Discovers tests for the kernel test suite.
 */
final class CoreKernelTestSuite extends TestSuiteBase {

  /**
   * Factory method which loads up a suite with all core kernel tests.
   *
   * @return static
   *   The test suite.
   */
  public static function suite() {
    $root = self::getDrupalRoot();
    $suite = new static('kernel');
    $suite->addCoreKernelTests($root);
    return $suite;
  }

  /**
   * Find and add tests to the suite for core and any extensions.
   *
   * @param string $root
   *   Path to the root of the Drupal installation.
   */
  protected function addCoreKernelTests($root) {
    $failing_classes = [];
    foreach ($this->failingClasses as $failing_class) {
      $failing_classes[] = $root . $failing_class;
    }
    // Core's Kernel tests are in the namespace Drupal\KernelTests\ and are
    // always inside of core/tests/Drupal/KernelTests.
    $passing_tests = [];
    $tests = TestDiscovery::scanDirectory("Drupal\\KernelTests\\", "$root/core/tests/Drupal/KernelTests");
    foreach ($tests as $test) {
      if (!in_array($test, $failing_classes)) {
        $passing_tests[] = $test;
      }
    }
    $this->addTestFiles($passing_tests);
  }

}
