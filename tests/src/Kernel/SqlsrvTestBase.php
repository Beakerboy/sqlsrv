<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\DatabaseTestBase;

class SqlsrvTestBase extends DatabaseTestBase {

  protected $schema;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->schema = $this->connection->schema();
    $table_spec = [
        'fields' => [
          'id'  => [
            'type' => 'serial',
            'not null' => TRUE,
          ],
          'task' => [
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
          ],
        ],
      ];

    $this->connection->schema()->createTable('test_task', $table_spec);
    $this->connection->insert('test_task')->fields(['task' => 'eat'])->execute();
    $this->connection->insert('test_task')->fields(['task' => 'sleep'])->execute();
    $this->connection->insert('test_task')->fields(['task' => 'sleep'])->execute();
    $this->connection->insert('test_task')->fields(['task' => 'code'])->execute();
    $this->connection->insert('test_task')->fields(['task' => 'found new band'])->execute();
    $this->connection->insert('test_task')->fields(['task' => 'perform at superbowl'])->execute();
  }
  
}
