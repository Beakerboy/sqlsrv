<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\StatementWrapper;
use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests the deprecations of the StatementWrapper class.
 *
 * @coversDefaultClass \Drupal\Core\Database\StatementWrapper
 * @group legacy
 * @group Database
 */
class StatementWrapperLegacyTest extends DatabaseTestBase {
  protected $statement;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->statement = $this->connection->prepareStatement('SELECT * FROM {test}', []);
    if (!$this->statement instanceof StatementWrapper) {
      $this->markTestSkipped('This test only works for drivers implementing Drupal\Core\Database\StatementWrapper.');
    }
  }

  /**
   * Tests calling a non existing \PDOStatement method.
   */
  public function testMissingMethod() {
    $PDO = new \PDOStatement();
    $this-assertFalse(is_callable([$PDO, 'uehdbsyyddh']);
  }

  /**
   * Tests calling an existing \PDOStatement method.
   */
  public function testClientStatementMethod() {
    $this->expectDeprecation('StatementWrapper::columnCount should not be called in drupal:9.1.0 and will error in drupal:10.0.0. Access the client-level statement object via ::getClientStatement(). See https://www.drupal.org/node/3177488');
    $this->statement->execute();
    $array = $this->statement->fetch(\PDO::FETCH_ASSOC);
    $this->assertEquals($array, array("1", "Foo"));
    $this->assertEquals(4, $this->statement->columnCount());
  }
}
