<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

use use Drupal\Core\Test\TestDiscovery;

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
    $root = dirname(dirname(dirname(dirname(dirname(dirname(__DIR__))))));
    $suite = new static('kernel');
    $suite->addCoreKernelTests($root, 'Kernel');
    return $suite;
  }

  /**
   * Find and add tests to the suite for core and any extensions.
   *
   * @param string $root
   *   Path to the root of the Drupal installation.
   */
  protected function addCoreKernelTests($root) {
    // Core's Kernel tests are in the namespace Drupal\KernelTests\ and are
    // always inside of core/tests/Drupal/KernelTests.
    $this->addTestFiles(TestDiscovery::scanDirectory("Drupal\\KernelTests\\", "$root/core/tests/Drupal/KernelTests"));
  }

}
