<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;
use Drupal\Driver\Database\sqlsrv\Condition;
/**
 * Test behavior that is unique to the Sql Server Driver.
 *
 * These tests may not pass on other drivers.
 */
class SqlsrvTest extends DatabaseTestBase {

  /**
   * Test the 2100 parameter limit per query.
   */
  public function testParameterLimit() {
    $values = [];
    for ($x = 0; $x < 2200; $x ++) {
      $values[] = uniqid($x, TRUE);
    }
    $query = $this->connection->select('test_task', 't');
    $query->addExpression('COUNT(task)', 'num');
    $query->where('t.task IN (:data)', [':data' => $values]);
    $result = NULL;
    // If > 2100 we can get SQL Exception! The driver must handle that.
    try {
      $result = $query->execute()->fetchField();
    
    } catch (\Exception $err)
    {
    }
    
    $this->assertEqual($result, 0, 'Returned the correct number of total rows.');
  }

  /**
   * Although per official documentation you cannot send
   * duplicate placeholders in same query, this works in mySQL
   * and is present in some queries, even in core, wich have not
   * gotten enough attention.
   */
  public function testDuplicatePlaceholders() {
    $query = $this->connection->select('test_task', 't');
    $query->addExpression('COUNT(task)', 'num');
    $query->where('t.task IN (:data0, :data0)', [':data0' => 'sleep']);
    $result = NULL;
    // If > 2100 we can get SQL Exception! The driver must handle that.
    try {
      $result = $query->execute()->fetchField();
    } 
    catch (\Exception $err) {
    }
    
    $this->assertEqual($result, 2, 'Returned the correct number of total rows.');
  }

  /**
   * Test the temporary table functionality.
   */
  public function testTemporaryTables() {
    
    $query = $this->connection->select('test_task', 't');
    $query->fields('t');
    
    $table = $this->connection->queryTemporary((string) $query);
    
    // First assert that the table exists
    $this->assertTRUE(db_table_exists($table), 'The temporary table exists.');
    
    $query2 = $this->connection->select($table, 't');
    $query2->fields('t');
    
    // Now make sure that both tables are exactly the same.
    $data1 = $query->execute()->fetchAllAssoc('tid');
    $data2 = $query2->execute()->fetchAllAssoc('tid');

    // User ID's are negative, so this should return 0 matches.
    $this->assertEqual(count($data1), count($data2), 'Temporary table has the same number of rows.');
    // $this->assertEqual(count($data1[0]), count($data2[0]), 'Temporary table has the same number of columns.');
    
    // Drop the table.
    $this->connection->schema()->dropTable($table);
    
    // The table should not exist now.
    $this->assertFALSE($this->connection->schema()->tableExists($table), 'The temporary table does not exists.');
  }
  
  /**
   * Test LIKE statement wildcards are properly escaped.
   */
  public function testEscapeLike() {
    // Test expected escaped characters
    $string = 't[e%s]t_\\';
    $escaped_string = $this->connection->escapeLike($string);
    $this->assertEqual($escaped_string, 't[e\%s]t\_\\\\', 'Properly escaped string with backslashes');
    $query = $this->connection->select('test_task', 't');
    $condition = new Condition('AND');
    $condition->condition('task', $escaped_string, 'LIKE');
    $condition->compile($this->connection, $query);
    $arguments = $condition->conditions();
    $argument = $arguments[0];
    
    $expected = 't[[]e[%]s[]]t[_]\\';
    $actual = $argument['value'];
    $this->assertEqual($actual, $expected, 'Properly escaped LIKE statement wildcards.');

    $this->connection->insert('test_task')
      ->fields(['task' => 'T\\est'])
      ->execute();

    $query = $this->connection->select('test_task', 't');
    $query->fields('t');
    $query->condition('t.task', $this->connection->escapeLike('T\\est'), 'LIKE');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 1, t('db_select returned the correct number of total rows.'));

    $this->connection->insert('test_task')
      ->fields(['task' => 'T\'est'])
      ->execute();

    $query = $this->connection->select('test_task', 't');
    $query->fields('t');
    $query->condition('t.task', $this->connection->escapeLike('T\'est'), 'LIKE');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 1, t('db_select returned the correct number of total rows.'));

    // db_select: Test unescaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->condition('t.task', '[s]leep', 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, t('db_select returned the correct number of total rows.'));

    // db_select: Test unescaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->condition('t.task', '[s]leep', 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, t('db_select returned the correct number of total rows.'));

    // db_select: Test escaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->condition('t.task', $this->connection->escapeLike('[s]leep'), 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 0, t('db_select returned the correct number of total rows.'));

    // db_select->where: Test unescaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->where('t.task LIKE :task', [':task' => '[s]leep']);
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, t('db_select returned the correct number of total rows.'));

    // db_select->where: Test escaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->where('t.task LIKE :task', [':task' => $this->connectionescapeLike('[s]leep')]);
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 0, t('db_select returned the correct number of total rows.'));

    // db_query: Test unescaped wildcard.
    $query = $this->connection->query('SELECT COUNT(*) FROM {test_task} WHERE task LIKE :task',
      [':task' => '[s]leep']);
    $result = $query->fetchField();
    $this->assertEqual($result, 2, t('db_query returned the correct number of total rows.'));

    // db_query: Test escaped wildcard.
    $query = $this->connection->query('SELECT COUNT(*) FROM {test_task} WHERE task LIKE :task',
      [':task' => $this->connection->escapeLike('[s]leep')]);
    $result = $query->fetchField();
    $this->assertEqual($result, 0, t('db_query returned the correct number of total rows.'));
  }

}
