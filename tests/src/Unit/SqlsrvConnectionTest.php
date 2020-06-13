<?php

namespace Drupal\Tests\sqlsrv\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\sqlsrv\Driver\Database\sqlsrv\Connection;

/**
 * Test the behavior of the Connection class.
 *
 * These tests are not expected to pass on other database drivers.
 *
 * @group Database
 */
class SqlsrvConnectionTest extends UnitTestCase {

  /**
   * Mock PDO object for use in tests.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\Tests\Core\Database\Stub\StubPDO
   */
  protected $mockPdo;

  /**
   * Mock Schema object for use in tests.
   *
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\sqlsrv\Driver\Database\sqlsrv\Schema
   */
  protected $mockSchema;

  /**
   * Database connection options.
   *
   * The core test suite uses an empty array.
   * This module requires at least a value in:
   * $option['prefix']['default']
   *
   * @var array
   */
  protected $options;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->mockSchema = $this->getMockBuilder('Drupal\sqlsrv\Driver\Database\sqlsrv\Schema')
      ->setMethods(['getDefaultSchema', '__construct'])
      ->setMockClassName('MockSchema')
      ->setConstructorArgs([NULL])
      ->disableOriginalConstructor()
      ->getMock();
    $this->mockSchema->method('getDefaultSchema')->willReturn('dbo');
    if (!class_exists('Drupal\sqlsrv\Driver\Database\mock\Schema')) {
      class_alias('MockSchema', 'Drupal\sqlsrv\Driver\Database\mock\Schema');
    }

    $this->options['namespace'] = 'Drupal\sqlsrv\Driver\Database\mock';
    $this->options['prefix']['default'] = '';

    $this->mockPdo = $this->createMock('Drupal\Tests\Core\Database\Stub\StubPDO');
  }

  /**
   * Data provider for testEscapeTable.
   *
   * @return array
   *   An indexed array of where each value is an array of arguments to pass to
   *   testEscapeField. The first value is the expected value, and the second
   *   value is the value to test.
   */
  public function providerEscapeTables() {
    return [
      ['nocase', 'nocase'],
    ];
  }

  /**
   * Data provider for testEscapeField.
   *
   * @return array
   *   Array of arrays with the following elements:
   *   - Expected escaped string.
   *   - String to escape.
   */
  public function providerEscapeFields() {
    return [
      ['[timestamp]', 'timestamp'],
    ];
  }

  /**
   * Test that tables are properly escaped.
   *
   * @dataProvider providerEscapeTables
   */
  public function testEscapeTable($expected, $name) {
    $connection = new Connection($this->mockPdo, $this->options);

    $this->assertEquals($expected, $connection->escapeTable($name));
  }

  /**
   * Test that fields are properly escaped.
   *
   * @dataProvider providerEscapeFields
   */
  public function testEscapeField($expected, $name) {
    $connection = new Connection($this->mockPdo, $this->options);

    $this->assertEquals($expected, $connection->escapeField($name));
  }

  /**
   * Test that the connection returns the correct driver string.
   */
  public function testDriverString() {
    $connection = new Connection($this->mockPdo, $this->options);

    $this->assertEquals('sqlsrv', $connection->driver());
  }

  /**
   * Test that the connection returns the correct database type string.
   */
  public function testDatabaseType() {
    $connection = new Connection($this->mockPdo, $this->options);

    $this->assertEquals('sqlsrv', $connection->databaseType());
  }

}
