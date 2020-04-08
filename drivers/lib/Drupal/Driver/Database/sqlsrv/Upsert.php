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
    return "";
  }

}
