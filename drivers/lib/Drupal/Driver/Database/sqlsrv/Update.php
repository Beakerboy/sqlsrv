<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Update
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Query\Update as QueryUpdate;
use Drupal\Core\Database\Query\Condition;

use Drupal\Driver\Database\sqlsrv\Utils as DatabaseUtils;
use Drupal\Driver\Database\sqlsrv\TransactionSettings as DatabaseTransactionSettings;

use mssql\Settings\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use mssql\Settings\TransactionScopeOption as DatabaseTransactionScopeOption;

use PDO as PDO;
use Exception as Exception;
use PDOStatement as PDOStatement;

class Update extends QueryUpdate {

  /**
   * @var Connection
   */
  protected $connection;

  /**
   * {@inheritdoc}
   */
  public function execute() {

    // Retrieve query options.
    $options = $this->queryOptions;

    // Check that the table does exist.
    if (!$this->connection->schema()->tableExists($this->table)) {
      throw new \Drupal\Core\Database\SchemaObjectDoesNotExistException("Table $this->table does not exist.");
    }

    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->getTableIntrospection($this->table);

    // MySQL is a pretty slut that swallows everything thrown at it,
    // like trying to update an identity field...
    if (isset($columnInformation['identity']) && isset($this->fields[$columnInformation['identity']])) {
      unset($this->fields[$columnInformation['identity']]);
    }

    // Because we filter $fields the same way here and in __toString(), the
    // placeholders will all match up properly.
    $stmt = $this->connection->prepareQuery((string)$this);

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    $stmt->BindExpressions($this->expressionFields, $fields);

    // We use this array to store references to the blob handles.
    // This is necessary because the PDO will otherwise messes up with references.
    $blobs = array();
    $stmt->BindValues($fields, $blobs, ':db_update_placeholder_', $columnInformation);

    // Add conditions.
    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      $arguments = $this->condition->arguments();
      $stmt->BindArguments($arguments);
    }

    $options = $this->queryOptions;
    $options['already_prepared'] = TRUE;

    $this->connection->query($stmt, array(), $options);

    return $stmt->rowCount();
  }

  public function __toString() {

    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    $update_fields = array();
    foreach ($this->expressionFields as $field => $data) {
      $update_fields[] = $this->connection->quoteIdentifier($field) . '=' . $data['expression'];
      unset($fields[$field]);
    }

    $max_placeholder = 0;
    foreach ($fields as $field => $value) {
      $update_fields[] = $this->connection->quoteIdentifier($field) . '=:db_update_placeholder_' . ($max_placeholder++);
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