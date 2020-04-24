<?php

namespace Drupal\Driver\Database\sqlsrv;

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

          /** @var \Drupal\Driver\Database\sqlsrv\Schema $schema*/
          $schema = $connection->schema();
          $schema_name = $schema->getDefaultSchema();
          $placeholder = ':db_condition_placeholder_' . $queryPlaceholder->nextPlaceholder();
          $field_fragment = $connection->escapeField($condition['field']);
          $comparison = $condition['operator'] == 'REGEXP' ? '1' : '0';
          $condition['field'] = "{$schema_name}.REGEXP({$placeholder}, {$field_fragment}) = {$comparison}";
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
   * Need to add consideration for NOT REGEXP.
   */
  public function where($snippet, $args = []) {
    $operator = '';
    if (strpos($snippet, " NOT REGEXP ") !== FALSE) {
      $operator = ' NOT REGEXP ';
    }
    elseif (strpos($snippet, " REGEXP ") !== FALSE) {
      $operator = ' REGEXP ';
    }
    if ($operator !== '') {
      $fragments = explode($operator, $snippet);
      $field = $fragments[0];
      $value = $fragments[1];
      $comparison = $operator == ' REGEXP ' ? '1' : '0';
      /** @var \Drupal\Driver\Database\sqlsrv\Schema $schema*/
      $schema = $connection->schema();
      $schema_name = $schema->getDefaultSchema();
      $expression = "{$schema_name}.REGEXP({$value}, {$field}) = {$comparison}";
      $this->conditions[] = [
        'field' => $expression,
        'value' => $args,
        'operator' => NULL,
      ];
      $this->changed = TRUE;
    }
    return $this;
  }

}

/**
 * @} End of "addtogroup database".
 */
