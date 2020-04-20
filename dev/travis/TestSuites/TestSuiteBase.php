<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

use PHPUnit\Framework\TestSuite;

/**
 * Base class for Drupal test suites.
 */
abstract class TestSuiteBase extends TestSuite {

  protected $failing_classes = [
    'core/tests/Drupal/KernelTests/Core/Database/SelectSubqueryTest.php',
    'core/tests/Drupal/KernelTests/Core/Database/SchemaTest.php',
    'core/modules/dblog/tests/src/Kernel/Migrate/d6/MigrateDblogConfigsTest.php',
    'core/modules/aggregator/tests/src/Kernel/Migrate/MigrateAggregatorStubTest.php',
    'core/modules/comment/tests/src/Kernel/CommentIntegrationTest.php',
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

}
