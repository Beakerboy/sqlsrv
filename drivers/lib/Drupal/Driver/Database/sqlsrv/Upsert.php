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
    $max_placeholder = -1;
    $values = [];
    foreach ($this->insertValues as $insert_values) {
      foreach ($insert_values as $value) {
        $values[':db_upsert_placeholder_' . ++$max_placeholder] = $value;
      }
    }
    $this->connection->query((string) $this, $values, $this->queryOptions);

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Do we to escape fields?
    $key = $this->connection->escapeField($this->key);
    $all_fields = array_merge($this->defaultFields, $this->insertFields);

    $placeholders = [];
    $row = [];
    $max_placeholder = -1;
    foreach ($this->insertValues as $insert_values) {
      foreach ($insert_values as $value) {
        $row[] = ':db_upsert_placeholder_' . ++$max_placeholder;
      }
      $placeholders[] = '(' . implode(', ', $row) . ')';
      $row = [];
    }
    $placeholder_list = '(' . implode(', ', $placeholders) . ')';
    $insert_count = count($this->insertValues);
    $field_count = count($all_fields);

    $insert_fields = [];
    $update_fields = [];
    $all_fields_escaped = [];
    foreach ($all_fields as $field) {
      $field = $this->connection->escapeField($field);
      $all_fields_escaped[] = $field;
      $insert_fields[] = 'src.' . $field;
      $update_fields[] = $field . '=' . 'src.' . $field;
    }
    $insert_list = '(' . implode(', ', $insert_fields) . ')';
    $update_list = implode(', ', $update_fields);
    $field_list = '(' . implode(', ', $all_fields_escaped) . ')';
    $values_string = 'VALUES ' . $placeholder_list;
    $update_string = 'UPDATE SET ' . $update_list;
    $insert_string = 'INSERT ' . $field_list . ' VALUES ' . $insert_list;
    $query = 'MERGE {' . $this->table . '} AS tgt USING(' . $values_string . ')';
    $query .= ' AS src ' . $field_list . ' ON tgt.' . $key . '=src.'. $key;
    $query .= ' WHEN MATCHED THEN ' . $update_string;
    $query .= ' WHEN NOT MATCHED THEN ' . $insert_string;

    return $query;
  }

}
