<?php

namespace Drupal\Tests\sqlsrv\Unit;

use Drupal\sqlsrv\Driver\Database\sqlsrv\Select;
use Drupal\sqlsrv\Driver\Database\sqlsrv\Condition;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the orderBy() method of select queries.
 *
 * @group Database
 */
class OrderByTest extends UnitTestCase {

  /**
   * The select query object to test.
   *
   * @var \Drupal\Core\Database\Query\Select
   */
  protected $query;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    $connection = $this->getMockBuilder('Drupal\sqlsrv\Driver\Database\sqlsrv\Connection')
      ->setMethods(['condition'])
      ->disableOriginalConstructor()
      ->getMockForAbstractClass();
    $condition = new Condition('AND');
    $connection->method('condition')->willReturn($condition);
    $this->query = new Select($connection, 'test', 'test');
  }

  /**
   * Checks that invalid sort directions in ORDER BY get converted to ASC.
   */
  public function testInvalidDirection() {
    $this->query->orderBy('test', 'invalid direction');
    $order_bys = $this->query->getOrderBy();
    $this->assertEquals($order_bys['test'], 'ASC', 'Invalid order by direction is converted to ASC.');
  }

  /**
   * Tests that fields passed for ordering get escaped properly.
   */
  public function testFieldEscaping() {
    $this->query->orderBy('x; DROP table node; --');
    $sql = $this->query->__toString();
    $this->assertStringEndsWith('ORDER BY [xDROPtablenode] ASC', $sql, 'Order by field is escaped correctly.');
  }

}
