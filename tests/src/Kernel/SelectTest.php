<?php

namespace Drupal\KernelTests\sqlsrv\Database;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests the select builder.
 *
 * @group Database
 */
class SelectTest extends DatabaseTestBase {
  
  /**
   * The modules to load to run the test.
   *
   * @var array
   */
  public static $modules = [
    'sqlsrv',
    'database_test',
  ];

  /**
   * Tests rudimentary SELECT statements.
   */
  public function testSimpleSelect() {
    $query = $this->connection->select('test');
    $query->addField('test', 'name');
    $query->addField('test', 'age', 'age');
    $num_records = $query->countQuery()->execute()->fetchField();

    $this->assertEqual($num_records, 4, 'Returned the correct number of rows.');
  }
}
