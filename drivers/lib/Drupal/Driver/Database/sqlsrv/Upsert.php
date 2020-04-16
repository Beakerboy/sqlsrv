<?php

namespace Drupal\Driver\Database\sqlsrv;

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
    if (!$this->preExecute()) {
      return NULL;
    }
    $insert_fields = array_merge($this->defaultFields, $this->insertFields);
    // Start transaction.
    $transaction = $this->connection->startTransaction();
    foreach ($this->insertValues as $insert_values) {
      $update_fields = array_combine($insert_fields, $insert_values);
      $condition = $update_fields[$this->key];
      unset($update_fields[$this->key]);

      $update = $this->connection->update($this->table, $this->queryOptions)
        ->fields($update_fields)
        ->condition($this->key, $condition);
      $number = $update->execute();

      if ($number === 0) {
        $insert = $this->connection->insert($this->table, $this->queryOptions)
          ->fields($insert_fields)
          ->values($insert_values)
          ->execute();
      }
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Do we to escape fields?
    $all_fields = array_merge($this->defaultFields, $this->insertFields);
    $placeholders = [];
    $row = [];
    $max_placeholder = -1;
    foreach ($this->insertValues as $insert_values) {
      foreach ($insert_values as $value) {
        $row[] = ':db_upsert_placeholder_' . ++$max_placeholder;
      }
      $placeholders[] = '(' . implode(', ', $row) . ')';
    }
    $placeholder_list = '(' . implode(', ', $placeholders) . ')';
    $insert_count = count($this->insertValues);
    $field_count = count($all_fields);

    $insert_fields = [];
    foreach ($all_fields as $field) {
      $insert_fields[] = 'src.' . $field;
    }
    $insert_list = '(' . implode(', ', $insert_fields) . ')';
    $field_list = '(' . implode(', ', $all_fields) . ')';
    $values_string = 'VALUES ' . $placeholder_list;
    $update_string = 'UPDATE SET ' . $update_fields;
    $insert_string = 'INSERT ' . $field_list . ' VALUES ' . $insert_list;
    $query = 'MERGE {' . $this->table . '} t USING(' . $values_string . ')';
    $query .= ' src ' . $field_list . ' ON t.key = src.key';
    $query .= ' WHEN MATCHED THEN ' . $update_string;
    $query .= ' WHEN NOT MATCHED THEN ' . $insert_string;

    return $query;
  }

}
