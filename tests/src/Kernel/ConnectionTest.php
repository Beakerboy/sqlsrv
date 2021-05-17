<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseAccessDeniedException;
use Drupal\Core\Database\DatabaseNotFoundException;
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

  /**
   * Test createUrl.
   */
  public function testCreateUrlFromConnectionOptions() {
    $connection_array = [
      'driver' => 'sqlsrv',
      'database' => 'mydrupalsite',
      'username' => 'sa',
      'password' => 'Password12!',
      'host' => 'localhost',
      'schema' => 'dbo',
      'cache_schema' => 'true',
    ];
    $url = $this->connection->createUrlFromConnectionOptions($connection_array);
    $db_url = "sqlsrv://sa:Password12!@localhost/mydrupalsite?schema=dbo&amp;cache_schema=true";
    $this->assertEquals($db_url, $url);
  }

  /**
   * Test AccessDeniedException is thrown.
   */
  public function testAccessDeniedException() {
    $connection_array = [
      'driver' => 'sqlsrv',
      'database' => 'mydrupalsite',
      'username' => 'sa',
      'password' => 'incorrect!',
      'host' => 'localhost',
      'schema' => 'dbo',
      'cache_schema' => 'true',
    ];
    $this->expectException(DatabaseAccessDeniedException::class);
    // Generate an exception
    $this->connection->open($connection_array);
  }

  /**
   * Test PDOExceptions are rethrown.
   */
  public function testRethrowPDOException() {
    $connection_array = [
      'driver' => 'sqlsrv',
      'database' => 'mydrupalsite',
      'username' => 'sa',
      'password' => 'Password12!',
      'host' => '10.0.0.42',
      'schema' => 'dbo',
      'cache_schema' => 'true',
    ];
    $this->expectException(\PDOException::class);
    $this->expectExceptionCode('HYT00');
    // Generate an exception
    $this->connection->open($connection_array);
  }

  /**
   * Test DatabaseNotFoundException is thrown.
   */
  public function testDatabaseNotFoundException() {
    $connection_array = [
      'driver' => 'sqlsrv',
      'database' => 'incorrect',
      'username' => 'sa',
      'password' => 'Password12!',
      'host' => 'localhost',
      'schema' => 'dbo',
      'cache_schema' => 'true',
    ];
    $this->expectException(DatabaseNotFoundException::class);
    // Generate an exception
    $this->connection->open($connection_array);
  }

}
