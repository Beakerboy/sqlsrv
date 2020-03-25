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
    // Start transaction
    $transaction = $this->connection->startTransaction();
    foreach ($this->insertValues as $insert_values) {
      $update_fields = array_combine($insert_fields, $insert_values);
      $condition = $update_fields[$this->key];
      unset($update_fields[$this->key]);
      // UPDATE WHERE key=value
      $update = $this->connection->update($this->table, $this->queryOptions)
        ->fields($update_fields)
        ->condition($this->key, $condition);
      $number = $update->execute();
    
      if ($number === 0) {
       // INSERT table (fields) SELECT values WHERE ROWCOUNT=0
        $insert = $this->connection->insert($this->table, $this->queryOptions)
          ->fields($insert_fields)
          ->values($insert_values)
          ->execute();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Do nothing.
  }

}
