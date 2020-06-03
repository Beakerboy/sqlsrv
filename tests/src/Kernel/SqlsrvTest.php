<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Driver\Database\sqlsrv\Condition;
use Drupal\Driver\Database\sqlsrv\Connection;
use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Test behavior that is unique to the Sql Server Driver.
 *
 * These tests may not pass on other drivers.
 *
 * @group Database
 */
class SqlsrvTest extends DatabaseTestBase {

  /**
   * Test the 2100 parameter limit per query.
   */
  public function testParameterLimit() {
    $values = [];
    for ($x = 0; $x < 2200; $x++) {
      $values[] = uniqid(strval($x), TRUE);
    }
    $query = $this->connection->select('test_task', 't');
    $query->addExpression('COUNT(task)', 'num');
    $query->where('t.task IN (:data)', [':data' => $values]);
    $result = NULL;
    // If > 2100 we can get SQL Exception! The driver must handle that.
    try {
      $result = $query->execute()->fetchField();
    }
    catch (\Exception $err) {
    }

    $this->assertEqual($result, 0, 'Returned the correct number of total rows.');
  }

  /**
   * Test duplicate placeholders in queries.
   *
   * Although per official documentation you cannot send
   * duplicate placeholders in same query, this works in mySQL
   * and is present in some queries, even in core, which have not
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
   *
   * @dataProvider dataProviderForTestTemporaryTables
   */
  public function testTemporaryTables($temp_prefix, $leak_table) {
    // Set the temp table prefix on the Connection.
    $reflectionClass = new \ReflectionClass(Connection::class);
    $reflectionProperty = $reflectionClass->getProperty('tempTablePrefix');
    $reflectionProperty->setAccessible(TRUE);
    $reflectionProperty->setValue($this->connection, $temp_prefix);
    $reflectionMethod = $reflectionClass->getMethod('setPrefix');
    $reflectionMethod->setAccessible(TRUE);
    $prefixProperty = $reflectionClass->getProperty('prefixes');
    $prefixProperty->setAccessible(TRUE);

    $prefixes = $prefixProperty->getValue($this->connection);
    $reflectionMethod->invoke($this->connection, $prefixes);

    $query = $this->connection->select('test_task', 't');
    $query->fields('t');

    $table = $this->connection->queryTemporary((string) $query);

    // First assert that the table exists.
    $this->assertTRUE($this->connection->schema()->tableExists($table), 'The temporary table exists.');

    $query2 = $this->connection->select($table, 't');
    $query2->fields('t');

    // Now make sure that both tables are exactly the same.
    $data1 = $query->execute()->fetchAllAssoc('tid');
    $data2 = $query2->execute()->fetchAllAssoc('tid');

    // User ID's are negative, so this should return 0 matches.
    $this->assertEqual(count($data1), count($data2), 'Temporary table has the same number of rows.');

    // Drop the table.
    $this->connection->schema()->dropTable($table);

    // The table should not exist now.
    $this->assertFALSE($this->connection->schema()->tableExists($table), 'The temporary table does not exist');

    $schema = [
      'description' => 'Basic test table for the database unit tests.',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
      ],
    ];
    // Create a second independant connection.
    $connection_info = $this->getDatabaseConnectionInfo()['default'];
    Database::addConnectionInfo('second', 'second', $connection_info);
    Database::addConnectionInfo('third', 'third', $connection_info);

    $second_connection = Database::getConnection('second', 'second');
    $reflectionProperty->setValue($second_connection, $temp_prefix);
    $prefixes = $prefixProperty->getValue($second_connection);
    $reflectionMethod->invoke($second_connection, $prefixes);

    $third_connection = Database::getConnection('third', 'third');
    $reflectionProperty->setValue($third_connection, $temp_prefix);
    $prefixes = $prefixProperty->getValue($third_connection);
    $reflectionMethod->invoke($third_connection, $prefixes);

