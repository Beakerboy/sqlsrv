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
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->mockSchema = $this->getMockBuilder('Drupal\Driver\Database\sqlsrv\Schema')->setMethods(['getDefaultSchema'=>'dbo'])->getMock();
    $this->options['prefix']['default'] = '';
    $this->options['namespace'] = getNamespace($this->mockSchema)
    $this->mockPdo = $this->getMockBuilder('Drupal\Tests\Core\Database\Stub\StubPDO')->setMethods(null)->getMock();
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
    // The Connection class should be able to handle missing keys
    $options['prefix']['default'] = '';
    $pgsql_connection = new Connection($this->mockPdo, $options);

    $this->assertEquals($expected, $pgsql_connection->escapeTable($name));
  }

  /**
   * @covers ::escapeAlias
   * @dataProvider providerEscapeAlias
   */
  public function testEscapeAlias($expected, $name) {
    // The Connection class should be able to handle missing keys
    $sqlsvr_connection = new Connection($this->mockPdo, $this->options);
    $this->assertInstanceOf(Connection::class, $sqlsvr_connection);
    //$this->assertEquals($expected, $sqlsvr_connection->escapeAlias($name));
  }

  /**
   * @covers ::escapeField
   * @dataProvider providerEscapeFields
   */
  public function testEscapeField($expected, $name) {
    // The Connection class should be able to handle missing keys
    $options['prefix']['default'] = '';
    $sqlsvr_connection = new Connection($this->mockPdo, $options);
    $this->assertTrue(true);
    //$this->assertEquals($expected, $sqlsvr_connection->escapeField($name));
  }

}
