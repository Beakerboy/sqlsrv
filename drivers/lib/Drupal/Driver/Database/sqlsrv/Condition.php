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
   */
  public function compile(DatabaseConnection $connection, PlaceholderInterface $queryPlaceholder) {
    // Find any REGEXP conditions and turn them into function calls
    foreach ($this->conditions as &$condition) {
      if(isset($condition['operator'])) {
        $schema_name = $connection->schema->getDefaultSchema();
        if ($condition['operator'] == 'REGEXP') {
          $condition['field'] = '{$schema_name}.REGEXP(:db_regexp, ' . $connection->escapeField($condition['field']) . ') = 1';
          $condition['operator'] = NULL;
          $condition['value'] = [':db_regexp' => $condition['value']];
        } else if ($condition['operator'] == 'NOT REGEXP') {
          $condition['field'] = '{$schema_name}.REGEXP(:db_regexp, ' . $connection->escapeField($condition['field']) . ') = 0';
          $condition['operator'] = NULL;
          $condition['value'] = [':db_regexp' => $condition['value']];
        } 
      }
    }
    parent::compile($connection, $queryPlaceholder);
  }
}

/**
 * @} End of "addtogroup database".
 */
