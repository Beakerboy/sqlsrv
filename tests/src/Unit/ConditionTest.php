<?php

namespace Drupal\Tests\sqlsrv\Unit;

use Drupal\Driver\Database\sqlsrv\Select;
use Drupal\Driver\Database\sqlsrv\Condition;
use Drupal\Tests\Core\Database\Stub\StubConnection;

/**
 * Test the behavior of the custom Condition class
 */
class ConditionTest {
  public function setUp() {
    $mock_pdo = $this->createMock('Drupal\Tests\Core\Database\Stub\StubPDO');
    $this->connection = new StubConnection($mock_pdo, []);
    $this->select = new Select('table', 't', $this->connection);
  }

  /**
   * Test the escaping strategy of the LIKE operator.
   *
   * Mysql, Postgres, and sqlite all use '\' to escape '%' and '_'
   * in the LIKE statement. SQL Server can also use a backslash with the syntax.
   * field LIKE :text ESCAPE '\'.
   * However, due to a bug in PDO (https://bugs.php.net/bug.php?id=79276), if a SQL
   * statement has multiple LIKE statements, parameters are not correctly replaced
   * if they are located between a pair of backslashes:
   * "field1 LIKE :text1 ESCAPE '\' AND field2 LIKE :text2 ESCAPE '\'"
   * :text2 will not be replaced.
   *
   * If the PDO bug is fixed, this test and the LIKE customization within the
   * Condition class can be removed
   */
  public function testLike($given, $reescaped) {
   
    $condition = new Condition('AND');
    $condition->condition('name', $given, 'LIKE');
    $condition->compile($this->connection, $select);
    $conditions = $condition->conditions();
    $reescaped = $conditions[0]['value'];
    $this->assertEqual($reescaped, $expected, "Test that the driver escapes LIKE parameters correctly");
  }

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
    ];
  }
    
  /**
   * Test the REGEXP operator string replacement
   */
  //public function testRegexp() {
  
  //}

}
