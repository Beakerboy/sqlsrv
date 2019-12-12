<?php

namespace Drupal\Tests\phpunit_example\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\Driver\Database\sqlsrv\Connection;

/**
 * @coversDefaultClass \Drupal\Driver\Database\sqlsrv\Connection
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
   * @var \PHPUnit\Framework\MockObject\MockObject|\Drupal\Driver\Database\sqlsrv\Schema
   */
  protected $mockSchema;

  /**
   * @var array database connection options
   *
   * The core test suite uses an empty array. This module requires at least a value in
   * $option['prefix']['default']
   */
  protected $options;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->mockSchema = $this->getMockBuilder('Drupal\Driver\Database\sqlsrv\Schema')
      ->setMethods(['getDefaultSchema', '__construct'])
      ->setClassName('MockSchema')
      ->setConstructorArgs([NULL])
      ->disableOriginalConstructor()
      ->getMock();
    $this->mockSchema->method('getDefaultSchema')->willReturn('dbo');
    class_alias('MockSchema', 'Drupal\Driver\Database\mock\Schema');
    // $class = get_class($this->mockSchema);
    // $namespace = substr($class, 0, strrpos($class, '\\'));
    $this->options['namespace'] = 'Drupal\Driver\Database\mock';
    echo "\nNamespace: '$namespace'\n";
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
      ['"camelCase"', 'camelCase'],
      ['"camelCase"', '"camelCase"'],
      ['"camelCase"', 'camel/Case'],
      // Sometimes, table names are following the pattern database.schema.table.
      ['"camelCase".nocase.nocase', 'camelCase.nocase.nocase'],
      ['nocase."camelCase".nocase', 'nocase.camelCase.nocase'],
      ['nocase.nocase."camelCase"', 'nocase.nocase.camelCase'],
      ['"camelCase"."camelCase"."camelCase"', 'camelCase.camelCase.camelCase'],
    ];
  }

  /**
   * Data provider for testEscapeAlias.
   *
   * @return array
   *   Array of arrays with the following elements:
   *   - Expected escaped string.
   *   - String to escape.
   */
  public function providerEscapeAlias() {
    return [
      ['nocase', 'nocase'],
      ['"camelCase"', '"camelCase"'],
      ['"camelCase"', 'camelCase'],
      ['"camelCase"', 'camel.Case'],
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
      ['title', 'title'],
      ['"isDefaultRevision"', 'isDefaultRevision'],
      ['"isDefaultRevision"', '"isDefaultRevision"'],
      ['entity_test."isDefaultRevision"', 'entity_test.isDefaultRevision'],
      ['entity_test."isDefaultRevision"', '"entity_test"."isDefaultRevision"'],
      ['"entityTest"."isDefaultRevision"', '"entityTest"."isDefaultRevision"'],
      ['"entityTest"."isDefaultRevision"', 'entityTest.isDefaultRevision'],
      ['entity_test."isDefaultRevision"', 'entity_test.is.Default.Revision'],
    ];
  }

  /**
   * @covers ::escapeTable
   * @dataProvider providerEscapeTables
   */
  public function testEscapeTable($expected, $name) {
    $pgsql_connection = new Connection($this->mockPdo, $this->options);

    $this->assertEquals($expected, $pgsql_connection->escapeTable($name));
  }

  /**
   * @covers ::escapeAlias
   * @dataProvider providerEscapeAlias
   */
  public function testEscapeAlias($expected, $name) {
    $sqlsvr_connection = new Connection($this->mockPdo, $this->options);

    $this->assertEquals($expected, $sqlsvr_connection->escapeAlias($name));
  }

  /**
   * @covers ::escapeField
   * @dataProvider providerEscapeFields
   */
  public function testEscapeField($expected, $name) {
    $sqlsvr_connection = new Connection($this->mockPdo, $this->options);

    $this->assertEquals($expected, $sqlsvr_connection->escapeField($name));
  }

}
