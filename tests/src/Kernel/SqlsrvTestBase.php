<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Test aliases within GROUP BY and ORDER BY.
 *
 * @group Database
 */
class SqlsrvTestBase extends DatabaseTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['sqlsrv'];

  /**
   * {@inheritdoc}
   *
   * Skip any kernel tests if not running on the correct database.
   */
  protected function setup() {
    if (Database::getConnection()->databaseType() !== 'sqlsrv') {
      $this->markTestSkipped("This test only runs for MS SQL Server");
    }
  }

}
