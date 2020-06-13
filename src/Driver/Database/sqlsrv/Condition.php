<?php

namespace Drupal\sqlsrv\Driver\Database\sqlsrv;

use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\Query\Condition as QueryCondition;
use Drupal\Core\Database\Query\PlaceholderInterface;

/**
 * @addtogroup database
 * @{
 */
class Condition extends QueryCondition {

  /**
   * {@inheritdoc}
   *
   * Overridden to replace REGEXP expressions.
   * Should this move to Condition::condition()?
   */
  public function compile(DatabaseConnection $connection, PlaceholderInterface $queryPlaceholder) {
    // Find any REGEXP conditions and turn them into function calls.
    foreach ($this->conditions as &$condition) {
      if (isset($condition['operator'])) {
        if ($condition['operator'] == 'REGEXP' || $condition['operator'] == 'NOT REGEXP') {
          $placeholder = ':db_condition_placeholder_' . $queryPlaceholder->nextPlaceholder();
          $field_fragment = $connection->escapeField($condition['field']);
          $comparison = $condition['operator'] == 'REGEXP' ? '1' : '0';
          $condition['field'] = "REGEXP({$placeholder}, {$field_fragment}) = {$comparison}";
          $condition['operator'] = NULL;
          $condition['value'] = [$placeholder => $condition['value']];
        }
        // Drupal expects all LIKE expressions to be escaped with a backslash.
        // Due to a PDO bug sqlsrv uses its default escaping behavior.
        // This can be removed if https://bugs.php.net/bug.php?id=79276 is
        // fixed.
        elseif ($condition['operator'] == 'LIKE' || $condition['operator'] == 'NOT LIKE') {
          $condition['value'] = strtr($condition['value'], [
            '[' => '[[]',
            '\%' => '[%]',
            '\_' => '[_]',
            '\\\\' => '\\',
          ]);
        }
      }
    }
    parent::compile($connection, $queryPlaceholder);
  }

  /**
   * {@inheritdoc}
   *
   * Overridden to replace REGEXP expressions.
   * Needs to be tested for complex nested expressions.
   */
  public function where($snippet, $args = []) {
    $operator = NULL;
    if (strpos($snippet, " NOT REGEXP ") !== FALSE) {
      $operator = ' NOT REGEXP ';
    }
    elseif (strpos($snippet, " REGEXP ") !== FALSE) {
      $operator = ' REGEXP ';
    }
    if ($operator !== NULL) {
      $fragments = explode($operator, $snippet);
      $field = $fragments[0];
      $value = $fragments[1];
      $comparison = $operator == ' REGEXP ' ? '1' : '0';

      $snippet = "REGEXP({$value}, {$field}) = {$comparison}";
      $operator = NULL;
    }
    $this->conditions[] = [
      'field' => $snippet,
      'value' => $args,
      'operator' => $operator,
    ];
    $this->changed = TRUE;
    return $this;
  }

}

/**
 * @} End of "addtogroup database".
 */
