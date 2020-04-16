<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Upsert as QueryUpsert;

/**
 * Implements Native Upsert queries for MSSQL.
 */
class Upsert extends QueryUpsert {

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
    $query = 'MERGE {' . $this->table . '} t USING(VALUES ';
    $query .= $values . ') src ' . $field_list;
    $query .= ' ON t.key = src.key';
    $query .= ' UPDATE SET ';
    $query .= ' WHEN NOT MATCHED THEN';
    $query .= ' INSERT ' . $field_list;
    MERGE tablename trg
      USING (VALUES ('A','B','C'),
              ('C','D','E'),
              ('F','G','H'),
              ('I','J','K')) src(keycol, col1, col2)
  ON trg.keycol = src.keycol
WHEN MATCHED THEN
   UPDATE SET col1 = src.col1, col2 = src.col2
WHEN NOT MATCHED THEN
   INSERT(keycol, col1, col2)
   VALUES(src.keycol, src.col1, src.col2);
    return "";
  }

}
