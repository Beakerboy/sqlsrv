<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests of the sqlsrv database system.
 *
 * @group Database
 */
class ConnectionTest extends DatabaseTestBase {

  /**
   * Tests ::condition()
   *
   * Test that the method ::condition() returns a Condition object from the
   * driver directory.
   */
  public function testCondition() {
    $db = Database::getConnection('default', 'default');
    $namespace = (new \ReflectionObject($db))->getNamespaceName() . "\\Condition";

    $condition = $db->condition('AND');
    $this->assertIdentical($namespace, get_class($condition));

    $nested_and_condition = $condition->andConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_and_condition));
    $nested_or_condition = $condition->orConditionGroup();
    $this->assertIdentical($namespace, get_class($nested_or_condition));
  }

}
