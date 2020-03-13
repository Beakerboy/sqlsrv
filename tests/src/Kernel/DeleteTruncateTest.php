<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests delete and truncate queries.
 *
 * The DELETE tests are not as extensive, as all of the interesting code for
 * DELETE queries is in the conditional which is identical to the UPDATE and
 * SELECT conditional handling.
 *
 * The TRUNCATE tests are not extensive either, because the behavior of
 * TRUNCATE queries is not consistent across database engines. We only test
 * that a TRUNCATE query actually deletes all rows from the target table.
 *
 * @group Database
 */
class DeleteTruncateTest extends DatabaseTestBase {

  /**
   * Tests namespace of the condition object.
   */
  public function testNamespaceConditionObject() {
    $namespace = (new \ReflectionObject($this->connection))->getNamespaceName() . "\\Condition";
    $delete = $this->connection->delete('test');

    $reflection = new \ReflectionObject($delete);
    $condition_property = $reflection->getProperty('condition');
    $condition_property->setAccessible(TRUE);
    $this->assertIdentical($namespace, get_class($condition_property->getValue($delete)));

    $nested_and_condition = $delete->andConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_and_condition));
    $nested_or_condition = $delete->orConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_or_condition));
  }

}