    // Ensure connections are unique.
    $connection_id1 = $this->connection->query('SELECT @@SPID AS [ID]')->fetchField();
    $connection_id2 = $second_connection->query('SELECT @@SPID AS [ID]')->fetchField();
    $connection_id3 = $third_connection->query('SELECT @@SPID AS [ID]')->fetchField();
    $this->assertNotEquals($connection_id2, $connection_id3, 'Connections 2 & 3 have different IDs.');
    $this->assertNotEquals($connection_id1, $connection_id3, 'Connections 1 & 3 have different IDs.');
    $this->assertNotEquals($connection_id2, $connection_id1, 'Connections 1 & 2 have different IDs.');

    // Create a temporary table in this connection.
    $table = $second_connection->queryTemporary((string) $query);
    // Is the temp table visible on the originating connection?
    $this->assertTrue($second_connection->schema()->tableExists($table), 'Temporary table exists.');

    // Create a normal table.
    $second_connection->schema()->createTable('real_table_for_temp_test', $schema);

    // Is the real table visible on the other connection?
    $this->assertTrue($third_connection->schema()->tableExists('real_table_for_temp_test'), 'Real table found across connections.');

    // Is the temp table visible on the other connection?
    $this->assertEquals($leak_table, $third_connection->schema()->tableExists($table), 'Temporary table leaking appropriately.');

    // Is the temp table still visible on the originating connection?
    $this->assertTrue($second_connection->schema()->tableExists($table), 'Temporary table still exists.');

    // Close the Connection that created the table and ensure that
    // it is removed.
    unset($second_connection);
    Database::removeConnection('second');

    // Next assertion has intermittent failures. Add a wait?
    sleep(2);
    $this->assertFalse($third_connection->schema()->tableExists($table), 'Temporary table removed when creation connection closes.');
  }

  /**
   * Provides data for testTemporaryTable().
   */
  public function dataProviderForTestTemporaryTables() {
    return [
      'local' => ['#', FALSE],
      'global' => ['##', TRUE],
      // Need a test where the prefix has periods.
    ];
  }

  /**
   * Test LIKE statement wildcards are properly escaped.
   */
  public function testEscapeLike() {
    // Test expected escaped characters.
    $string = 't[e%s]t_\\';
    $escaped_string = $this->connection->escapeLike($string);
    $this->assertEqual($escaped_string, 't[e\%s]t\_\\\\', 'Properly escaped string with backslashes');
    $query = $this->connection->select('test_task', 't');
    $condition = new Condition('AND');
    $condition->condition('task', $escaped_string, 'LIKE');
    $condition->compile($this->connection, $query);
    $arguments = $condition->conditions();
    $argument = $arguments[0];

    $expected = 't[[]e[%]s]t[_]\\';
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

    // Using the condition function requires that only % and _ can be used as
    // wildcards.
    // select->condition: Test unescaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->condition('t.task', '_leep', 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, t('db_select returned the correct number of total rows.'));

    // select->condition: Test escaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->condition('t.task', $this->connection->escapeLike('_leep'), 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 0, t('db_select returned the correct number of total rows.'));

    // Using the where function requires that database-specific notation be
    // used. This means we can use the SQL Server bracket notation, but these
    // queries will not be valid on other databases.
    // select->where: Test unescaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->where('t.task LIKE :task', [':task' => '[s]leep']);
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, t('db_select returned the correct number of total rows.'));

    // select->where: Test escaped wildcard.
    $query = $this->connection->select('test_task', 't');
    $query->where('t.task LIKE :task', [':task' => $this->connection->escapeLike('[[]s[]]leep')]);
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 0, t('db_select returned the correct number of total rows.'));

    // Using a static query also allows us to use database-specific syntax.
    // Again, queries may not run on other databases.
    // query: Test unescaped wildcard.
    $query = $this->connection->query('SELECT COUNT(*) FROM {test_task} WHERE task LIKE :task',
      [':task' => '[s]leep']);
    $result = $query->fetchField();
    $this->assertEqual($result, 2, t('db_query returned the correct number of total rows.'));

    // query: Test escaped wildcard.
    $query = $this->connection->query('SELECT COUNT(*) FROM {test_task} WHERE task LIKE :task',
      [':task' => $this->connection->escapeLike('[[]s[]]leep')]);
    $result = $query->fetchField();
    $this->assertEqual($result, 0, t('db_query returned the correct number of total rows.'));
  }

}
