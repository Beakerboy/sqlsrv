<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

use PHPUnit\Framework\TestSuite;
use Drupal\Core\Test\TestDiscovery;

/**
 * Base class for Drupal test suites.
 */
abstract class TestSuiteBase extends TestSuite {

  /**
   * Regex patterns to split up core Kernel extensions.
   *
   * @var array
   */
  protected static $coreExtensionPatterns = [
    '[a-i]',
    '[j-q]',
    '[r-z]',
  ];

  /**
   * The failing test files.
   *
   * @var array
   */
  protected $failingClasses = [
    // Kernel Test Failures.
    '/core/tests/Drupal/KernelTests/Core/Database/SelectSubqueryTest.php',
    '/core/tests/Drupal/KernelTests/Core/Database/SchemaTest.php',
    '/core/modules/aggregator/tests/src/Kernel/Migrate/MigrateAggregatorStubTest.php',
    '/core/modules/field/tests/src/Kernel/Views/HandlerFieldFieldTest.php',
    '/core/modules/migrate_drupal/tests/src/Kernel/d7/FieldDiscoveryTest.php',
    '/core/modules/field_ui/tests/src/Kernel/EntityDisplayTest.php',
    // Functional Test Failures.
    '/core/modules/datetime/tests/src/Functional/Views/FilterDateTest.php',
    '/core/modules/language/tests/src/Functional/ConfigurableLanguageManagerTest.php',
    '/core/modules/locale/tests/src/Functional/LocaleLocaleLookupTest.php',
    '/core/modules/path/tests/src/Functional/PathAliasTest.php',
    '/core/modules/search/tests/src/Functional/SearchConfigSettingsFormTest.php',
    '/core/modules/system/tests/src/Functional/Module/InstallUninstallTest.php',
    '/core/modules/views_ui/tests/src/Functional/UnsavedPreviewTest.php',
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

  /**
   * Find and add tests to the suite for core and any extensions.
   *
   * @param string $root
   *   Path to the root of the Drupal installation.
   * @param string $suite_namespace
   *   SubNamespace used to separate test suite. Examples: Unit, Functional.
   * @param int $index
   *   The chunk number to test.
   */
  protected function addExtensionTestsBySuiteNamespaceAndChunk($root, $suite_namespace, $index = 0) {
    $failing_classes = [];
    foreach ($this->failingClasses as $failing_class) {
      $failing_classes[] = $root . $failing_class;
    }
    // Extensions' tests will always be in the namespace
    // Drupal\Tests\$extension_name\$suite_namespace\ and be in the
    // $extension_path/tests/src/$suite_namespace directory. Not all extensions
    // will have all kinds of tests.
    $passing_tests = [];
    foreach ($this->findExtensionDirectories($root) as $extension_name => $dir) {
      $test_path = "$dir/tests/src/$suite_namespace";
      if (is_dir($test_path)) {
        $tests = TestDiscovery::scanDirectory("Drupal\\Tests\\$extension_name\\$suite_namespace\\", $test_path);
        foreach ($tests as $test) {
          if (!in_array($test, $failing_classes)) {
            $passing_tests[] = $test;
          }
        }
      }
    }
    $sizes = [
      17, 34, 25, 30, 30,
      25, 25, 25, 30, 25,
      25, 15, 25, 25, 25,
      25, 25, 25, 30, 25,
      25, 25, 25, 25, 25,
      25, 25, 25, 25, 25,
      25, 25, 25, 20, 20,
      25, 25, 25, 25, 25,
      25, 25, 25, 25, 25,
      30, 25, 25, 25, 25,
      25, 25, 25, 25, 25,
      25, 25, 25, 25,
    ];
    $index = rand(0, 58);
    $length = $sizes[$index];
    $offset = $index == 0 ? 0 : array_sum(array_splice($sizes, 0, $index));
    $subset = array_splice($passing_tests, $offset, $length);
    fwrite(STDOUT, "SPLICE:" . $index);
    $this->addTestFiles($subset);
  }

  /**
   * Get the path to webroot.
   *
   * @return string
   *   Path to webroot.
   */
  protected static function getDrupalRoot() {
    return dirname(__DIR__, 5);
  }

}
