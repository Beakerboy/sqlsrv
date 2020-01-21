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
      if (isset($condition['operator'])) {
        if ($condition['operator'] == 'REGEXP' || $condition['operator'] == 'NOT REGEXP') {
          $schema_name = $connection->schema()->getDefaultSchema();
          $placeholder = ':db_condition_placeholder_' . $queryPlaceholder->nextPlaceholder();
          $field_fragment = $connection->escapeField($condition['field']);
          $comparison = $condition['operator'] == 'REGEXP' ? '1' : '0';
          $condition['field'] = "{$schema_name}.REGEXP({$placeholder}, {$field_fragment}) = {$comparison}";
          $condition['operator'] = NULL;
          $condition['value'] = [$placeholder => $condition['value']];
        }
      }
    }
    parent::compile($connection, $queryPlaceholder);
  }
}

/**
 * @} End of "addtogroup database".
 */
