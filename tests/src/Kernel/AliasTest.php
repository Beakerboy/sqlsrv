<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Test aliases within GROUP BY and ORDER BY.
 *
 * @group Database
 */
class AliasTest extends DatabaseTestBase {

  /**
   * Test GROUP BY alias expansion.
   *
   * Drupal allows users to specify aliases in GROUP BY.
   * By ANSI SQL, GROUP BY columns cannot use aliases. Test that the
   * driver expands the aliases properly.
   */
  public function testGroupByExpansion() {
    // By ANSI SQL, GROUP BY columns cannot use aliases. Test that the
    // driver expands the aliases properly.
    $query = $this->connection->select('test_task', 't');
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
      'Task: sing' => 1,
      'Task: perform at superbowl' => 1,
    ];

    foreach ($correct_results as $task => $count) {
      $this->assertEqual($records[$task], $count, "Correct number of '@task' records found.");
    }
    $this->assertEqual($num_records, 6, 'Returned the correct number of total rows.');
  }

}
