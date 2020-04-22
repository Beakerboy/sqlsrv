<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;
use Drupal\Driver\Database\sqlsrv\Condition;

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
   */
  public function testTemporaryTables() {

    $query = $this->connection->select('test_task', 't');
    $query->fields('t');

    $table = $this->connection->queryTemporary((string) $query);

    // First assert that the table exists.
    $this->assertTRUE(db_table_exists($table), 'The temporary table exists.');

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
    $this->assertFALSE($this->connection->schema()->tableExists($table), 'The temporary table does not exists.');
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

  public function testStraightPrepared() {
    $prefix = 'test7472525';
    $prefixed_table = $prefix . 'tablename';
    $create_sql = "CREATE TABLE $prefixed_table (id int NOT NULL PRIMARY KEY, name varchar(20))";
    $dbh = new \PDO("sqlsrv:Server=localhost;Database=mydrupalsite", "sa", "Password12!");
    $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    $sql = "INSERT INTO $prefixed_table (name) VALUES (:placeholder_0, :placeholder_1), (:placeholder_2, :placeholder_3)";
    $args = [
      ':placeholder_0' => 0,
      ':placeholder_1' => 'Paul',
      ':placeholder_2' => 1,
      ':placeholder_3' => 'John',
    ];
    $dbh->exec($create_sql);
    $sth = $dbh->prepare($sql);
    $sth->execute($args);
    $select_sql = "SELECT * FROM $prefixed_table";
    $res = $dbh->query($select_sql)->fetchAll();
    fwrite(STDOUT, print_r($res, TRUE));
    $sql = "MERGE $prefixed_table AS tgt USING(VALUES (:db_upsert_placeholder_0, :db_upsert_placeholder_1), (:db_upsert_placeholder_2, :db_upsert_placeholder_3)) AS src (id, name) ON tgt.id=src.id WHEN MATCHED THEN UPDATE SET id=src.id, name=src.name WHEN NOT MATCHED THEN INSERT (id, name) VALUES (src.id, src.name);";
    $sth = $dbh->prepare($sql);
    $args = [
      ':placeholder_0' => 0,
      ':placeholder_1' => 'Ringo',
      ':placeholder_2' => 3,
      ':placeholder_3' => 'George',
    ];
    $sth->execute($args);
    $res = $dbh->query($select_sql)->fetchAll();
    fwrite(STDOUT, print_r($res, TRUE));
    $this->assertTrue(FALSE);
  }

}
