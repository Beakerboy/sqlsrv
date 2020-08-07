<?php

namespace Drupal\Tests\sqlsrv\Kernel;

/**
 * Tests the Select query builder.
 *
 * @group Database
 */
class SelectTest extends SqlsrvTestBase {

  /**
   * Tests namespace of the condition and having objects.
   */
  public function testNamespaceConditionAndHavingObjects() {
    $namespace = (new \ReflectionObject($this->connection))->getNamespaceName() . "\\Condition";
    $select = $this->connection->select('test');
    $reflection = new \ReflectionObject($select);

    $condition_property = $reflection->getProperty('condition');
    $condition_property->setAccessible(TRUE);
    $this->assertIdentical($namespace, get_class($condition_property->getValue($select)));

    $having_property = $reflection->getProperty('having');
    $having_property->setAccessible(TRUE);
    $this->assertIdentical($namespace, get_class($having_property->getValue($select)));

    $nested_and_condition = $select->andConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_and_condition));
    $nested_or_condition = $select->orConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_or_condition));
  }

}
