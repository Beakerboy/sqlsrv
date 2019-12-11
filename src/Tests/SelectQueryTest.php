<?php

namespace Drupal\sqlsrv\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\Core\Database\Database;

/**
 * General tests for SQL Server database driver.
 *
 * @group SQLServer
 */
class SelectQueryTest extends WebTestBase {

  /**
   *
   */
  public static function getInfo() {
    return [
      'name' => 'SQLServer form protections',
      'description' => 'Ensure that SQLServer protects site forms properly.',
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
    // Get a connection to use during testing.
    $connection = Database::getConnection();

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

    db_create_table('test_task', $table_spec);
    db_insert('test_task')->fields(['task' => 'eat'])->execute();
    db_insert('test_task')->fields(['task' => 'sleep'])->execute();
    db_insert('test_task')->fields(['task' => 'sleep'])->execute();
    db_insert('test_task')->fields(['task' => 'code'])->execute();
    db_insert('test_task')->fields(['task' => 'found new band'])->execute();
    db_insert('test_task')->fields(['task' => 'perform at superbowl'])->execute();
  }

  /**
   * Checks that invalid sort directions in ORDER BY get converted to ASC.
   */
  public function testGroupByExpansion() {

    // By ANSI SQL, GROUP BY columns cannot use aliases. Test that the
    // driver expands the aliases properly.
    $query = db_select('test_task', 't');
    $count_field = $query->addExpression('COUNT(task)', 'num');
    $task_field = $query->addExpression('CONCAT(:prefix, t.task)', 'task', [':prefix' => 'Task: ']);
    $query->orderBy($count_field);
    $query->groupBy($task_field);
    $result = $query->execute();

    $num_records = 0;
    $last_count = 0;
    $records = [];
    foreach ($result as $record) {
      $num_records++;
      $this->assertTrue($record->$count_field >= $last_count, 'Results returned in correct order.');
      $last_count = $record->$count_field;
      $records[$record->$task_field] = $record->$count_field;
    }

    $correct_results = [
      'Task: eat' => 1,
      'Task: sleep' => 2,
      'Task: code' => 1,
      'Task: found new band' => 1,
      'Task: perform at superbowl' => 1,
    ];

    foreach ($correct_results as $task => $count) {
      $this->assertEqual($records[$task], $count, "Correct number of '@task' records found.");
    }

    $this->assertEqual($num_records, 5, 'Returned the correct number of total rows.');
  }

  /**
   * Test the 2100 parameter limit per query.
   */
  public function testParameterLimit() {
    $values = [];
    for ($x = 0; $x < 2200; $x++) {
      $values[] = uniqid($x, TRUE);
    }
    $query = db_select('test_task', 't');
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
   * Although per official documentation you cannot send
   * duplicate placeholders in same query, this works in mySQL
   * and is present in some queries, even in core, wich have not
   * gotten enough attention.
   */
  public function testDuplicatePlaceholders() {
    $query = db_select('test_task', 't');
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
   * Test for weird key names
   * in array arguments.
   */
  public function testBadKeysInArrayArguments() {
    $params[':nids'] = [
      'uid1' => -9,
      'What a bad placeholder name, why should we care?' => -6,
    ];
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

    // First assert that the table exists.
    $this->assertTRUE(db_table_exists($table), 'The temporary table exists.');

    $query2 = db_select($table, 't');
    $query2->fields('t');

    // Now make sure that both tables are exactly the same.
    $data1 = $query->execute()->fetchAllAssoc('tid');
    $data2 = $query2->execute()->fetchAllAssoc('tid');

    // User ID's are negative, so this should return 0 matches.
    $this->assertEqual(count($data1), count($data2), 'Temporary table has the same number of rows.');
    // $this->assertEqual(count($data1[0]), count($data2[0]), 'Temporary table has the same number of columns.');
    // Drop the table.
    db_drop_table($table);

    // The table should not exist now.
    $this->assertFALSE(db_table_exists($table), 'The temporary table does not exists.');
  }

  /**
   * Test LIKE statement wildcards are properly escaped.
   */
  public function testEscapeLike() {
    // Test expected escaped characters.
    $string = 't[e%s]t_\\';
    $expected = 't[[]e[%]s[]]t[_]\\';
    $actual = db_like($string);
    $this->assertEqual($actual, $expected, 'Properly escaped LIKE statement wildcards.');

    db_insert('test_task')
      ->fields([
        'task' => 'T\\est',
      ])
      ->execute();

    $query = db_select('test_task', 't');
    $query->fields('t');
    $query->condition('t.task', db_like('T\\est'), 'LIKE');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 1, t('db_select returned the correct number of total rows.'));

    db_insert('test_task')
      ->fields([
        'task' => 'T\'est',
      ])
      ->execute();

    $query = db_select('test_task', 't');
    $query->fields('t');
    $query->condition('t.task', db_like('T\'est'), 'LIKE');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 1, t('db_select returned the correct number of total rows.'));

    // db_select: Test unescaped wildcard.
    $query = db_select('test_task', 't');
    $query->condition('t.task', '[s]leep', 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, t('db_select returned the correct number of total rows.'));

    // db_select: Test unescaped wildcard.
    $query = db_select('test_task', 't');
    $query->condition('t.task', '[s]leep', 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, t('db_select returned the correct number of total rows.'));

    // db_select: Test escaped wildcard.
    $query = db_select('test_task', 't');
    $query->condition('t.task', db_like('[s]leep'), 'LIKE');
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 0, t('db_select returned the correct number of total rows.'));

    // db_select->where: Test unescaped wildcard.
    $query = db_select('test_task', 't');
    $query->where('t.task LIKE :task', [':task' => '[s]leep']);
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 2, t('db_select returned the correct number of total rows.'));

    // db_select->where: Test escaped wildcard.
    $query = db_select('test_task', 't');
    $query->where('t.task LIKE :task', [':task' => db_like('[s]leep')]);
    $query->fields('t');
    $result = $query->execute()->fetchAll();
    $this->assertEqual(count($result), 0, t('db_select returned the correct number of total rows.'));

    // db_query: Test unescaped wildcard.
    $query = db_query('SELECT COUNT(*) FROM {test_task} WHERE task LIKE :task',
      [':task' => '[s]leep']);
    $result = $query->fetchField();
    $this->assertEqual($result, 2, t('db_query returned the correct number of total rows.'));

    // db_query: Test escaped wildcard.
    $query = db_query('SELECT COUNT(*) FROM {test_task} WHERE task LIKE :task',
      [':task' => db_like('[s]leep')]);
    $result = $query->fetchField();
    $this->assertEqual($result, 0, t('db_query returned the correct number of total rows.'));
  }

}
