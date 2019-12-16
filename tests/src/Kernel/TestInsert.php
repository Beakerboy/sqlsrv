<?php

namespace Drupal\KernelTests\sqlsrv\Database;

use Drupal\KernelTests\Core\Database\InsertTest

/**
 * Tests the insert builder.
 *
 * @group Database
 */
class InsertTest extends InsertTest {

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
