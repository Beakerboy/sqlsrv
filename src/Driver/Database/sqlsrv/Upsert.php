<?php

namespace Drupal\sqlsrv\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Upsert as QueryUpsert;

/**
 * Implements Native Upsert queries for MSSQL.
 */
class Upsert extends QueryUpsert {

  const MAX_BATCH_SIZE = 200;

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (count($this->insertValues) === 1) {
      $insert_fields = array_merge($this->defaultFields, $this->insertFields);
      $update_fields = array_combine($insert_fields, array_shift($this->insertValues));
      $condition = $update_fields[$this->key];
      $merge = $this->connection->merge($this->table, $this->queryOptions)
        ->fields($update_fields)
        ->key($this->key, $condition);
      $merge->execute();
      return NULL;
    }
    if (!$this->preExecute()) {
      return NULL;
    }
    // Fetch the list of blobs and sequences used on that table.
    /** @var \Drupal\sqlsrv\Driver\Database\sqlsrv\Schema $schema */
    $schema = $this->connection->schema();
    $columnInformation = $schema->queryColumnInformation($this->table);
    $this->queryOptions['allow_delimiter_in_query'] = TRUE;
    $max_placeholder = -1;
    $values = [];
    foreach ($this->insertValues as $insert_values) {
      foreach ($insert_values as $value) {
        $values[':db_upsert_placeholder_' . ++$max_placeholder] = $value;
      }
    }
    $batch = array_splice($this->insertValues, 0, min(intdiv(2000, count($this->insertFields)), self::MAX_BATCH_SIZE));

    // If we are going to need more than one batch for this, start a
    // transaction.
    if (empty($this->queryOptions['sqlsrv_skip_transactions']) && !empty($this->insertValues)) {
      $transaction = $this->connection->startTransaction();
    }

    while (!empty($batch)) {
      // Give me a query with the amount of batch inserts.
      $query = $this->buildQuery(count($batch));

      // Prepare the query.
      /** @var \Drupal\Core\Database\Statement $stmt */
      $stmt = $this->connection->prepareQuery($query);

      // We use this array to store references to the blob handles.
      // This is necessary because the PDO will otherwise mess up with
      // references.
      $blobs = [];

      $max_placeholder = 0;
      foreach ($batch as $insert_index => $insert_values) {
        $values = array_combine($this->insertFields, $insert_values);
        Utils::bindValues($stmt, $values, $blobs, ':db_upsert_placeholder_', $columnInformation, $max_placeholder, $insert_index);
      }

      // Run the query.
      $this->connection->query($stmt, [], $this->queryOptions);

      // Fetch the next batch.
      $batch = array_splice($this->insertValues, 0, min(intdiv(2000, count($this->insertFields)), self::MAX_BATCH_SIZE));
    }
    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    return $this->buildQuery(count($this->insertValues));
  }

  /**
   * The aspect of the query depends on the batch size...
   *
   * @param int $batch_size
   *   The number of inserts to perform on a single statement.
   *
   * @throws \Exception
   *
   * @return string
   *   SQL Statement.
   */
  private function buildQuery($batch_size) {
    // Make sure we don't go crazy with this numbers.
    if ($batch_size > self::MAX_BATCH_SIZE) {
      throw new \Exception("MSSQL Native Batch Insert limited to 250.");
    }
    // Do we to escape fields?
    $key = $this->connection->escapeField($this->key);
    $all_fields = array_merge($this->defaultFields, $this->insertFields);

    $placeholders = [];
    $row = [];
    $max_placeholder = -1;
    $field_count = count($this->insertFields);
    for ($i = 0; $i < $batch_size; $i++) {
      for ($j = 0; $j < $field_count; $j++) {
        $row[] = ':db_upsert_placeholder_' . ++$max_placeholder;
      }
      $placeholders[] = '(' . implode(', ', $row) . ')';
      $row = [];
    }
    $placeholder_list = implode(', ', $placeholders);
    $insert_count = count($this->insertValues);
    $field_count = count($all_fields);

    $insert_fields = [];
    $update_fields = [];
    $all_fields_escaped = [];
    foreach ($all_fields as $field) {
      $field = $this->connection->escapeField($field);
      $all_fields_escaped[] = $field;
      $insert_fields[] = 'src.' . $field;
      $update_fields[] = $field . '=src.' . $field;
    }
    $insert_list = '(' . implode(', ', $insert_fields) . ')';
    $update_list = implode(', ', $update_fields);
    $field_list = '(' . implode(', ', $all_fields_escaped) . ')';
    $values_string = 'VALUES ' . $placeholder_list;
    $update_string = 'UPDATE SET ' . $update_list;
    $insert_string = 'INSERT ' . $field_list . ' VALUES ' . $insert_list;
    $query = 'MERGE {' . $this->table . '} AS tgt USING(' . $values_string . ')';
    $query .= ' AS src ' . $field_list . ' ON tgt.' . $key . '=src.' . $key;
    $query .= ' WHEN MATCHED THEN ' . $update_string;
    $query .= ' WHEN NOT MATCHED THEN ' . $insert_string . ';';

    return $query;
  }

}
