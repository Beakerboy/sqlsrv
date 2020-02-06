<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;
/**
 * Tests the Select query builder.
 *
 * @group Database
 */
class SelectSubqueryTest extends DatabaseTestBase {

  public function testIntegerAverage() {
    // Create a subquery, which is just a normal query object.
    $query = $this->connection->select('test', 't2');
    $query->addExpression('AVG(t2.age)');
    $average = $query->execute()->fetchField();
    $this->assertEqual($average, '26.5');
  }
  
  public function testMultipleIntegerAverage() {
    // Create a subquery, which is just a normal query object.
    $query = $this->connection->select('test', 't2');
    $query->addExpression('AVG(t2.age) + AVG(t2.age + 1)');
    $average = $query->execute()->fetchField();
    $this->assertEqual($average, '54');
  }
  
}
