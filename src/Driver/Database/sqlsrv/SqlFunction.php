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
     
     return $this->functionName . '(' . $arguments . ')';
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

  /**
   * CONCAT_WS.
   *
   * CONCAT_WS(sep, arg1, arg2, arg3, ...)
   * Becomes
   * STUFF(COALESCE(sep + arg1, '') + COALESCE(sep + arg2, '') + COALESCE(sep+arg3, '') + ..., 1, len(sep), '')
   */
  function concat_ws() {
    if (($pos1 = stripos($snippet, 'CONCAT_WS(')) !== FALSE) {
      // We assume the the separator does not contain any single-quotes
      // and none of the arguments contain commas.
      $pos2 = $this->findParenMatch($snippet, $pos1 + 9);
      $argument_list = substr($snippet, $pos1 + 10, $pos2 - 10 - $pos1);
      $arguments = explode(', ', $argument_list);
      $closing_quote_pos = stripos($argument_list, '\'', 1);
      $separator = substr($argument_list, 1, $closing_quote_pos - 1);
      $strings_list = substr($argument_list, $closing_quote_pos + 3);
      $arguments = explode(', ', $strings_list);
      $replace = "STUFF(";
      $coalesce = [];
      foreach ($arguments as $argument) {
        $coalesce[] = "COALESCE('{$separator}' + {$argument}, '')";
      }
      $coalesce_string = implode(' + ', $coalesce);
      $sep_len = strlen($separator);
      $replace = "STUFF({$coalesce_string}, 1, {$sep_len}, '')";
      $snippet = substr($snippet, 0, $pos1) . $replace . substr($snippet, $pos2 + 1);
      $operator = NULL;
    }
  }

}
