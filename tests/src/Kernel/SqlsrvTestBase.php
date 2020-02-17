<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\DatabaseTestBase;

class SqlsrvTestBase extends DatabaseTestBase {

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    // Get a connection to use during testing.
    $connection = Database::getConnection();
    
    $table_spec = array(
        'fields' => array(
          'id'  => array(
            'type' => 'serial',
            'not null' => TRUE,
          ),
          'task' => array(
            'type' => 'varchar',
            'length' => 255,
            'not null' => TRUE,
          ),
        ),
      );

    db_create_table('test_task', $table_spec);
    db_insert('test_task')->fields(array('task' => 'eat'))->execute();
    db_insert('test_task')->fields(array('task' => 'sleep'))->execute();
    db_insert('test_task')->fields(array('task' => 'sleep'))->execute();
    db_insert('test_task')->fields(array('task' => 'code'))->execute();
    db_insert('test_task')->fields(array('task' => 'found new band'))->execute();
    db_insert('test_task')->fields(array('task' => 'perform at superbowl'))->execute();
  }

}
