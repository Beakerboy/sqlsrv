<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\KernelTestBase;

class SelectTest extends KernelTestBase {

  // Test custom Condition class in where.

  // Test custom Condition class in having.

  // Test custom Condition class on nested conditions.
  /**
   * Confirms that we can properly nest conditional clauses.
   */
  public function testNestedConditions() {
    // This query should translate to:
    // "SELECT job FROM {test} WHERE name = 'Paul' AND (name REGEX '^P' OR age = 27)"
    // That should find only one record. Yes it's a non-optimal way of writing
    // that query but that's not the point!
    $query = $this->connection->select('test');
    $query->addField('test', 'job');
    $query->condition('name', 'Paul');
    $query->condition(($this->connection->condition('OR'))->condition('name', '^P', 'REGEX')->condition('age', 27));

    $job = $query->execute()->fetchField();
    $this->assertEqual($job, 'Songwriter', 'Correct data retrieved.');
  }
}
