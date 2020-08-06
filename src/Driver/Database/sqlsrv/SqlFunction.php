<?php

namespace Drupal\sqlsrv\Driver\Database\sqlsrv;
.........1.........2.........3.........4.........5.........6.........7.........8
/**
 * SqlFunction Class.
 *
 * This class manages translating SQL Functions from Drupal syntax into SQL
 * Server syntax.
 *
 * Because function translation may need to take place in SELECT fields, WHERE
 * expressions, or JOIN clauses, we need to collect the code in one place.
 *
 * @addtogroup database
 * @{
 */
class SqlFunction {

  /**
   * Constructor.
   */
   public function __construct(string $expression) {
   }
 
   public static createFromString(string $expression) {
   
   }
 
   public static createFromArray(string $function, array $arguments) {
   }
 
   /**
    * Compile.
    *
    * Return the expression as a string in SQL Server syntax.
    */
   public compile() {
   }
 
   /**
   * Given a string find the matching parenthesis after the given point.
   *
   * @param string $string
   *   The input string.
   * @param int $start_paren
   *   The 0 indexed position of the open-paren, for which we would like
   *   to find the matching closing-paren.
   *
   * @return int|false
   *   The 0 indexed position of the close paren.
   */
  public function findParenMatch($string, $start_paren) {
    if ($string[$start_paren] !== '(') {
      return FALSE;
    }
    $str_array = str_split(substr($string, $start_paren + 1));
    $paren_num = 1;
    foreach ($str_array as $i => $char) {
      if ($char == '(') {
        $paren_num++;
      }
      elseif ($char == ')') {
        $paren_num--;
      }
      if ($paren_num == 0) {
        return $i + $start_paren + 1;
      }
    }
    return FALSE;
  }
  
}
