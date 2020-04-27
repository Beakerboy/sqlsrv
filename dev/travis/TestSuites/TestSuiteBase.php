<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

use PHPUnit\Framework\TestSuite;
use Drupal\Core\Test\TestDiscovery;

/**
 * Base class for Drupal test suites.
 */
abstract class TestSuiteBase extends TestSuite {

  /**
   * Regex patterns to split up core extensions.
   *
   * @var array
   */
  protected static $coreExtensionPatterns = [
    '[A-Ea-e]',
    '[F-Lf-l]',
    '[M-Rm-r]',
    '[S-Zs-z]',
  ];

  /**
   * The failing test files.
   *
   * @var array
   */
  protected $failingClasses = [
    '/core/tests/Drupal/KernelTests/Core/Database/SelectSubqueryTest.php',
    '/core/tests/Drupal/KernelTests/Core/Database/SchemaTest.php',
    '/core/modules/aggregator/tests/src/Kernel/Migrate/MigrateAggregatorStubTest.php',
    '/core/modules/comment/tests/src/Kernel/CommentIntegrationTest.php',
    '/core/modules/migrate_drupal/tests/src/Kernel/d7/FieldDiscoveryTest.php',
    '/core/modules/field/tests/src/Kernel/Views/HandlerFieldFieldTest.php',
    '/core/modules/field_ui/tests/src/Kernel/EntityDisplayTest.php',
    '/core/modules/node/tests/src/Kernel/Views/RevisionUidTest.php',
    '/core/modules/workspaces/tests/src/Kernel/WorkspaceIntegrationTest.php',
  ];

  /**
   * Finds extensions in a Drupal installation.
   *
   * An extension is defined as a directory with an *.info.yml file in it.
   *
   * @param string $root
   *   Path to the root of the Drupal installation.
   *
   * @return string[]
   *   Associative array of extension paths, with extension name as keys.
   */
  protected function findExtensionDirectories($root) {
    $extension_roots = \drupal_phpunit_contrib_extension_directory_roots($root);
    $extension_directories = array_map('drupal_phpunit_find_extension_directories', $extension_roots);
    return array_reduce($extension_directories, 'array_merge', []);
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
    $failing_classes = [];
    foreach ($this->failingClasses as $failing_class) {
      $failing_classes[] = $root . $failing_class;
    }
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
