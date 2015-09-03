<?php

/**
 * @file
 * Contains \Drupal\Core\Database\Driver\sqlsrv\Upsert.
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Upsert as QueryUpsert;

use Drupal\Driver\Database\sqlsrv\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use Drupal\Driver\Database\sqlsrv\TransactionScopeOption as DatabaseTransactionScopeOption;
use Drupal\Driver\Database\sqlsrv\TransactionSettings as DatabaseTransactionSettings;

use Drupal\Driver\Database\sqlsrv\Utils as DatabaseUtils;

/**
 * Implements the Upsert query for the MSSQL database driver.
 *
 * TODO: This class has been replaced by UpsertNative. Keeping this here for a while though..
 */
class Upsert extends QueryUpsert {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (!$this->preExecute()) {
      return NULL;
    }

    // Default options for upsert queries.
    $this->queryOptions += array(
      'throw_exception' => TRUE,
    );

    // Default fields are always placed first for consistency.
    $insert_fields = array_merge($this->defaultFields, $this->insertFields);
    $insert_fields_escaped = array_map(function($f) { return $this->connection->escapeField($f); }, $insert_fields);

    $table = $this->connection->escapeTable($this->table);
    $unique_key = $this->connection->escapeField($this->key);

    // We have to execute multiple queries, therefore we wrap everything in a
    // transaction so that it is atomic where possible.
    $transaction = $this->connection->startTransaction(NULL, DatabaseTransactionSettings::GetDDLCompatibleDefaults());

    // First, create a temporary table with the same schema as the table we
    // are trying to upsert in.
    $query = 'SELECT TOP(0) * FROM {' . $table . '}';
    $temp_table = $this->connection->queryTemporary($query, [], array_merge($this->queryOptions, array('real_table' => TRUE)));

    // Second, insert the data in the temporary table.
    $insert = $this->connection->insert($temp_table, $this->queryOptions)
      ->fields($insert_fields);
    foreach ($this->insertValues as $insert_values) {
      $insert->values($insert_values);
    }
    $insert->execute();

    // Third, lock the table we're upserting into.
    $this->connection->query("SELECT 1 FROM {{$table}} WITH (HOLDLOCK)", [], $this->queryOptions);

    // Fourth, update any rows that can be updated. This results in the
    // following query:
    //
    // UPDATE table_name
    // SET column1 = temp_table.column1 [, column2 = temp_table.column2, ...]
    // FROM temp_table
    // WHERE table_name.id = temp_table.id;
    $update = [];
    foreach ($insert_fields_escaped as $field) {
      if ($field !== $unique_key) {
        $update[] = "$field = {" . $temp_table . "}.$field";
      }
    }

    $update_query = 'UPDATE {' . $table . '} SET ' . implode(', ', $update);
    $update_query .= ' FROM {' . $temp_table . '}';
    $update_query .= ' WHERE {' . $temp_table . '}.' . $unique_key . ' = {' . $table . '}.' . $unique_key;
    $this->connection->query($update_query, [], $this->queryOptions);

    // Fifth, insert the remaining rows. This results in the following query:
    //
    // INSERT INTO table_name
    // SELECT temp_table.primary_key, temp_table.column1 [, temp_table.column2 ...]
    // FROM temp_table
    // LEFT OUTER JOIN table_name ON (table_name.id = temp_table.id)
    // WHERE table_name.id IS NULL;
    $select = $this->connection->select($temp_table, 'temp_table', $this->queryOptions)
      ->fields('temp_table', $insert_fields);
    $select->leftJoin($this->table, 'actual_table', 'actual_table.' . $this->key . ' = temp_table.' . $this->key);
    $select->isNull('actual_table.' . $this->key);

    $this->connection->insert($this->table, $this->queryOptions)
      ->from($select)
      ->execute();

    // Drop the "temporary" table.
    $this->connection->query_direct("DROP TABLE {$temp_table}");

    $transaction->commit();

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = array();

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Nothing to do.
  }

}