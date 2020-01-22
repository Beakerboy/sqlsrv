<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\QueryTest as DrupalQueryTest;

/**
 * Tests Drupal's extended prepared statement syntax..
 *
 * @group Database
 */
class QueryTest extends DrupalQueryTest {

  /**
   *{@inheritdoc)
   */
  public function testNumericExpressionSubstitution() {
    $direct_count = $this->connection->query('SELECT count(*) + 3 FROM {test}')->fetchField();
    $sql = 'SELECT count(*) + :count FROM {test}';
    $fields = [':count' => 3];
    $substituted_count = $this->connection->query($sql, $fields)->fetchField();
    $this->assertEqual($substituted_count, $direct_count);
  }

}
