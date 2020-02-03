<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Update as QueryUpdate;

use Drupal\Driver\Database\sqlsrv\Utils as DatabaseUtils;

/**
 * Sqlsvr implementation of \Drupal\Core\Database\Query\Update.
 */
class Update extends QueryUpdate {

  /**
   * {@inheritdoc}
   */
  public function execute() {

    // Retrieve query options.
    $options = $this->queryOptions;

    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->queryColumnInformation($this->table);

    // Because we filter $fields the same way here and in __toString(), the
    // placeholders will all match up properly.
    $stmt = $this->connection->prepareQuery((string) $this);

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    foreach ($this->expressionFields as $field => $data) {
      if (!empty($data['arguments'])) {
        foreach ($data['arguments'] as $placeholder => $argument) {
          // We assume that an expression will never happen on a BLOB field,
          // which is a fairly safe assumption to make since in most cases
          // it would be an invalid query anyway.
          $stmt->bindParam($placeholder, $data['arguments'][$placeholder]);
        }
      }
      if ($data['expression'] instanceof SelectInterface) {
        $data['expression']->compile($this->connection, $this);
        $select_query_arguments = $data['expression']->arguments();
        foreach ($select_query_arguments as $placeholder => $argument) {
          $stmt->bindParam($placeholder, $select_query_arguments[$placeholder]);
        }
      }
      unset($fields[$field]);
    }

    // We use this array to store references to the blob handles.
    // This is necessary because the PDO will otherwise messes up with
    // references.
    $blobs = [];
    DatabaseUtils::BindValues($stmt, $fields, $blobs, ':db_update_placeholder_', $columnInformation);

    // Add conditions.
    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      $arguments = $this->condition->arguments();
      DatabaseUtils::BindArguments($stmt, $arguments);
    }

    $options = $this->queryOptions;
    $options['already_prepared'] = TRUE;
    $options['return'] = Database::RETURN_AFFECTED;

    return $this->connection->query($stmt, [], $options);
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    $update_fields = [];
    foreach ($this->expressionFields as $field => $data) {
      if ($data['expression'] instanceof SelectInterface) {
        // Compile and cast expression subquery to a string.
        $data['expression']->compile($this->connection, $this);
        $data['expression'] = ' (' . $data['expression'] . ')';
      }
      
      $update_fields[] = $this->connection->escapeField($field) . '=' . $data['expression'];
      unset($fields[$field]);
    }

    $max_placeholder = 0;
    foreach ($fields as $field => $value) {
      $update_fields[] = $this->connection->escapeField($field) . '=:db_update_placeholder_' . ($max_placeholder++);
    }

    $query = $prefix . 'UPDATE {' . $this->connection->escapeTable($this->table) . '} SET ' . implode(', ', $update_fields);

    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      // There is an implicit string cast on $this->condition.
      $query .= "\nWHERE " . $this->condition;
    }

    return $query;
  }

}
