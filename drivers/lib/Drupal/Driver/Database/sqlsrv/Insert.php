<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Insert
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Query\Insert as QueryInsert;

use PDO as PDO;

/**
 * @ingroup database
 * @{
 */

class Insert extends QueryInsert {

  public function execute() {
    if (!$this->preExecute()) {
      return NULL;
    }

    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->queryColumnInformation($this->table);
    
    // Find out if there is an identity field set in this insert.
    $this->setIdentity = !empty($columnInformation['identity']) && in_array($columnInformation['identity'], $this->insertFields);

    #region Select Based Insert
    
    if (!empty($this->fromQuery)) {
      // Re-initialize the values array so that we can re-use this query.
      $this->insertValues = array();

      $stmt = $this->connection->PDOPrepare($this->connection->prefixTables((string) $this));
      // Handle the case of SELECT-based INSERT queries first.
      $values = $this->fromQuery->getArguments();
      foreach ($values as $key => $value) {
        $stmt->bindParam($key, $values[$key]);
      }
      $stmt->execute();

      // We can only have 1 identity column per table (or none, where fetchColumn will fail)
      try {
        return $stmt->fetchColumn(0);
      }
      catch(\PDOException $e) {
        return NULL;
      }
    }
    
    #endregion

    #region Inserts with no values (full defaults)
    
    // Handle the case of full-default queries.
    if (empty($this->fromQuery) && (empty($this->insertFields) || empty($this->insertValues))) {
      // Re-initialize the values array so that we can re-use this query.
      $this->insertValues = array();
      $query = (string) $this;
      $stmt = $this->connection->PDOPrepare($this->connection->prefixTables($query));
      $stmt->execute();
      
      // We can only have 1 identity column per table (or none, where fetchColumn will fail)
      try {
        return $stmt->fetchColumn(0);
      }
      catch(\PDOException $e) {
        return NULL;
      }
    }
    
    #endregion

    #region Regular Inserts
    
    $last_insert_id = NULL;
    $query = (string) $this;
    $stmt = $this->connection->PDOPrepare($this->connection->prefixTables($query));

    // We use this array to store references to the blob handles.
    // This is necessary because the PDO will otherwise messes up with references.
    $data_values = array();
    
    // Each insert happens in its own query. However, we wrap it in a transaction
    // so that it is atomic where possible.
    if (empty($this->queryOptions['sqlsrv_skip_transactions']) && count($this->insertValues) > 1) {
      $transaction = $this->connection->startTransaction();
    }

    foreach ($this->insertValues as $insert_index => $insert_values) {
      $max_placeholder = 0;
      foreach ($this->insertFields as $field_index => $field) {
        $placeholder = ':db_insert' . $max_placeholder++;
        if (isset($columnInformation['blobs'][$field])) {
          $data_values[$placeholder . $insert_index] = fopen('php://memory', 'a');
          fwrite($data_values[$placeholder . $insert_index], $insert_values[$field_index]);
          rewind($data_values[$placeholder . $insert_index]);

          $stmt->bindParam($placeholder, $data_values[$placeholder . $insert_index], PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
        }
        else {
          $data_values[$placeholder . $insert_index] = $insert_values[$field_index];
          $stmt->bindParam($placeholder, $data_values[$placeholder . $insert_index]);
        }
      }

      try {
        $stmt->execute();
      }
      catch (\Exception $e) {
        // This INSERT query failed, rollback everything if we started a transaction earlier.
        if (!empty($transaction)) {
          $transaction->rollback();
        }
        // Rethrow the exception.
        throw $e;
      }

      // We can only have 1 identity column per table (or none, where fetchColumn will fail)
      try {
        $last_insert_id = $stmt->fetchColumn(0);
      }
      catch(\PDOException $e) {
        $last_insert_id = NULL;
      }
    }

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = array();

    return $last_insert_id;
    
    #endregion
  }

  public function __toString() {
    
    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->queryColumnInformation($this->table);
    
    
    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    $output = NULL;
    
    // Enable direct insertion to identity columns if necessary.
    if (!empty($this->setIdentity)) {
      $prefix .= 'SET IDENTITY_INSERT {' . $this->table . '} ON;';
    }
    
    // Using PDO->lastInsertId() is not reliable on highly concurrent scenarios.
    // It is much better to use the OUTPUT option of SQL Server.
    if (isset($columnInformation['identities']) && !empty($columnInformation['identities'])) {
      $identities = array_keys($columnInformation['identities']);
      $identity = reset($identities);
      $output = "OUTPUT (Inserted.{$identity})";
    }

    // If we're selecting from a SelectQuery, finish building the query and
    // pass it back, as any remaining options are irrelevant.
    if (!empty($this->fromQuery)) {
      if (empty($this->insertFields)) {
        return $prefix . "INSERT INTO {{$this->table}} {$output}" . $this->fromQuery;
      }
      else {
        $fields_csv = implode(', ', $this->connection->quoteIdentifiers($this->insertFields));
        return $prefix . "INSERT INTO {{$this->table}} ({$fields_csv}) {$output} " . $this->fromQuery;
      }
    }
    
    // Full default insert
    if (empty($this->insertFields)) {
      return $prefix . "INSERT INTO {{$this->table}} {$output} DEFAULT VALUES";
    }

    // Build the list of placeholders.
    $placeholders = array();
    for ($i = 0; $i < count($this->insertFields); ++$i) {
      $placeholders[] = ':db_insert' . $i;
    }

    return $prefix . 'INSERT INTO {' . $this->table . '} (' . implode(', ', $this->connection->quoteIdentifiers($this->insertFields)) . ') ' . $output . ' VALUES (' . implode(', ', $placeholders) . ')';
  }
}
