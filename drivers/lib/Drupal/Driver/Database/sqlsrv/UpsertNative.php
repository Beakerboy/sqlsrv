<?php

/**
 * @file
 * Contains \Drupal\Core\Database\Driver\sqlsrv\UpsertNative.
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Upsert as QueryUpsert;

use Drupal\Driver\Database\sqlsrv\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use Drupal\Driver\Database\sqlsrv\TransactionScopeOption as DatabaseTransactionScopeOption;
use Drupal\Driver\Database\sqlsrv\TransactionSettings as DatabaseTransactionSettings;

use Drupal\Driver\Database\sqlsrv\Utils as DatabaseUtils;

/**
 * Implements Native Upsert queries for MSSQL.
 */
class UpsertNative extends QueryUpsert {

  /**
   * Result summary of INSERTS/UPDATES after execution.
   * @var string[]
   */
  public $result = NULL;

  /**
   * {@inheritdoc}
   */
  public function execute() {

    // Retrieve query options.
    $options = $this->queryOptions;

    // Initialize result array.
    $this->result = array();

    // Keep a reference to the blobs.
    $blobs = array();

    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->queryColumnInformation($this->table);

    // If the table does not exist, trigger an exception.
    if (empty($columnInformation)) {
      throw new \Drupal\Core\Database\SchemaObjectDoesNotExistException();
    }

    // Find out if there is an identity field set in this insert.
    $this->setIdentity = !empty($columnInformation['identity']) && in_array($columnInformation['identity'], array_keys($this->insertFields));

    // Initialize placeholder count.
    $max_placeholder = 0;

    // Build the query.
    $stmt = $this->connection->prepareQuery((string) $this);

    // 3. Bind the dataset.
    foreach ($this->insertValues as $insert_values) {
      $fields = array_combine($this->insertFields, $insert_values);
      DatabaseUtils::BindValues($stmt, $fields, $blobs, ':db_insert_placeholder_', $columnInformation, $max_placeholder);
    }

    // 4. Run the query, this will return UPDATE or INSERT
    $this->connection->query($stmt, array(), $options);

    foreach ($stmt as $value) {
      $this->result[] = $value->{'$action'};
    }

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return TRUE;

  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {

    $query = array();

    // Enable direct insertion to identity columns if necessary.
    if (!empty($this->setIdentity)) {
      $query[] = "SET IDENTITY_INSERT {{$this->table}} ON;";
    }

    $query[] = "MERGE INTO {{$this->table}} _target";

    // 1. Implicit dataset
    // select t.*  from (values(1,2,3), (2,3,4)) as t(col1,col2,col3)
    $values = $this->getInsertPlaceholderFragment($this->insertValues, $this->defaultFields);
    $columns = implode(', ', $this->connection->quoteIdentifiers($this->insertFields));

    $dataset = "SELECT T.* FROM (values" . implode(',', $values) .") as T({$columns})";

    // Build primery key conditions
    $key_conditions = array();

    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->queryColumnInformation($this->table);
    $primary_key_cols = array_column($columnInformation['indexes'][$columnInformation['primary_key_index']]['columns'], 'name');
    foreach ($primary_key_cols as $key) {
      $key_conditions[] = "_target.[$key] = _source.[$key]";
    }

    $query[] = "USING ({$dataset}) _source" . PHP_EOL . 'ON ' . implode(' AND ', $key_conditions);

    // Mappings.
    $insert_mappings = array();
    $update_mappings = array();
    foreach ($this->insertFields as $field) {
      $insert_mappings[] = "_source.[{$field}]";
      // Updating the unique / primary key is not necessary.
      if (!in_array($field, $primary_key_cols)) {
        $update_mappings[] = "_target.[{$field}] = _source.[{$field}]";
      }
    }

    // "When matched" part
    $query[] = 'WHEN MATCHED THEN UPDATE SET ' . implode(', ', $update_mappings);

    // "When not matched" part.
    $query[] = "WHEN NOT MATCHED THEN INSERT ({$columns}) VALUES (".  implode(', ', $insert_mappings) .")";

    // Return information about the query.
    $query[] = 'OUTPUT $action;';

    return implode(PHP_EOL, $query);

  }

}