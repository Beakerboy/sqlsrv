<?php

namespace Drupal\KernelTests\sqlsrv\Database;

use Drupal\KernelTests\Core\Database\DatabaseTestBase

/**
 * Tests the insert builder.
 *
 * @group Database
 */
class InsertTest extends DatabaseTestBase {

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
   * Tests very basic insert functionality.
   */
  public function testSimpleInsert() {
    $num_records_before = $this->connection->query('SELECT COUNT(*) FROM {test}')->fetchField();

    $query = $this->connection->insert('test');
    $query->fields([
      'name' => 'Yoko',
      'age' => '29',
    ]);

    // Check how many records are queued for insertion.
    $this->assertIdentical($query->count(), 1, 'One record is queued for insertion.');
    $query->execute();

    $num_records_after = $this->connection->query('SELECT COUNT(*) FROM {test}')->fetchField();
    $this->assertSame($num_records_before + 1, (int) $num_records_after, 'Record inserts correctly.');
    $saved_age = $this->connection->query('SELECT age FROM {test} WHERE name = :name', [':name' => 'Yoko'])->fetchField();
    $this->assertIdentical($saved_age, '29', 'Can retrieve after inserting.');
  }
}
