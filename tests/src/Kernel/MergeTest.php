<?php

namespace Drupal\Tests\sqlsrv\Kernel;

/**
 * Tests the MERGE query builder.
 *
 * @group Database
 */
class MergeTest extends SqlsrvTestBase {

  /**
   * Tests namespace of the condition object.
   */
  public function testNamespaceConditionObject() {
    $namespace = (new \ReflectionObject($this->connection))->getNamespaceName() . "\\Condition";
    $merge = $this->connection->merge('test');

    $reflection = new \ReflectionObject($merge);
    $condition_property = $reflection->getProperty('condition');
    $condition_property->setAccessible(TRUE);
    $this->assertIdentical($namespace, get_class($condition_property->getValue($merge)));

    $nested_and_condition = $merge->andConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_and_condition));
    $nested_or_condition = $merge->orConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_or_condition));
  }

}
