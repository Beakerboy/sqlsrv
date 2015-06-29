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
      $arguments = $this->fromQuery->getArguments();
      DatabaseUtils::BindArguments($stmt, $arguments);

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
    $blobs = array();
    
    // Each insert happens in its own query. However, we wrap it in a transaction
    // so that it is atomic where possible.
    $transaction = NULL;
    if (empty($this->queryOptions['sqlsrv_skip_transactions']) && count($this->insertValues) > 1) {
      $transaction = $this->connection->startTransaction('', DatabaseTransactionSettings::GetBetterDefaults());
    }

    foreach ($this->insertValues as $insert_index => $insert_values) {
      $values = array_combine($this->insertFields, $insert_values);
      $max_placeholder = 0;
      DatabaseUtils::BindValues($stmt, $values, $blobs, ':db_insert', $columnInformation, $max_placeholder, $insert_index);

      $stmt->execute();

      // We can only have 1 identity column per table (or none, where fetchColumn will fail)
      try {
        $last_insert_id = $stmt->fetchColumn(0);
      }
      catch(\PDOException $e) {
        $last_insert_id = NULL;
      }
    }

    // If we started a transaction, commit it.
    if ($transaction) {
      $transaction->commit();
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

  public function execute() {
    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->queryColumnInformation($this->table);

    // MySQL is a pretty slut that swallows everything thrown at it,
    // like trying to update an identity field...
    if (isset($columnInformation['identity']) && isset($this->fields[$columnInformation['identity']])) {
      unset($this->fields[$columnInformation['identity']]);
    }

    // Because we filter $fields the same way here and in __toString(), the
    // placeholders will all match up properly.
    $stmt = $this->connection->PDOPrepare($this->connection->prefixTables((string)$this));

    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->fields;
    DatabaseUtils::BindExpressions($stmt, $this->expressionFields, $fields);

    // We use this array to store references to the blob handles.
    // This is necessary because the PDO will otherwise messes up with references.
    $blobs = array();
    DatabaseUtils::BindValues($stmt, $fields, $blobs, ':db_update_placeholder_', $columnInformation);

    // Add conditions.
    if (count($this->condition)) {
      $this->condition->compile($this->connection, $this);
      $arguments = $this->condition->arguments();
      DatabaseUtils::BindArguments($stmt, $arguments);
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
 * 
 * Tested to be at least 50% faster than parent's implementation.
 */
class MergeQuery_sqlsrv extends MergeQuery {
  public function execute() {

    if (!count($this->condition)) {
      throw new InvalidMergeQueryException(t('Invalid merge query: no conditions'));
    }

    // Keep a reference to the blobs.
    $blobs = array();

    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->queryColumnInformation($this->table);

    // Find out if there is an identity field set in this insert.
    $this->setIdentity = !empty($columnInformation['identity']) && in_array($columnInformation['identity'], array_keys($this->insertFields));

    // Initialize placeholder count.
    $max_placeholder = 0;
    
    // Build the query.
    $stmt = $this->connection->PDOPrepare($this->connection->prefixTables((string)$this));

    // Build the arguments: 1. condition.
    $arguments = $this->condition->arguments();
    DatabaseUtils::BindArguments($stmt, $arguments);

    // 2. When matched part.
    $fields = $this->updateFields;
    DatabaseUtils::BindExpressions($stmt, $this->expressionFields, $fields);
    DatabaseUtils::BindValues($stmt, $fields, $blobs, ':db_merge_placeholder_', $columnInformation, $max_placeholder);

    // 3. When not matched part.
    DatabaseUtils::BindValues($stmt, $this->insertFields, $blobs, ':db_merge_placeholder_', $columnInformation, $max_placeholder);

    // 4. Run the query, this will return UPDATE or INSERT
    $stmt->execute();
    $result = NULL;
    foreach ($stmt as $value) {
      $result = $value->{'$action'};
    }
    switch($result) {
      case 'UPDATE':
        return static::STATUS_UPDATE;
      case 'INSERT':
        return static::STATUS_INSERT;
    } 
  }

  public function __toString() {
    // Initialize placeholder count.
    $max_placeholder = 0;
    $max_placeholder_conditions =  0;

    $query = array();

    // Enable direct insertion to identity columns if necessary.
    if (!empty($this->setIdentity)) {
      $query[] = 'SET IDENTITY_INSERT {' . $this->table . '} ON;';
    }

    $query[] = 'MERGE INTO {' . $this->table . '} _target';

    // 1. Condition part.
    $this->condition->compile($this->connection, $this);
    $key_conditions = array();
    $template_item = array();
    $conditions = $this->conditions();
    unset($conditions['#conjunction']);
    foreach ($conditions as $condition) {
      $key_conditions[] = '_target.' . $this->connection->escapeField($condition['field']) . ' = ' . '_source.' . $this->connection->escapeField($condition['field']);
      $template_item[] = ':db_condition_placeholder_' . $max_placeholder_conditions++ . ' AS ' . $this->connection->escapeField($condition['field']);
    }
    $query[] = 'USING (SELECT ' . implode(', ', $template_item) . ') _source ' . PHP_EOL . 'ON ' . implode(' AND ', $key_conditions);

    // 2. "When matched" part.
    // Expressions take priority over literal fields, so we process those first
    // and remove any literal fields that conflict.
    $fields = $this->updateFields;
    $update_fields = array();
    foreach ($this->expressionFields as $field => $data) {
      $update_fields[] = $field . '=' . $data['expression'];
      unset($fields[$field]);
    }

    foreach ($fields as $field => $value) {
      $update_fields[] = $field . '=:db_merge_placeholder_' . ($max_placeholder++);
    }

    if (!empty($update_fields)) {
      $query[] = 'WHEN MATCHED THEN UPDATE SET ' . implode(', ', $update_fields);
    }

    // 3. "When not matched" part.
    if ($this->insertFields) {
      // Build the list of placeholders.
      $placeholders = array();
      for ($i = 0; $i < count($this->insertFields); ++$i) {
        $placeholders[] = ':db_merge_placeholder_' . ($max_placeholder++);
      }

      $query[] = 'WHEN NOT MATCHED THEN INSERT (' . implode(', ', $this->connection->quoteIdentifiers(array_keys($this->insertFields))) . ') VALUES (' . implode(', ', $placeholders) . ')';
    }
    else {
      $query[] = 'WHEN NOT MATCHED THEN INSERT DEFAULT VALUES';
    }

    // Return information about the query.
    $query[] = 'OUTPUT $action;';

    return implode(PHP_EOL, $query);

  }

}