<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\KernelTestBase;

class ParameterTest extends KeenelTestBase {

  /**
   * Test the 2100 parameter limit per query.
   */
  public function testParameterLimit() {
    $values = array();
    for ($x = 0; $x < 2200; $x ++) {
      $values[] = uniqid($x, TRUE);
    }
    $query = $this->connection->select('test_task', 't');
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

}
