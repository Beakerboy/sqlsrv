<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\Query\Condition as CoreCondition;
use Drupal\Driver\Database\sqlsrv\Select;
use Drupal\KernelTests\Core\Database\DatabaseTestBase;

class ConditionTest extends DatabaseTestBase {

  // Testing of custom Condition class in select->where already happens in core.

  // Test custom Condition class in select->having.

  /**
   * Confirms that we can properly nest custom conditional clauses.
   */
  public function testNestedConditions() {
    // This query should translate to:
    // "SELECT job FROM {test} WHERE name = 'Paul' AND (name REGEX '^P' OR age = 27)"
    // That should find only one record. Yes it's a non-optimal way of writing
    // that query but that's not the point!
    $query = $this->connection->select('test');
    $query->addField('test', 'job');
    $query->condition('name', 'Paul');
    $query->condition(($this->connection->condition('OR'))->condition('name', '^P', 'REGEXP')->condition('age', 27));

    $job = $query->execute()->fetchField();
    $this->assertEqual($job, 'Songwriter', 'Correct data retrieved.');
  }

  // Test custom Condition class in delete->where.

  // Test custom Condition class in merge.

  // Test custom Condition class in update->where.
  
  // Test that multiple LIKE statements throws exception when escaped by backslash. 
  public function testPdoBugExists() {
    // Extend Connection with new $sqlsrvConditionOperatorMap array
    $connection = $this->connection;
    $reflection = new \ReflectionClass($connection);
    $reflection_property = $reflection->getProperty('sqlsrvConditionOperatorMap');
    $reflection_property->setAccessible(true);
    $desired_operator_map = ['LIKE' => ['postfix' => " ESCAPE '\\'"]];
    $reflection_property->setValue($connection, $desired_operator_map);
    
    // Set Condition to use parent::compile()
    $condition = new CoreCondition('AND');
    
    $query = new Select('test', 't', $connection);
    $reflection = new \ReflectionClass($query);
    $reflection_property = $reflection->getProperty('condition');
    $reflection_property->setAccessible(true);
    $reflection_property->setValue($query, $condition);

    // Expect exception when executing query;
    // Should specify what type.
    // $this->expectException(\Exception:class);
    
    // Create and execute buggy query
    $results = $query->addFields('t', ['job'])
      ->condition('job', '%i%', 'LIKE')
      ->condition('name', '%o%', 'LIKE')
      ->execute();
  }
}
