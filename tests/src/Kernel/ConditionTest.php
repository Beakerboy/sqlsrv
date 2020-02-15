<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;

class ConditionTest extends DatabaseTestBase {

  // Testing custom Condition class in select where already happens in core.

  // Test custom Condition class in select having.

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

  // Test custom Condition class in delete where.

  // Test custom Condition class in merge.

  // Test custom Condition class in update where.

}