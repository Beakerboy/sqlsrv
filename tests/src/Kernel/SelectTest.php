<?php
namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\InvalidQueryException;
use Drupal\Core\Database\Database;

use Drupal\Core\Database\Database\DatabaseTestBase;

/**
 * Tests the Select query builder.
 *
 * @group Database
 */
class SelectTest extends DatabaseTestBase {

  /**
   * Tests that an invalid merge query throws an exception.
   */
  public function testInvalidSelectCount() {
   
    // This query will fail because the table does not exist.
    // Normally it would throw an exception but we are suppressing
    // it with the throw_exception option.
    $options['throw_exception'] = FALSE;
    $this->connection->select('some_table_that_doesnt_exist', 't', $options)
      ->fields('t')
      ->countQuery()
      ->execute();
  }

}
