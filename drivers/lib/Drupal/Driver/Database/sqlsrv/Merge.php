<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Merge as QueryMerge;
use Drupal\Core\Database\Query\InvalidMergeQueryException;

class Merge extends QueryMerge {

  /**
   * Returned by execute() no
   * records have been affected.
   */
  const STATUS_NONE = -1;

  /**
   * The database connection
   *
   * @var Connection
   */
  protected $connection;

  /**
   * Keep track of the number of
   * placeholders in the compiled query
   *
   * @var int
   */
  protected $totalPlaceholders;

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (!count($this->condition)) {
      throw new InvalidMergeQueryException(t('Invalid merge query: no conditions'));
    }
    // Check that the table does exist.
    if (!$this->connection->schema()->tableExists($this->table)) {
      throw new \Drupal\Core\Database\SchemaObjectDoesNotExistException("Table $this->table does not exist.");
    }
    // Retrieve query options.
    $options = $this->queryOptions;
    // Keep a reference to the blobs.
    $blobs = [];
    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->getTableIntrospection($this->table);
    // Find out if there is an identity field set in this insert.
    $this->setIdentity = !empty($columnInformation['identity']) && in_array($columnInformation['identity'], array_keys($this->insertFields));
    // Initialize placeholder count.
    $max_placeholder = 0;
    // Stringify the query
    $query = $this->__toString();
    // Build the query, ensure that we have retries for concurrency control
    $options['integrityretry'] = TRUE;
    if ($this->totalPlaceholders >= 2100) {
      $options['insecure'] = TRUE;
    }
    $stmt = $this->connection->prepareQuery($query, $options);
    // Build the arguments: 1. condition.
    $arguments = $this->condition->arguments();
    $stmt->BindArguments($arguments);
    // 2. When matched part.
    $fields = $this->updateFields;
    $stmt->BindExpressions($this->expressionFields, $fields);
    $stmt->BindValues($fields, $blobs, ':db_merge_placeholder_', $columnInformation, $max_placeholder);
    // 3. When not matched part.
    $stmt->BindValues($this->insertFields, $blobs, ':db_merge_placeholder_', $columnInformation, $max_placeholder);
    // 4. Run the query, this will return UPDATE or INSERT
    $this->connection->query($stmt, []);
    $result = NULL;
    foreach ($stmt as $value) {
      $result = $value->{'$action'};
    }
    switch($result) {
      case 'UPDATE':
        return static::STATUS_UPDATE;
      case 'INSERT':
        return static::STATUS_INSERT;
      default:
        if (!empty($this->expressionFields)) {
          throw new InvalidMergeQueryException(t('Invalid merge query: no results.'));
        }
        else {
          return static::STATUS_NONE;
        }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Initialize placeholder count.
    $max_placeholder = 0;
    $max_placeholder_conditions =  0;
    $query = [];
    // Enable direct insertion to identity columns if necessary.
    if (!empty($this->setIdentity)) {
      $query[] = 'SET IDENTITY_INSERT {' . $this->table . '} ON;';
    }
    $query[] = 'MERGE INTO {' . $this->table . '} _target';
    // 1. Condition part.
    $this->condition->compile($this->connection, $this);
    $key_conditions = [];
    $template_item = [];
    $conditions = $this->conditions();
    unset($conditions['#conjunction']);
    foreach ($conditions as $condition) {
      $key_conditions[] = '_target.' . $this->connection->escapeField($condition['field']) . ' = ' . '_source.' . $this->connection->escapeField($condition['field']);
      $template_item[] = ':db_condition_placeholder_' . $max_placeholder_conditions++ . ' AS ' . $this->connection->escapeField($condition['field']);
    }
    $query[] = 'USING (SELECT ' . implode(', ', $template_item) . ') _source ' . PHP_EOL . 'ON ' . implode(' AND ', $key_conditions);
    // 2. "When matched" part.
    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->updateFields;
    $update_fields = [];
    foreach ($this->expressionFields as $field => $data) {
      $update_fields[] = $field . '=' . $data['expression'];
      unset($fields[$field]);
    }
    foreach ($fields as $field => $value) {
      $update_fields[] = $this->connection->quoteIdentifier($field) . '=:db_merge_placeholder_' . ($max_placeholder++);
    }
    if (!empty($update_fields)) {
      $query[] = 'WHEN MATCHED THEN UPDATE SET ' . implode(', ', $update_fields);
    }
    // 3. "When not matched" part.
    if ($this->insertFields) {
      // Build the list of placeholders.
      $placeholders = [];
      $insertFieldsCount = count($this->insertFields);
      for ($i = 0; $i < $insertFieldsCount; ++$i) {
        $placeholders[] = ':db_merge_placeholder_' . ($max_placeholder++);
      }
      $query[] = 'WHEN NOT MATCHED THEN INSERT (' . implode(', ', $this->connection->quoteIdentifiers(array_keys($this->insertFields))) . ') VALUES (' . implode(', ', $placeholders) . ')';
    }
    else {
      $query[] = 'WHEN NOT MATCHED THEN INSERT DEFAULT VALUES';
    }
    // Return information about the query.
    $query[] = 'OUTPUT $action;';
    $this->totalPlaceholders = $max_placeholder + $max_placeholder_conditions;
    return implode(PHP_EOL, $query);
  }
}