<?php

namespace Drupal\Tests\sqlsrv\Unit

/**
 * Test the behavior of the custom Condition class
 */
class ConditionTest {

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
   
    $condition = [
      'field' => 'name',
      'value' = $given,
      'operation' => 'LIKE',
    ];

    $this->assertEqual($reescaped, $expected, "Test that the driver escapes LIKE parameters correctly");
  }

  public function dataProviderForTestLike() {
    return [
      [
      ],
      [
      ],
    ];
  }
    
  /**
   * Test the REGEXP operator string replacement
   */
  public function testRegexp() {
  
  }

}
