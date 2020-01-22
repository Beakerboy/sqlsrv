<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests Drupal's extended prepared statement syntax..
 *
 * @group Database
 */
class QueryTest extends DatabaseTestBase {

  /**
   * Tests numeric query parameter expansion in expressions.
   *
   * @see \Drupal\Core\Database\Driver\sqlite\Statement::getStatement()
   * @see http://bugs.php.net/bug.php?id=45259
   */
  public function testNumericExpressionSubstitution() {
    $count = $this->connection->query('SELECT count(*) + :count FROM {test}', [
      ':count' => 3,
    ])->fetchField();
    $this->assertEqual($count, 6);
  }

}
