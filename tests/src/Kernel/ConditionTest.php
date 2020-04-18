<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\Query\Condition as CoreCondition;
use Drupal\Driver\Database\sqlsrv\Select;
use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Test the functions of the custom Condition class.
 *
 * @group Database
 */
class ConditionTest extends DatabaseTestBase {

  // Testing of custom Condition class in select->where already happens in core.
  // Test custom Condition class in select->having.

  /**
   * Confirms that we can properly nest custom conditional clauses.
   */
  public function testNestedConditions() {
    // This query should translate to:
    // SELECT job FROM {test} WHERE name='Paul' AND (name REGEX '^P' OR age=27)
    // That should find only one record. Yes it's a non-optimal way of writing
    // that query but that's not the point!
    $query = $this->connection->select('test');
    $query->addField('test', 'job');
    $query->condition('name', 'Paul');
    $query->condition(($this->connection->condition('OR'))->condition('name', '^P', 'REGEXP')->condition('age', '27'));

    $job = $query->execute()->fetchField();
    $this->assertEqual($job, 'Songwriter', 'Correct data retrieved.');
  }

  // Test custom Condition class in delete->where.
  // Test custom Condition class in merge.
  // Test custom Condition class in update->where.

  /**
   * Test presence of PDO Bug.
   *
   * @link https://bugs.php.net/bug.php?id=79276 Bug Report. @endlink
   *
   * This test will throw an exception while the PDO bug exists. When it is
   * fixed, the LIKE operator can safely use "ESCAPE '\'" and custom code within
   * the Condition class can be removed.
   */
  public function testPdoBugExists() {
    // Extend Connection with new $sqlsrvConditionOperatorMap array.
    $connection = $this->connection;
    $reflection = new \ReflectionClass($connection);
    $reflection_property = $reflection->getProperty('sqlsrvConditionOperatorMap');
    $reflection_property->setAccessible(TRUE);
    $desired_operator_map = ['LIKE' => ['postfix' => " ESCAPE '\\'"]];
    $reflection_property->setValue($connection, $desired_operator_map);

    // Set Condition to use parent::compile()
    $condition = new CoreCondition('AND');

    $query = new Select('test', 't', $connection);
    $reflection = new \ReflectionClass($query);
    $reflection_property = $reflection->getProperty('condition');
    $reflection_property->setAccessible(TRUE);
    $reflection_property->setValue($query, $condition);

    // Expect exception when executing query;
    // Should specify what type.
    $this->expectException(\Exception::class);

    // Create and execute buggy query.
    $query->addField('t', 'job');
    $query->condition('job', '%i%', 'LIKE');
    $query->condition('name', '%o%', 'LIKE');
    $query->execute();
  }

  /**
   * Ensure that the sqlsrv driver can execute queries with multiple escapes.
   *
   * Core tests already do this, but good to double check.
   */
  public function testPdoBugFix() {
    $connection = $this->connection;

    $query = new Select('test', 't', $connection);

    // Create and execute buggy query.
    $query->addField('t', 'job');
    $query->condition('job', '%i%', 'LIKE');
    $query->condition('name', '%o%', 'LIKE');
    $result = $query->execute();

    // Asserting that no exception is thrown. Is there a better way?
    // Should actually review results.
    $this->assertTrue(TRUE);
  }

  /**
   * Test that brackets are escaped correctly.
   */
  public function testLikeWithBrackets() {
    $this->connection->insert('test_people')
      ->fields([
        'job' => '[Rutles] - Guitar',
        'name' => 'Dirk',
      ])
      ->execute();
    $name = $this->connection->select('test_people', 't')
      ->fields('t', ['name'])
      ->condition('job', '%[Rutles%', 'LIKE')
      ->execute()
      ->fetchField();
    $this->assertEqual('Dirk', $name);
    $this->connection->insert('test_people')
      ->fields([
        'job' => '[Rutles] - Drummer [Original]',
        'name' => 'Kevin',
      ])
      ->execute();
    $names = $this->connection->select('test_people', 't')
      ->fields('t', ['name', 'job'])
      ->condition('job', '%[Rutles]%', 'LIKE')
      ->execute()
      ->fetchAllAssoc('job');
    $this->assertCount(2, $names);
  }

}
