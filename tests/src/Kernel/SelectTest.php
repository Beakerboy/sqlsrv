<?php

namespace Drupal\KernelTests\sqlsrv\Database;

use Drupal\KernelTests\Core\Database\SelectTest as CoreSelectTest;

/**
 * Tests the select builder.
 *
 * @group Database
 */
class SelectTest extends CoreSelectTest {
  /**
   * The modules to load to run the test.
   *
   * @var array
   */
  public static $modules = [
    'sqlsrv',
    'database_test',
  ];
}
