<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Insert as QueryInsert;

/**
 * @addtogroup database
 * @{
 */

/**
 * Sql Server implementation of \Drupal\Core\Database\Query\Insert.
 */
class Insert extends QueryInsert {

  /**
   * Does the insert require setting an identity column?
   *
   * @var bool
   */
  protected $setIdentity = FALSE;

  /**
   * Max Batch Size.
   *
   * Maximum number of inserts that the driver will perform
   * on a single statement.
   */
  const MAX_BATCH_SIZE = 200;

  /**
   * {@inheritdoc}
   */
  public function execute() {
    if (!$this->preExecute()) {
      return NULL;
    }

    // Fetch the list of blobs and sequences used on that table.
    /** @var \Drupal\Driver\Database\sqlsrv\Schema $schema */
    $schema = $this->connection->schema();
    $columnInformation = $schema->queryColumnInformation($this->table);

    // Find out if there is an identity field set in this insert.
    $this->setIdentity = !empty($columnInformation['identity']) && in_array($columnInformation['identity'], $this->insertFields);
    $identity = !empty($columnInformation['identity']) ? $columnInformation['identity'] : NULL;

    // Retrieve query options.
    $options = $this->queryOptions;

    // Select Based Insert.
    if (!empty($this->fromQuery)) {
      // Re-initialize the values array so that we can re-use this query.
      $this->insertValues = [];

      /** @var \Drupal\Core\Database\Statement $stmt */
      $stmt = $this->connection->prepareQuery((string) $this);

      // Handle the case of SELECT-based INSERT queries first.
      $arguments = $this->fromQuery->getArguments();
      Utils::bindArguments($stmt, $arguments);

      // Run the query.
      $this->connection->query($stmt, [], $options);

      // We can only have 1 identity column per table
      // (or none, where fetchColumn will fail)
      try {
        return $stmt->fetchColumn(0);
      }
      catch (\PDOException $e) {
        return NULL;
      }
    }

    // Inserts with no values (full defaults)
    // Handle the case of full-default queries.
    if (empty($this->fromQuery) && (empty($this->insertFields) || empty($this->insertValues))) {
      // Re-initialize the values array so that we can re-use this query.
      $this->insertValues = [];

      /** @var \Drupal\Core\Database\Statement $stmt */
      $stmt = $this->connection->prepareQuery((string) $this);

      // Run the query.
      $this->connection->query($stmt, [], $options);

      // We can only have 1 identity column per table
      // (or none, where fetchColumn will fail)
      try {
        return $stmt->fetchColumn(0);
      }
      catch (\PDOException $e) {
        return NULL;
      }
    }

    // Regular Inserts.
    $this->insertedKeys = [];

    // Each insert happens in its own query. However, we wrap it in a
    // transaction so that it is atomic where possible.
    $transaction = NULL;

    // At most we can process in batches of $batch_size.
    $batch = array_splice($this->insertValues, 0, min(intdiv(2000, count($this->insertFields)), Insert::MAX_BATCH_SIZE));

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
        Utils::bindValues($stmt, $values, $blobs, ':db_insert', $columnInformation, $max_placeholder, $insert_index);
      }

      // Run the query.
      $this->connection->query($stmt, [], array_merge($options, ['fetch' => \PDO::FETCH_ASSOC]));

      // We can only have 1 identity column per table (or none, where
      // fetchColumnwill fail). When the column does not have an identity
      // column, no results are thrown back.
      foreach ($stmt as $insert) {
        try {
          $this->insertedKeys[] = $insert[$identity];
        }
        catch (\Exception $e) {
          $this->insertedKeys[] = NULL;
        }
      }

      // Fetch the next batch.
      $batch = array_splice($this->insertValues, 0, min(intdiv(2000, count($this->insertFields)), Insert::MAX_BATCH_SIZE));
    }

    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];

    // Return the last inserted key.
    return empty($this->insertedKeys) ? NULL : end($this->insertedKeys);
  }

  /**
   * Give an option to retrieve all keys.
   *
   * @var mixed[]
   */
  private $insertedKeys = [];

  /**
   * Retrieve an array of the keys resulting from the last insert.
   *
   * @return mixed[]
   *   The Keys.
   */
  public function getInsertedKeys() {
    return $this->insertedKeys;
  }

  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Default to a query that inserts everything at the same time.
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
    if ($batch_size > Insert::MAX_BATCH_SIZE) {
      throw new \Exception("MSSQL Native Batch Insert limited to 250.");
    }

    // Fetch the list of blobs and sequences used on that table.
    /** @var \Drupal\Driver\Database\sqlsrv\Schema $schema */
    $schema = $this->connection->schema();
    $columnInformation = $schema->queryColumnInformation($this->table);

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
    else {
      $output = "OUTPUT (1)";
    }

    // If we're selecting from a SelectQuery, finish building the query and
    // pass it back, as any remaining options are irrelevant.
    $escapedFields = [];
    if (!empty($this->fromQuery)) {
      if (empty($this->insertFields)) {
        return $prefix . "INSERT INTO {{$this->table}} {$output}" . $this->fromQuery;
      }
      else {
        foreach ($this->insertFields as $field) {
          $escapedFields[] = $this->connection->escapeField($field);
        }
        $fields_csv = implode(', ', $escapedFields);
        return $prefix . "INSERT INTO {{$this->table}} ({$fields_csv}) {$output} " . $this->fromQuery;
      }
    }

    // Full default insert.
    if (empty($this->insertFields)) {
      return $prefix . "INSERT INTO {{$this->table}} {$output} DEFAULT VALUES";
    }

    // Build the list of placeholders, a set of placeholders
    // for each element in the batch.
    $placeholders = [];
    $field_count = count($this->insertFields);
    for ($j = 0; $j < $batch_size; $j++) {
      $batch_placeholders = [];
      for ($i = 0; $i < $field_count; ++$i) {
        $batch_placeholders[] = ':db_insert' . (($field_count * $j) + $i);
      }
      $placeholders[] = '(' . implode(', ', $batch_placeholders) . ')';
    }
    foreach ($this->insertFields as $field) {
      $escapedFields[] = $this->connection->escapeField($field);
    }
    $fields_csv = implode(', ', $escapedFields);
    $sql = $prefix . 'INSERT INTO {' . $this->table . '} (' . $fields_csv . ') ' . $output . ' VALUES ' . PHP_EOL;
    $sql .= implode(', ', $placeholders) . PHP_EOL;
    return $sql;
  }

}

/**
 * @} End of "addtogroup database".
 */
