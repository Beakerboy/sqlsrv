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
  public function compile(Connection $connection, PlaceholderInterface $queryPlaceholder) {
    // Find any REGEXP conditions and turn them into function calls
    $conditions = &$this->conditions;

    foreach ($conditions as &$condition) {
      if ($condition['operator'] == 'REGEXP') {
        //$condition['field'] = 'RegExCompiledMatch(' . $field;
        //$condition['operator'] = ',';
        //$condition['value'] = $condition['value'] . ') = 1';
      } else if ($condition['operator'] == 'NOT REGEXP') {
        //$condition['field'] = 'RegExCompiledMatch(' . $field;
        //$condition['operator'] = ',';
        //$condition['value'] = $condition['value'] . ') = 0';
      } 
    }
    parent::compile($connection, $queryPlaceholder);
  }
}

/**
 * @} End of "addtogroup database".
 */
