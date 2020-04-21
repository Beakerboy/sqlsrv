<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

use Drupal\Core\Test\TestDiscovery;

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
    $suite->addExtensionTestsBySuiteNamespace($root, 'Kernel', '[M-Rm-r]');
    return $suite;
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
    $failing_classes = [
      'core/tests/Drupal/KernelTests/Core/Database/SelectSubqueryTest.php',
      'core/tests/Drupal/KernelTests/Core/Database/SchemaTest.php',
      'core/modules/dblog/tests/src/Kernel/Migrate/d6/MigrateDblogConfigsTest.php',
      'core/modules/aggregator/tests/src/Kernel/Migrate/MigrateAggregatorStubTest.php',
      'core/modules/comment/tests/src/Kernel/CommentIntegrationTest.php',
    ];
    // Extensions' tests will always be in the namespace
    // Drupal\Tests\$extension_name\$suite_namespace\ and be in the
    // $extension_path/tests/src/$suite_namespace directory. Not all extensions
    // will have all kinds of tests.
    foreach ($this->findExtensionDirectories($root) as $extension_name => $dir) {
      if (preg_match("#^{$pattern}(.*)$#i", $extension_name) !== 0) {
        $test_path = "$dir/tests/src/$suite_namespace";
        if (is_dir($test_path)) {
          $passing_tests = [];
          $tests = TestDiscovery::scanDirectory("Drupal\\Tests\\$extension_name\\$suite_namespace\\", $test_path);
          foreach ($tests as $test) {
            if (!in_array($test, $failing_classes)) {
              $passing_tests[] = $test;
            }
          }
          $this->addTestFiles($passing_tests);
        }
      }
    }
  }

}
