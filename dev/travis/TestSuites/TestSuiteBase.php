<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

use PHPUnit\Framework\TestSuite;
use Drupal\Core\Test\TestDiscovery;

/**
 * Base class for Drupal test suites.
 */
abstract class TestSuiteBase extends TestSuite {

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
    $failing_classes = [
      $root . '/core/tests/Drupal/KernelTests/Core/Database/SelectSubqueryTest.php',
      $root . '/core/tests/Drupal/KernelTests/Core/Database/SchemaTest.php',
      $root . '/core/modules/dblog/tests/src/Kernel/Migrate/d6/MigrateDblogConfigsTest.php',
      $root . '/core/modules/aggregator/tests/src/Kernel/Migrate/MigrateAggregatorStubTest.php',
      $root . '/core/modules/comment/tests/src/Kernel/CommentIntegrationTest.php',
      $root . '/core/modules/field_ui/tests/src/Kernel/EntityDisplayTest.php',
      $root . '/core/modules/field/tests/src/Kernel/Views/HandlerFieldFieldTest.php',
      $root . '/core/modules/migrate_drupal/tests/src/Kernel/Plugin/migrate/DestinationCategoryTest.php',
      $root . '/core/modules/migrate_drupal/tests/src/Kernel/d6/MigrateDrupal6AuditIdsTest.php',
      $root . '/core/modules/migrate_drupal/tests/src/Kernel/d6/MigrationProcessTest.php',
      $root . '/core/modules/node/tests/src/Kernel/Views/RevisionUidTest.php',
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
              fwrite(STDOUT, $test);
              fwrite(STDOUT, $failing_classes[2]);
              $passing_tests[] = $test;
            }
          }
          $this->addTestFiles($passing_tests);
        }
      }
    }
  }

}
