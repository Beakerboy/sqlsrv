<?php

namespace Drupal\Tests\sqlsrv\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PlaceholderInterface;
use Drupal\Driver\Database\sqlsrv\Condition;
use Drupal\Tests\UnitTestCase;
use Prophecy\Argument;

/**
 * Test the behavior of the custom Condition class.
 *
 * These tests are not expected to pass on other database drivers.
 *
 * @group Database
 */
class SqlsrvConditionTest extends UnitTestCase {

  /**
   * Test the escaping strategy of the LIKE operator.
   *
   * Mysql, Postgres, and sqlite all use '\' to escape '%' and '_'
   * in the LIKE statement. SQL Server can also use a backslash with the syntax.
   * field LIKE :text ESCAPE '\'.
   * However, due to a bug in PDO (https://bugs.php.net/bug.php?id=79276), if a
   * SQL statement has multiple LIKE statements, parameters are not correctly
   * replaced if they are located between a pair of backslashes:
   *
   * "field1 LIKE :text1 ESCAPE '\' AND field2 LIKE :text2 ESCAPE '\'"
   * :text2 will not be replaced.
   *
   * If the PDO bug is fixed, this test and the LIKE customization within the
   * Condition class can be removed
   *
   * @dataProvider dataProviderForTestLike
   */
  public function testLike($given, $expected) {
    $connection = $this->prophesize(Connection::class);
    $connection->escapeField(Argument::any())->will(function ($args) {
      return preg_replace('/[^A-Za-z0-9_.]+/', '', $args[0]);
    });
    $connection->mapConditionOperator(Argument::any())->willReturn([]);
    $connection = $connection->reveal();
    $query_placeholder = $this->prophesize(PlaceholderInterface::class);

    $counter = 0;
    $query_placeholder->nextPlaceholder()->will(function () use (&$counter) {
      return $counter++;
    });

    $query_placeholder->uniqueIdentifier()->willReturn(4);
    $query_placeholder = $query_placeholder->reveal();

    $condition = new Condition('AND');
    $condition->condition('name', $given, 'LIKE');
    $condition->compile($connection, $query_placeholder);
    $this->assertEquals('name LIKE :db_condition_placeholder_0', $condition->__toString());
    $this->assertEquals([':db_condition_placeholder_0' => $expected], $condition->arguments());
  }

  /**
   * Data Provider.
   */
  public function dataProviderForTestLike() {
    return [
      [
        '%',
        '%',
      ],
      [
        '\%',
        '[%]',
      ],
      [
        '\_',
        '[_]',
      ],
      [
        '\\\\',
        '\\',
      ],
      [
        '[\%]',
        '[[][%]]',
      ],
    ];
  }

  /**
   * Test the REGEXP operator string replacement.
   *
   * @dataProvider dataProviderForTestRegexp
   */
  public function testRegexp($expected, $field_name, $operator, $pattern) {
    $connection = $this->prophesize(Connection::class);
    $connection->escapeField($field_name)->will(function ($args) {
      return preg_replace('/[^A-Za-z0-9_.]+/', '', $args[0]);
    });
    $connection->mapConditionOperator($operator)->willReturn(['operator' => $operator]);
    $connection = $connection->reveal();

    $query_placeholder = $this->prophesize(PlaceholderInterface::class);

    $counter = 0;
    $query_placeholder->nextPlaceholder()->will(function () use (&$counter) {
      return $counter++;
    });
    $query_placeholder->uniqueIdentifier()->willReturn(4);
    $query_placeholder = $query_placeholder->reveal();

    $condition = new Condition('AND');
    $condition->condition($field_name, $pattern, $operator);
    $condition->compile($connection, $query_placeholder);

    $this->assertEquals($expected, $condition->__toString());
    $this->assertEquals([':db_condition_placeholder_0' => $pattern], $condition->arguments());
  }

  /**
   * Provides a list of known operations and the expected output.
   */
  public function dataProviderForTestRegexp() {
    return [
      [
        '(REGEXP(:db_condition_placeholder_0, name) = 1)',
        'name',
        'REGEXP',
        '^P',
      ],
      [
        '(REGEXP(:db_condition_placeholder_0, name123) = 1)',
        'name-123',
        'REGEXP',
        's$',
      ],
      [
        '(REGEXP(:db_condition_placeholder_0, name) = 0)',
        'name',
        'NOT REGEXP',
        '^\$[a-z][a-zA-Z_]$',
      ],
      [
        '(REGEXP(:db_condition_placeholder_0, name123) = 0)',
        'name-123',
        'NOT REGEXP',
        '^[a-z].*$',
      ],
    ];
  }

}
