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
      ->condition('id', 1)
      ->execute();
  }

}
