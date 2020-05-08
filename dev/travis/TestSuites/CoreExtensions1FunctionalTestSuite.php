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
    $root = dirname(dirname(dirname(dirname(dirname(dirname(__DIR__))))));
    $suite = new static('functional');
    $suite->addExtensionTestsBySuiteNamespaceAndChunk($root, 'Functional', 0);
    return $suite;
  }

  /**
   * Find and add tests to the suite for core and any extensions.
   *
   * @param string $root
   *   Path to the root of the Drupal installation.
   * @param string $suite_namespace
   *   SubNamespace used to separate test suite. Examples: Unit, Functional.
   * @param int $splice
   *   The chunk number to test
   */
  protected function addExtensionTestsBySuiteNamespaceAndChunk($root, $suite_namespace, $splice = 0) {
    $failing_classes = [];
    foreach ($this->failingClasses as $failing_class) {
      $failing_classes[] = $root . $failing_class;
    }
    // Extensions' tests will always be in the namespace
    // Drupal\Tests\$extension_name\$suite_namespace\ and be in the
    // $extension_path/tests/src/$suite_namespace directory. Not all extensions
    // will have all kinds of tests.
    foreach ($this->findExtensionDirectories($root) as $extension_name => $dir) {
      $test_path = "$dir/tests/src/$suite_namespace";
      if (is_dir($test_path)) {
        $passing_tests = [];
        $tests = TestDiscovery::scanDirectory("Drupal\\Tests\\$extension_name\\$suite_namespace\\", $test_path);
        foreach ($tests as $test) {
          if (!in_array($test, $failing_classes)) {
            $passing_tests[] = $test;
          }
        }
        $chunks = array_chunk($passing_tests, 100);
        $this->addTestFiles($chunks[$splice]);
      }
    }
  }


}
