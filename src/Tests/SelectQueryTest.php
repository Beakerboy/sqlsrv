<?php

/**
 * @file
 * Definition of Drupal\sqlsrv\Tests\SelectQueryTest.
 */

namespace Drupal\sqlsrv\Tests;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Core\Database\Database;

/**
 * General tests for SQL Server database driver.
 *
 * @group SQLServer
 */
class SelectQueryTest extends KernelTestBase {

  public static function getInfo() {
    return [
      'name' => 'SQLServer select queries',
      'description' => 'Ensure that SQLServer retrieves data properly.',
      'group' => 'SQLServer',
    ];
  }

  /**
   * The select query object to test.
   *
   * @var \Drupal\Core\Database\Query\Select
   */
  protected $connection;

  /**
   * {@inheritdoc}
   */
  public function setUp() {

    parent::setUp();
    
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

  /**
   * The current connection object.
   * 
   * @return \Drupal\Driver\Database\sqlsrv\Connection
   */
  protected function getConnection() {
    /** @var \Drupal\Driver\Database\sqlsrv\Connection */
    $connection = \Drupal\Core\Database\Database::getConnection();
    return $connection;
  }

  /**
   * Checks that invalid sort directions in ORDER BY get converted to ASC.
   */
  public function testGroupByExpansion() {
    

    // By ANSI SQL, GROUP BY columns cannot use aliases. Test that the
    // driver expands the aliases properly.
    $query = db_select('test_task', 't');
    $count_field = $query->addExpression('COUNT(task)', 'num');
    $task_field = $query->addExpression('CONCAT(:prefix, t.task)', 'task', array(':prefix' => 'Task: '));
    $query->orderBy($count_field);
    $query->groupBy($task_field);
    $result = $query->execute();

    $num_records = 0;
    $last_count = 0;
    $records = array();
    foreach ($result as $record) {
      $num_records++;
      $this->assertTrue($record->$count_field >= $last_count, 'Results returned in correct order.');
      $last_count = $record->$count_field;
      $records[$record->$task_field] = $record->$count_field;
    }

    $correct_results = array(
      'Task: eat' => 1,
      'Task: sleep' => 2,
      'Task: code' => 1,
      'Task: found new band' => 1,
      'Task: perform at superbowl' => 1,
    );

    foreach ($correct_results as $task => $count) {
      $this->assertEqual($records[$task], $count, "Correct number of '@task' records found.");
    }

    $this->assertEqual($num_records, 5, 'Returned the correct number of total rows.');
  }

  /**
   * Test cross join.
   */
  public function testCrossJoin() {
    // SelectQuery in SQL Server driver
    // is expanding expressions into a cross
    // join statement. This allows the use
    // of these expressions in the Aggregate
    // or Where part of the query.
    $query = db_select('test_task', 't');
    // Cast the task to an accent insensitive collation in an expression.
    $query->addExpression('(t.task collate Latin1_General_CS_AI)', 'ci_task');
    // Add condition over that expression.
    $query->where('ci_task = :param', array(':param' => 'slëep'));

    $result = $query->execute();

    $this->assertEqual(count($result), 2, t('Returned the correct number of total rows.'));
    
    // There is a special case, if the query is an aggregate
    // and an expression is used, this expression must be part of the aggregate.
    $query = db_select('test_task', 't');
    // Cast the task to an accent insensitive collation in an expression.
    $query->addExpression('(t.task collate Latin1_General_CS_AI)', 'ci_task');
    // Add condition over that expression.
    $query->where('ci_task = :param', array(':param' => 'slëep'));
    // Add condition over that expression.
    $query->groupBy('t.task');
    
    $result = $query->execute();

    $this->assertEqual(count($result), 1, t('Returned the correct number of total rows.'));
  }
  
  /**
   * Test the 2100 parameter limit per query.
   */
  public function testParameterLimit() {
    $values = array();
    for ($x = 0; $x < 2200; $x ++) {
      $values[] = uniqid($x, TRUE);
    }
    $query = db_select('test_task', 't');
    $query->addExpression('COUNT(task)', 'num');
    $query->where('t.task IN (:data)', array(':data' => $values));
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
    $query = db_select('test_task', 't');
    $query->addExpression('COUNT(task)', 'num');
    $query->where('t.task IN (:data0, :data0)', array(':data0' => 'sleep'));
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
   * Test for weird key names
   * in array arguments.
   */
  public function testBadKeysInArrayArguments() {
    $params[':nids'] = array(
        'uid1' => -9,
        'What a bad placeholder name, why should we care?' => -6,
        );
    $result = NULL;
    try {
      // The regular expandArguments implementation will fail to
      // properly expand the associative array with weird keys, OH, and actually
      // you can perform some SQL Injection through the array keys.
      $result = db_query('SELECT COUNT(*) FROM USERS WHERE USERS.UID IN (:nids)', $params)->execute()->fetchField();
    } 
    catch (\Exception $err) {
      // Regular drupal will fail with
      // SQLSTATE[IMSSP]: An error occurred substituting the named parameters.
      // https://www.drupal.org/node/2146839
    }

    // User ID's are negative, so this should return 0 matches.
    $this->assertEqual($result, 0, 'Returned the correct number of total rows.');
  }
  
  
  /**
   * Test the temporary table functionality.
   */
  public function testTemporaryTables() {
    
    $query = db_select('test_task', 't');
    $query->fields('t');
    
    $table = db_query_temporary((string) $query);
    
    // First assert that the table exists
    $this->assertTRUE(db_table_exists($table), 'The temporary table exists.');
    
    $query2 = db_select($table, 't');
    $query2->fields('t');
    
    // Now make sure that both tables are exactly the same.
    $data1 = $query->execute()->fetchAllAssoc('id');
    $data2 = $query2->execute()->fetchAllAssoc('id');

    // User ID's are negative, so this should return 0 matches.
    $this->assertEqual(count($data1), count($data2), 'Temporary table has the same number of rows.');
    // $this->assertEqual(count($data1[0]), count($data2[0]), 'Temporary table has the same number of columns.');
    
    // Drop the table.
    db_drop_table($table);
    
    // The table should not exist now.
    $this->assertFALSE(db_table_exists($table), 'The temporary table does not exists.');
  }

  public function testSequence() {
    
    $connection = $this->getConnection();

    $sequence1 = 'firstsequence';
    $sequence2 = 'secondsequence';

    $this->assertEquals(1, $connection->nextId(0, $sequence1));
    $this->assertEquals(2, $connection->nextId(0, $sequence1));
    $this->assertEquals(3, $connection->nextId(0, $sequence1));
    $this->assertEquals(4, $connection->nextId(0, $sequence1));
    $this->assertEquals(5, $connection->nextId(0, $sequence1));

    $this->assertEquals(10, $connection->nextId(9, $sequence1));
    $this->assertEquals(11, $connection->nextId(5, $sequence1));
    $this->assertEquals(12, $connection->nextId(3, $sequence1));

    $this->assertEquals(4, $connection->nextId(3, $sequence2));
    $this->assertEquals(5, $connection->nextId(3, $sequence2));
    $this->assertEquals(6, $connection->nextId(3, $sequence2));

    $this->assertEquals(13, $connection->nextId(3, $sequence1));
  }

  /**
   * Test LIKE statement wildcards are properly escaped.
   */
  public function testEscapeLike() {
    // Test expected escaped characters
    $string = 't[e%s]t_\\';
    $expected = 't[[]e[%]s[]]t[_]\\';
    $actual = db_like($string);
    $this->assertEqual($actual, $expected, 'Properly escaped LIKE statement wildcards.');

    db_insert('test_task')
      ->fields(array(
        'task' => 'T\\est',
      ))
      ->execute();

    $query = db_select('test_task', 't');
    $query->fields('t');
    $query->condition('t.task', db_like('T\\est'), 'LIKE');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 1, 'db_select returned the correct number of total rows.');

    db_insert('test_task')
      ->fields(array(
        'task' => 'T\'est',
      ))
      ->execute();

    $query = db_select('test_task', 't');
    $query->fields('t');
    $query->condition('t.task', db_like('T\'est'), 'LIKE');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 1, 'db_select returned the correct number of total rows.');

    // db_select: Test unescaped wildcard.
    $query = db_select('test_task', 't');
    $query->condition('t.task', '[s]leep', 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, 'db_select returned the correct number of total rows.');

    // db_select: Test unescaped wildcard.
    $query = db_select('test_task', 't');
    $query->condition('t.task', '[s]leep', 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, 'db_select returned the correct number of total rows.');

    // db_select: Test escaped wildcard.
    $query = db_select('test_task', 't');
    $query->condition('t.task', db_like('[s]leep'), 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 0, 'db_select returned the correct number of total rows.');

    // db_select->where: Test unescaped wildcard.
    $query = db_select('test_task', 't');
    $query->where('t.task LIKE :task', array(':task' => '[s]leep'));
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, 'db_select returned the correct number of total rows.');

    // db_select->where: Test escaped wildcard.
    $query = db_select('test_task', 't');
    $query->where('t.task LIKE :task', array(':task' => db_like('[s]leep')));
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 0, 'db_select returned the correct number of total rows.');

    // db_query: Test unescaped wildcard.
    $query = db_query('SELECT COUNT(*) FROM {test_task} WHERE task LIKE :task',
      array(':task' => '[s]leep'));
    $result = $query->fetchField();
    $this->assertEqual($result, 2, 'db_query returned the correct number of total rows.');

    // db_query: Test escaped wildcard.
    $query = db_query('SELECT COUNT(*) FROM {test_task} WHERE task LIKE :task',
      array(':task' => db_like('[s]leep')));
    $result = $query->fetchField();
    $this->assertEqual($result, 0, 'db_query returned the correct number of total rows.');
  }
}
