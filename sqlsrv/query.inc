<?php

/**
 * SQL Server-specific implementation of INSERT.
 *
 * SQL Server doesn't supports multi-insert queries, and needs special handling for
 * binary columns.
 */
class InsertQuery_sqlsrv extends InsertQuery {

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

/**
 * SQL Server-specific implementation of UPDATE.
 *
 * The specific parts are:
 *  - SQL Server returns the number of matched rows to an UPDATE, and Drupal
 *    requires the number of affected rows to be returned.
 *  - SQL Server requires a special handling for the blobs.
 */
class UpdateQuery_sqlsrv extends UpdateQuery {
  /**
   * Rewrite the query not to select non-affected rows.
   *
   * A query like this one:
   *   UPDATE test SET col1 = 'newcol1', col2 = 'newcol2' WHERE tid = 1
   * will become:
   *   UPDATE test SET col1 = 'newcol1', col2 = 'newcol2' WHERE tid = 1 AND (col1 <> 'newcol1' OR col2 <> 'newcol2')
   */
  protected function excludeNonAffectedRows() {
    if (!empty($this->queryOptions['sqlsrv_return_matched_rows'])) {
      return;
    }

    // Get the fields used in the update query.
    $fields = $this->expressionFields + $this->fields;

    // Add the inverse of the fields to the condition.
    $condition = new DatabaseCondition('OR');
    foreach ($fields as $field => $data) {
      if (is_array($data)) {
        // The field is an expression.
        // Re-bind the placeholders.
        $expression = $data['expression'];
        $arguments = array();
        if (!empty($data['arguments'])) {
          foreach ($data['arguments'] as $placeholder => $value) {
            $new_placeholder = ':db_exclude_placeholder_' . $this->nextPlaceholder();
            $expression = str_replace($placeholder, $new_placeholder, $expression);
            $arguments[$new_placeholder] = $value;
          }
        }
        $condition->where($field . ' <> ' . $expression, $arguments);
        $condition->isNull($field);
      }
      elseif (!isset($data)) {
        // The field will be set to NULL.
        $condition->isNotNull($field);
      }
      else {
        $condition->condition($field, $data, '<>');
        $condition->isNull($field);
      }
    }
    if (count($condition)) {
      // Workaround for a bug in the base MergeQuery implementation:
      // a DatabaseCondition object is reused without being re-compiled,
      // leading to duplicate placeholders.
      $this->nextPlaceholder = 1000000;
      $this->condition($condition);
    }
  }

  public function execute() {
    // Rewrite the query to exclude the non-affected rows.
    // $this->excludeNonAffectedRows();

    // Now perform the special handling for BLOB fields.
    $max_placeholder = 0;

    // Because we filter $fields the same way here and in __toString(), the
    // placeholders will all match up properly.
    $stmt = $this->connection->PDOPrepare($this->connection->prefixTables((string)$this));

    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->queryColumnInformation($this->table);

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    $expression_fields = array();
    foreach ($this->expressionFields as $field => $data) {
      if (!empty($data['arguments'])) {
        foreach ($data['arguments'] as $placeholder => $argument) {
          // We assume that an expression will never happen on a BLOB field,
          // which is a fairly safe assumption to make since in most cases
          // it would be an invalid query anyway.
          $stmt->bindParam($placeholder, $data['arguments'][$placeholder]);
        }
      }
      unset($fields[$field]);
    }

    // We use this array to store references to the blob handles.
    // This is necessary because the PDO will otherwise messes up with references.
    $blobs = array();
    $blob_count = 0;

    foreach ($fields as $field => $value) {
      $placeholder = ':db_update_placeholder_' . ($max_placeholder++);
      if (isset($columnInformation['blobs'][$field])) {
        $blobs[$blob_count] = fopen('php://memory', 'a');
        fwrite($blobs[$blob_count], $value);
        rewind($blobs[$blob_count]);
        $stmt->bindParam($placeholder, $blobs[$blob_count], PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
        $blob_count++;
      }
      else {
        $stmt->bindParam($placeholder, $fields[$field]);
      }
    }

    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);

      $arguments = $this->condition->arguments();
      foreach ($arguments as $placeholder => $value) {
        $stmt->bindParam($placeholder, $arguments[$placeholder]);
      }
    }

    $options = $this->queryOptions;
    $options['already_prepared'] = TRUE;
    $stmt->execute();

    return $stmt->rowCount();
  }

  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    $update_fields = array();
    foreach ($this->expressionFields as $field => $data) {
      $update_fields[] = $this->connection->quoteIdentifier($field) . '=' . $data['expression'];
      unset($fields[$field]);
    }

    $max_placeholder = 0;
    foreach ($fields as $field => $value) {
      $update_fields[] = $this->connection->quoteIdentifier($field) . '=:db_update_placeholder_' . ($max_placeholder++);
    }

    $query = $prefix . 'UPDATE {' . $this->connection->escapeTable($this->table) . '} SET ' . implode(', ', $update_fields);

    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      // There is an implicit string cast on $this->condition.
      $query .= "\nWHERE " . $this->condition;
    }

    return $query;
  }
}

/**
 * SQL Server-specific implementation of TRUNCATE.
 */
class TruncateQuery_sqlsrv extends TruncateQuery {
  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    return $prefix . 'TRUNCATE TABLE {' . $this->connection->escapeTable($this->table) . '} ';
  }
}

/**
 * SQL Server-specific implementation of the MERGE operation.
 */
class MergeQuery_sqlsrv extends MergeQuery {
  public function execute() {
    // We don't need INSERT or UPDATE queries to trigger additional transactions.
    $this->queryOptions['sqlsrv_skip_transactions'] = TRUE;

    return parent::execute();
  }
}
