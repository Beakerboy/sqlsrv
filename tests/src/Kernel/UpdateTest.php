<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests the update query builder.
 *
 * @group Database
 */
class UpdateTest extends DatabaseTestBase {

  /**
   * Expect an exception when updating a primary key.
   */
  public function testPrimaryKeyUpdate() {
    $this->expectException(\Exception::class);
    $num_updated = $this->connection->update('test')
      ->fields(['id' => 42, 'name' => 'John'])
      ->condition('id', '1')
      ->execute();
  }

  /**
   * Tests namespace of the condition object.
   */
  public function testNamespaceConditionObject() {
    $namespace = (new \ReflectionObject($this->connection))->getNamespaceName() . "\\Condition";
    $update = $this->connection->update('test');

    $reflection = new \ReflectionObject($update);
    $condition_property = $reflection->getProperty('condition');
    $condition_property->setAccessible(TRUE);
    $this->assertIdentical($namespace, get_class($condition_property->getValue($update)));

    $nested_and_condition = $update->andConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_and_condition));
    $nested_or_condition = $update->orConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_or_condition));
  }

}
