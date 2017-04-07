<?php
/**
 * @file
 * Contains \Drupal\Core\Database\Driver\sqlsrv\Upsert
 */
namespace Drupal\Driver\Database\sqlsrv;
use Drupal\Core\Database\Query\Upsert as QueryUpsert;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use mssql\Settings\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use mssql\Settings\TransactionScopeOption as DatabaseTransactionScopeOption;
use Drupal\Driver\Database\sqlsrv\TransactionSettings as DatabaseTransactionSettings;
use Drupal\Driver\Database\sqlsrv\Utils as DatabaseUtils;
/**
 * Implements Native Upsert queries for MSSQL.
 */
class Upsert extends QueryUpsert {
  /**
   * @var Connection
   */
  protected $connection;
  /**
   * Result summary of INSERTS/UPDATES after execution.
   *
   * @var string[]
   */
  public $result = NULL;
  /**
   * {@inheritdoc}
   */
  public function execute() {
    // Check that the table does exist.
    if (!$this->connection->schema()->tableExists($this->table)) {
      throw new \Drupal\Core\Database\SchemaObjectDoesNotExistException("Table $this->table does not exist.");
    }
    // Retrieve query options.
    $options = $this->queryOptions;
    // Initialize result array.
    $this->result = [];
    // Keep a reference to the blobs.
    $blobs = [];
    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->getTableIntrospection($this->table);
    // Initialize placeholder count.
    $max_placeholder = 0;
    // Build the query, ensure that we have retries for concurrency control
    $options['integrityretry'] = TRUE;
    $options['prefix_tables'] = FALSE;
    $stmt = $this->connection->prepareQuery((string) $this, $options);
    // 3. Bind the dataset.
    foreach ($this->insertValues as $insert_values) {
      $fields = array_combine($this->insertFields, $insert_values);
      $stmt->BindValues($fields, $blobs, ':db_insert_placeholder_', $columnInformation, $max_placeholder);
    }
    // 4. Run the query, this will return UPDATE or INSERT
    $this->connection->query($stmt, []);
    // Captura the results.
    foreach ($stmt as $value) {
      $this->result[] = $value->{'$action'};
    }
    // Re-initialize the values array so that we can re-use this query.
    $this->insertValues = [];
    return TRUE;
  }
  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Fetch the list of blobs and sequences used on that table.
    $columnInformation = $this->connection->schema()->getTableIntrospection($this->table);
    // Find out if there is an identity field set in this insert.
    $setIdentity = !empty($columnInformation['identity']) && in_array($columnInformation['identity'], array_keys($this->insertFields));
    $query = [];
    $real_table = $this->connection->prefixTable($this->table);
    // Enable direct insertion to identity columns if necessary.
    if ($setIdentity === TRUE) {
      $query[] = "SET IDENTITY_INSERT [$real_table] ON;";
    }
    $query[] = "MERGE INTO [$real_table] _target";
    // 1. Implicit dataset
    // select t.*  from (values(1,2,3), (2,3,4)) as t(col1,col2,col3)
    $values = $this->getInsertPlaceholderFragment($this->insertValues, $this->defaultFields);
    $columns = implode(', ', $this->connection->quoteIdentifiers($this->insertFields));
    $dataset = "SELECT T.* FROM (values" . implode(',', $values) .") as T({$columns})";
    // Build primery key conditions
    $key_conditions = [];
    // Fetch the list of blobs and sequences used on that table.
    $primary_key_cols = array_column($columnInformation['indexes'][$columnInformation['primary_key_index']]['columns'], 'name');
    foreach ($primary_key_cols as $key) {
      $key_conditions[] = "_target.[$key] = _source.[$key]";
    }
    $query[] = "USING ({$dataset}) _source" . PHP_EOL . 'ON ' . implode(' AND ', $key_conditions);
    // Mappings.
    $insert_mappings = [];
    $update_mappings = [];
    foreach ($this->insertFields as $field) {
      $insert_mappings[] = "_source.[$field]";
      // Updating the unique / primary key is not necessary.
      if (!in_array($field, $primary_key_cols)) {
        $update_mappings[] = "_target.[$field] = _source.[$field]";
      }
    }
    // "When matched" part
    $query[] = 'WHEN MATCHED THEN UPDATE SET ' . implode(', ', $update_mappings);
    // "When not matched" part.
    $query[] = "WHEN NOT MATCHED THEN INSERT ({$columns}) VALUES (".  implode(', ', $insert_mappings) .")";
    // Return information about the query.
    $query[] = 'OUTPUT $action;';
    return implode(PHP_EOL, $query);
  }
}