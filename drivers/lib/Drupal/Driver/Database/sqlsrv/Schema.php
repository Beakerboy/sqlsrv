<?php
/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Schema
 */
namespace Drupal\Driver\Database\sqlsrv;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\Schema as DatabaseSchema;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Database\SchemaException as DatabaseSchemaException;
use Drupal\Core\Database\SchemaObjectDoesNotExistException as DatabaseSchemaObjectDoesNotExistException;
use Drupal\Core\Database\SchemaObjectExistsException as DatabaseSchemaObjectExistsException;
use mssql\Settings\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use mssql\Settings\TransactionScopeOption as DatabaseTransactionScopeOption;
use mssql\Utils as DatabaseUtils;
use mssql\Settings\ConstraintTypes;
use mssql\Scheme;
use Drupal\Driver\Database\sqlsrv\TransactionSettings as DatabaseTransactionSettings;
use PDO as PDO;
use Exception as Exception;
use PDOException as PDOException;
use PDOStatement as PDOStatement;
/**
 * @addtogroup schemaapi
 * @{
 */
class Schema extends DatabaseSchema {
  /**
   * Connection.
   *
   * @var Connection
   */
  protected $connection;
  /**
   * Default schema for SQL Server databases.
   */
  public $defaultSchema = 'dbo';
  /**
   * Default recommended collation for SQL Server.
   */
  const DEFAULT_COLLATION_CI = 'Latin1_General_CI_AI';
  /**
   * Used when binary => TRUE in the SCHEMA.
   */
  const DEFAULT_COLLATION_CS = 'Latin1_General_CS_AS';
  /**
   * Used when collation set to ascii_bin.
   */
  const DEFAULT_COLLATION_BINARY = 'Latin1_General_BIN2';

  // Name for the technical column used for computed keys
  // or technical primary key.
  // IMPORTANT: They both start with "__" because the
  // statement class will remove those columns from the final
  // result set.
  // This should be constants, but we are using variable to ease
  // their use in inline strings.
  var $COMPUTED_PK_COLUMN_NAME = '__pkc';
  var $COMPUTED_PK_COLUMN_INDEX = '__ix_pkc';
  var $TECHNICAL_PK_COLUMN_NAME = '__pk';
  protected function getTechnicalPrimaryKeyIndexName($table) {
    return "{$table}_pkey_technical";
  }
  /**
   * Returns a list of functions that are not
   * available by default on SQL Server, but used
   * in Drupal Core or contributed modules
   * because they are available in other databases
   * such as MySQL.
   */
  public function DrupalSpecificFunctions() {
    static $cache;
    if (!isset($cache)) {
      $cache = ['SUBSTRING','SUBSTRING_INDEX','GREATEST','MD5','LPAD','GROUP_CONCAT','IF','CONNECTION_ID'];
      // Since SQL Server 2012 (11), there is a native CONCAT implementation
      $version = $this->connection->Scheme()->EngineVersion()->Version();
      if (version_compare($version, '11', '<')) {
        $cache[] = 'CONCAT';
      }
    }
    return $cache;
  }
  protected function prefixTable($table) {
    return $this->connection->prefixTables("{{$table}}");
  }
  /**
   * Clear introspection cache for a specific table.
   *
   * @param string $table
   */
  public function getTableIntrospectionInvalidate($table) {
    $this->connection->Scheme()->TableDetailsInvalidate($this->prefixTable($table));
  }
  /**
   * Retrieve details about the table.
   *
   * @param string $table
   */
  public function getTableIntrospection($table) {
    return $this->connection->Scheme()->TableDetailsGet($this->prefixTable($table));
  }
  /**
   * Get details about a column.
   *
   * @param string $table
   *
   * @param string|array $column
   *
   * @return array
   */
  public function getColumnIntrospection($table, $columns) {
    return $this->connection->Scheme()->ColumnDetailsGet($this->prefixTable($table), $columns);
  }
  /**
   * {@Inheritdoc}
   */
  public function createTable($name, $table) {
    if ($this->tableExists($name)) {
      throw new SchemaObjectExistsException(t('Table %name already exists.', array('%name' => $name)));
    }
    // Reset caches after calling tableExists() otherwise it's results get cached again before
    // the table is created.
    $this->getTableIntrospectionInvalidate($name);
    // Build the table and its unique keys in a transaction, and fail the whole
    // creation in case of an error.
    /** @var Transaction $transaction */
    $transaction = $this->connection->startTransaction(NULL, DatabaseTransactionSettings::GetDDLCompatibleDefaults());
    // Create the table with a default technical primary key.
    // $this->createTableSql already prefixes the table name, and we must inhibit prefixing at the query level
    // because field default _context_menu_block_active_values definitions can contain string literals with braces.
    $this->connection->query_direct($this->createTableSql($name, $table), [], array('prefix_tables' => FALSE));
    // If the spec had a primary key, set it now after all fields have been created.
    // We are creating the keys after creating the table so that createPrimaryKey
    // is able to introspect column definition from the database to calculate index sizes
    // This adds quite quite some overhead, but is only noticeable during table creation.
    if (isset($table['primary key']) && is_array($table['primary key'])) {
      $this->createPrimaryKey($name,  $table['primary key']);
    }
    // Otherwise use a technical primary key.
    else {
      $this->createTechnicalPrimaryColumn($name);
    }
    // Now all the unique keys.
    if (isset($table['unique keys']) && is_array($table['unique keys'])) {
      foreach ($table['unique keys'] as $key_name => $key) {
        $this->addUniqueKey($name, $key_name, $key);
      }
    }
    // Commit changes until now.
    $transaction->commit();
    // Add table comment.
    if (!empty($table['description'])) {
      if ($this->tableExists($name)) {
        $comment = $this->prepareComment($table['description'], Scheme::COMMENT_MAX_BYTES);
        $this->connection->Scheme()->CommentCreate($comment, $name);
      }
    }
    // Add column comments.
    foreach ($table['fields'] as $field_name => $field) {
      if (!empty($field['description'])) {
        $comment = $this->prepareComment($table['description'], Scheme::COMMENT_MAX_BYTES);
        $this->connection->Scheme()->CommentCreate($comment, $name, $field_name);
      }
    }
    // Create the indexes but ignore any error during the creation. We do that
    // do avoid pulling the carpet under modules that try to implement indexes
    // with invalid data types (long columns), before we come up with a better
    // solution.
    if (isset($table['indexes']) && is_array($table['indexes'])) {
      foreach ($table['indexes'] as $key_name => $key) {
        try {
          $this->addIndex($name, $key_name, $key);
        }
        catch (Exception $e) {
          // Log the exception but do not rollback the transaction.
          watchdog_exception('database', $e);
        }
      }
    }
    // Invalidate introspection cache.
    $this->getTableIntrospectionInvalidate($name);
  }
  /**
   * Find if a table already exists. Results are cached, use
   * $reset = TRUE to get a fresh copy.
   *
   * @param $table
   *   Name of the table.
   * @return
   *   True if the table exists, false otherwise.
   */
  public function tableExists($table) {
    return $this->connection->Scheme()->TableExists($this->connection->prefixTable($table));
  }
  /**
   * Drops the current primary key and creates
   * a new one. If the previous primary key
   * was an internal primary key, it tries to cleant it up.
   *
   * @param mixed $table
   * @param mixed $primary_key_sql
   */
  protected function recreatePrimaryKey($table, $fields) {
    // Drop the existing primary key if exists, if it was a TPK
    // it will get completely dropped.
    $this->cleanUpPrimaryKey($table);
    $this->createPrimaryKey($table, $fields);
  }
  /**
   * Create a Primary Key for the table, does not drop
   * any prior primary keys neither it takes care of cleaning
   * technical primary column. Only call this if you are sure
   * the table does not currently hold a primary key.
   *
   * @param string $table
   * @param mixed $fields
   * @param int $limit
   */
  private function createPrimaryKey($table, $fields, $limit = 900) {
    // To be on the safe side, on the most restrictive use case the limit
    // for a primary key clustered index is of 128 bytes (usually 900).
    // @see http://blogs.msdn.com/b/jgalla/archive/2005/08/18/453189.aspx
    // If that is going to be exceeded, use a computed column.
    $csv_fields = $this->createKeySql($fields, FALSE);
    $real_table = $this->connection->prefixTable($table);
    $size = $this->connection->Scheme()->calculateClusteredIndexRowSizeBytes($real_table, $this->createKeySql($fields, TRUE));
    $result = [];
    $index = FALSE;
    // Add support for nullable columns in a primary key.
    $nullable = FALSE;
    $field_specs = $this->connection->schema()->getColumnIntrospection($table, $fields);
    foreach ($field_specs as $field) {
      if ($field['is_nullable'] == TRUE) {
        $nullable = TRUE;
        break;
      }
    }
    if ($nullable || $size >= $limit) {
      // Use a computed column instead, and create a custom index.
      $result[] = "{$this->COMPUTED_PK_COLUMN_NAME} AS (CONVERT(VARCHAR(32), HASHBYTES('MD5', CONCAT('', {$csv_fields})), 2)) PERSISTED NOT NULL";
      $result[] = "CONSTRAINT {$real_table}_pkey PRIMARY KEY CLUSTERED ({$this->COMPUTED_PK_COLUMN_NAME})";
      $index = TRUE;
    }
    else {
      $result[] = "CONSTRAINT {$real_table}_pkey PRIMARY KEY CLUSTERED ({$csv_fields})";
    }
    $add = implode(' ', $result);
    $this->connection->query_direct("ALTER TABLE [$real_table] ADD $add");
    // If we relied on a computed column for the Primary Key,
    // at least index the fields with a regular index.
    if ($index) {
      $this->addIndex($table, $this->COMPUTED_PK_COLUMN_INDEX, $fields);
    }
    // Invalidate current introspection.
    $this->getTableIntrospectionInvalidate($table);
  }
  /**
   * Generate SQL to create a new table from a Drupal schema definition.
   *
   * @param $name
   *   The name of the table to create.
   * @param $table
   *   A Schema API table definition array.
   * @return
   *   The SQL statement to create the table.
   */
  protected function createTableSql($name, $table) {
    $sql_fields = [];
    foreach ($table['fields'] as $field_name => $field) {
      $sql_fields[] = $this->createFieldSql($name, $field_name, $this->processField($field));
    }
    // Use already prefixed table name.
    $table_prefixed = $this->connection->prefixTable($name);
    $sql = "CREATE TABLE [{$table_prefixed}] (" . PHP_EOL;
    $sql .= implode("," . PHP_EOL, $sql_fields);
    $sql .= PHP_EOL . ")";
    return $sql;
  }
  /**
   * Create an SQL string for a field to be used in table creation or
   * alteration.
   *
   * Before passing a field out of a schema definition into this
   * function it has to be processed by _db_process_field().
   *
   *
   *
   * @param $table
   *    The name of the table.
   * @param $name
   *    Name of the field.
   * @param $spec
   *    The field specification, as per the schema data structure format.
   */
  protected function createFieldSql($table, $name, $spec, $skip_checks = FALSE) {
    $sql = $this->connection->quoteIdentifier($name) . ' ' . $spec['sqlsrv_type'];
    // When binary is true, case sensitivity is requested.
    if (isset($spec['sqlsrv_collation'])) {
      $sql .= ' COLLATE ' . $spec['sqlsrv_collation'];
    }
    if (isset($spec['not null']) && $spec['not null']) {
      $sql .= ' NOT NULL';
    }
    if (!$skip_checks) {
      if (isset($spec['default'])) {
        // Use a prefixed table.
        $table_prefixed = $this->connection->prefixTables('{' . $table . '}');
        $default = $this->connection->Scheme()->DefaultValueExpression($spec['sqlsrv_type'], $spec['default']);
        $sql .= " CONSTRAINT {$table_prefixed}_{$name}_df DEFAULT  $default";
      }
      if (!empty($spec['identity'])) {
        $sql .= ' IDENTITY';
      }
      if (!empty($spec['unsigned'])) {
        $sql .= ' CHECK (' . $this->connection->quoteIdentifier($name) . ' >= 0)';
      }
    }
    return $sql;
  }

  /**
   * Returns a list of field names coma separated ready
   * to be used in a SQL Statement.
   *
   * @param array $fields
   * @param boolean $as_array
   * @return array|string
   */
  protected function createKeySql($fields, $as_array = FALSE) {
    $ret = [];
    foreach ($fields as $field) {
      if (is_array($field)) {
        $ret[] = $field[0];
      }
      else {
        $ret[] = $field;
      }
    }
    if ($as_array) {
      return $ret;
    }
    // If this is a CSV it is to be used
    // in a query, so escape everything!
    foreach ($ret as $r) {
      $r = "[$r]";
    }
    return implode(', ', $ret);
  }
  /**
   * Returns the SQL needed (incomplete) to create and index. Supports XML indexes.
   *
   * @param string $table
   *   Table to create the index on.
   *
   * @param string $name
   *   Name of the index.
   *
   * @param array $fields
   *   Fields to be included in the Index.
   *
   * @return string
   */
  protected function createIndexSql($table, $name, $fields, &$xml_field) {
    // Get information about current columns.
    $info = $this->getTableIntrospection($table);
    // Flatten $fields array if neccesary.
    $fields = $this->createKeySql($fields, TRUE);
    // Look if an XML column is present in the fields list.
    $xml_field = NULL;
    foreach ($fields as $field) {
      $type = $info['columns'][$field]['type'] ?? NULL;
      if ($type == 'xml') {
        $xml_field = $field;
        break;
      }
    }
    // XML indexes can only have 1 column.
    if (!empty($xml_field) && isset($fields[1])) {
      throw new \Exception("Cannot include an XML field on a multiple column index.");
    }
    // No more than one XML index per table.
    if ($xml_field && $this->tableHasXmlIndex($table)) {
      throw new \Exception("Only one primary clustered XML index is allowed per table.");
    }
    if (empty($xml_field)) {
      // TODO: As we are already doing with primary keys, when a user requests
      // an index that is too big for SQL Server (> 900 bytes) this could be dependant
      // on a computed hash column.
      $fields_csv = implode(', ', $fields);
      return "CREATE INDEX {$name}_idx ON [{{$table}}] ({$fields_csv})";
    }
    else {
      return "CREATE PRIMARY XML INDEX {$name}_idx ON [{{$table}}] ({$xml_field})";
    }
  }
  /**
   * Determine what the correct sql server collation
   * is for a specific field.
   *
   * @param array $field
   *   The field specification
   */
  protected function getMssqlCollation(array $field) {
    // The ascii_bin type is just a text field with a special collation,
    // it is the fastest collation available on MSSQL Server to compare string
    // as it does this at the binary level.
    if (in_array($field['type'], ['ascii_bin', 'varchar_ascii'])) {
      return self::DEFAULT_COLLATION_BINARY;
    }
    // The collation property is out of specification,
    // but used sometimes in contrib (such as advagg).
    if (!empty($field['collation'])) {
      // Try to match to an SQL Server collation
      switch($field['collation']) {
        case 'ascii_bin':
          return self::DEFAULT_COLLATION_BINARY;
      }
    }
    // Temporary workaround for issue in core.
    // @see https://www.drupal.org/node/2580671
    // TODO: Remove when this is fixed in core.
    $mysqltype = $field['mysql_type'] ?? NULL;
    if ($mysqltype === 'blob') {
      return self::DEFAULT_COLLATION_BINARY;
    }
    // Finally, use CS or CI
    $binary = $field['binary'] ?? FALSE;
    return $binary ? self::DEFAULT_COLLATION_CS : self::DEFAULT_COLLATION_CI;
  }
  /**
   * Set database-engine specific properties for a field.
   *
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function processField($field) {
    // Default size to normal.
    $field['size'] = $field['size'] ?? 'normal';
    // Set the correct database-engine specific datatype.
    if (!isset($field['sqlsrv_type'])) {
      $map = $this->getFieldTypeMap();
      $field['sqlsrv_type'] = $map[$field['type'] . ':' . $field['size']];
      $field['sqlsrv_type_simple'] = DatabaseUtils::GetMSSQLType($field['sqlsrv_type']);
    }
    // Serial is identity.
    $field['identity'] = $field['type'] == 'serial';
    // If this is a text field.
    $field['sqlsrv_is_text'] = $field['sqlsrv_is_text'] ?? DatabaseUtils::IsTextType($field['sqlsrv_type']);
    if ($field['sqlsrv_is_text']) {
      $field['sqlsrv_collation'] = $field['sqlsrv_collation'] ?? $this->getMssqlCollation($field);
    }
    // Adjust the length of the field if specified
    if (isset($field['length']) && $field['length'] > 0) {
      $field['sqlsrv_type'] = $field['sqlsrv_type_simple'] . '(' . $field['length'] . ')';
    }
    // Adjust numeric fields.
    if (in_array($field['sqlsrv_type_simple'], ['numeric', 'decimal']) && isset($field['precision']) && isset($field['scale'])) {
      // Maximum precision for SQL Server 2008 or greater is 38.
      // For previous versions it's 28.
      if ($field['precision'] > 38) {
        $field['precision'] = 38;
      }
      $field['sqlsrv_type'] = $field['sqlsrv_type_simple'] . '(' . $field['precision'] . ', ' . $field['scale'] . ')';
    }
    return $field;
  }
  /**
   * This maps a generic data type in combination with its data size
   * to the engine-specific data type.
   */
  function getFieldTypeMap() {
    // Put :normal last so it gets preserved by array_flip.  This makes
    // it much easier for modules (such as schema.module) to map
    // database types back into schema types.
    return [
      'varchar:normal' => 'nvarchar',
      'char:normal' => 'nchar',
      'varchar_ascii:normal' => 'varchar(255)',
      'text:tiny' => 'nvarchar(255)',
      'text:small' => 'nvarchar(255)',
      'text:medium' => 'nvarchar(max)',
      'text:big' => 'nvarchar(max)',
      'text:normal' => 'nvarchar(max)',
      'serial:tiny'     => 'smallint',
      'serial:small'    => 'smallint',
      'serial:medium'   => 'int',
      'serial:big'      => 'bigint',
      'serial:normal'   => 'int',
      'int:tiny' => 'smallint',
      'int:small' => 'smallint',
      'int:medium' => 'int',
      'int:big' => 'bigint',
      'int:normal' => 'int',
      'float:tiny' => 'real',
      'float:small' => 'real',
      'float:medium' => 'real',
      'float:big' => 'float(53)',
      'float:normal' => 'real',
      'numeric:normal' => 'numeric',
      'blob:big' => 'varbinary(max)',
      'blob:normal' => 'varbinary(max)',
      'datetime:normal' => 'timestamp',
      'date:normal'     => 'date',
      'datetime:normal' => 'datetime2(0)',
      'time:normal'     => 'time(0)',
    ];
  }
  /**
   * Override DatabaseSchema::renameTable().
   *
   * @status complete
   */
  public function renameTable($table, $new_name) {
    if (!$this->tableExists($table)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot rename %table to %table_new: table %table doesn't exist.", array('%table' => $table, '%table_new' => $new_name)));
    }
    if ($this->tableExists($new_name)) {
      throw new DatabaseSchemaObjectExistsException(t("Cannot rename %table to %table_new: table %table_new already exists.", array('%table' => $table, '%table_new' => $new_name)));
    }
    $old_table_info = $this->getPrefixInfo($table);
    $new_table_info = $this->getPrefixInfo($new_name);
    // We don't support renaming tables across schemas (yet).
    if ($old_table_info['schema'] != $new_table_info['schema']) {
      throw new PDOException(t('Cannot rename a table across schema.'));
    }
    $this->connection->query_direct('EXEC sp_rename :old, :new', array(
      ':old' => $old_table_info['schema'] . '.' . $old_table_info['table'],
      ':new' => $new_table_info['table'],
    ));
    // Constraint names are global in SQL Server, so we need to rename them
    // when renaming the table. For some strange reason, indexes are local to
    // a table.
    $objects = $this->connection->query_direct('SELECT name FROM sys.objects WHERE parent_object_id = OBJECT_ID(:table)', array(':table' => $new_table_info['schema'] . '.' . $new_table_info['table']));
    foreach ($objects as $object) {
      if (preg_match('/^' . preg_quote($old_table_info['table']) . '_(.*)$/', $object->name, $matches)) {
        $this->connection->query_direct('EXEC sp_rename :old, :new, :type', array(
          ':old' => $old_table_info['schema'] . '.' . $object->name,
          ':new' => $new_table_info['table'] . '_' . $matches[1],
          ':type' => 'OBJECT',
        ));
      }
    }
    $this->connection->GetConnection()->Cache('sqlsrv-table-exists')->Clear($old_table_info['table']);
  }
  /**
   * {@inhertidoc}
   */
  public function dropTable($table) {
    return $this->connection->Scheme()->TableDrop($this->connection->prefixTables('{' . $table . '}'));
  }
  /**
   * {@inhertidoc}
   */
  public function fieldExists($table, $field) {
    return $this->connection->Scheme()->FieldExists($this->connection->prefixTables('{' . $table . '}'), $field);
  }
  /**
   * Override DatabaseSchema::addField().
   *
   * @status complete
   */
  public function addField($table, $field, $spec, $new_keys = []) {
    if (!$this->tableExists($table)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot add field %table.%field: table doesn't exist.", array('%field' => $field, '%table' => $table)));
    }
    if ($this->fieldExists($table, $field)) {
      throw new DatabaseSchemaObjectExistsException(t("Cannot add field %table.%field: field already exists.", array('%field' => $field, '%table' => $table)));
    }
    /** @var Transaction $transaction */
    $transaction = $this->connection->startTransaction(NULL, DatabaseTransactionSettings::GetDDLCompatibleDefaults());
    // Prepare the specifications.
    $spec = $this->processField($spec);
    // Clear column information for table.
    $this->getTableIntrospectionInvalidate($table);
    // Use already prefixed table name.
    $table_prefixed = $this->connection->prefixTable($table);
    // If the field is declared NOT NULL, we have to first create it NULL insert
    // the initial data (or populate default values) and then switch to NOT NULL.
    $fixnull = FALSE;
    if (!empty($spec['not null'])) {
      $fixnull = TRUE;
      $spec['not null'] = FALSE;
    }
    // Create the field.
    // Because the default values of fields can contain string literals
    // with braces, we CANNOT allow the driver to prefix tables because the algorithm
    // to do so is a crappy str_replace.
    $query = "ALTER TABLE {$table_prefixed} ADD ";
    $query .= $this->createFieldSql($table, $field, $spec);
    $this->connection->query_direct($query, [], array('prefix_tables' => FALSE));
    // Clear column information for table.
    $this->getTableIntrospectionInvalidate($table);
    // Load the initial data.
    if (isset($spec['initial'])) {
      $this->connection->update($table)
        ->fields(array($field => $spec['initial']))
        ->execute();
    }
    // Switch to NOT NULL now.
    if ($fixnull === TRUE) {
      // There is no warranty that the old data did not have NULL values, we need to populate
      // nulls with the default value because this won't be done by MSSQL by default.
      if (isset($spec['default'])) {
        $default_expression = $this->connection->Scheme()->DefaultValueExpression($spec['sqlsrv_type'], $spec['default']);
        $this->connection->query_direct("UPDATE [$table_prefixed] SET [$field] = $default_expression WHERE [$field] IS NULL", [], array('prefix_tables' => FALSE));
      }
      // Now it's time to make this non-nullable.
      $spec['not null'] = TRUE;
      $this->connection->query_direct("ALTER TABLE [$table_prefixed] ALTER COLUMN " . $this->createFieldSql($table, $field, $spec, TRUE), [], array('prefix_tables' => FALSE));
    }
    // Add the new keys.
    if (isset($new_keys)) {
      $this->recreateTableKeys($table, $new_keys);
    }
    // Commit.
    $transaction->commit();
    // Add column comment.
    if (!empty($spec['description'])) {
      $comment = $this->prepareComment($spec['description'], Scheme::COMMENT_MAX_BYTES);
      $this->connection->Scheme()->CommentCreate($comment, $table, $field);
    }
    // Clear column information for table.
    $this->getTableIntrospectionInvalidate($table);
  }
  /**
   * Sometimes the size of a table's primary key index needs
   * to be reduced to allow for Primary XML Indexes.
   *
   * @param string $table
   * @param int $limit
   */
  public function compressPrimaryKeyIndex($table, $limit = 900) {
    // Introspect the schema and save the current primary key if the column
    // we are modifying is part of it.
    $primary_key_fields = $this->introspectPrimaryKeyFields($table);
    // SQL Server supports transactional DDL, so we can just start a transaction
    // here and pray for the best.
    /** @var Transaction $transaction */
    $transaction = $this->connection->startTransaction(NULL, DatabaseTransactionSettings::GetDDLCompatibleDefaults());
    // Clear current Primary Key.
    $this->cleanUpPrimaryKey($table);
    // Recreate the Primary Key with the given limit size.
    $this->createPrimaryKey($table, $primary_key_fields, $limit);
    $transaction->commit();
    // Refresh introspection for this table.
    $this->getTableIntrospectionInvalidate($table);
  }
  /**
   * Override DatabaseSchema::changeField().
   *
   * @status complete
   */
  public function changeField($table, $field, $field_new, $spec, $new_keys = []) {
    if (!$this->fieldExists($table, $field)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot change the definition of field %table.%name: field doesn't exist.", array('%table' => $table, '%name' => $field)));
    }
    if (($field != $field_new) && $this->fieldExists($table, $field_new)) {
      throw new DatabaseSchemaObjectExistsException(t("Cannot rename field %table.%name to %name_new: target field already exists.", array('%table' => $table, '%name' => $field, '%name_new' => $field_new)));
    }
    // SQL Server supports transactional DDL, so we can just start a transaction
    // here and pray for the best.
    $real_table = $this->connection->prefixTable($table);
    /** @var Transaction $transaction */
    $transaction = $this->connection->startTransaction(NULL, DatabaseTransactionSettings::GetDDLCompatibleDefaults());
    // Prepare the specifications.
    $spec = $this->processField($spec);
    // IMPORTANT NOTE: To maintain database portability, you have to explicitly recreate all indices and primary keys that are using the changed field.
    // That means that you have to drop all affected keys and indexes with db_drop_{primary_key,unique_key,index}() before calling db_change_field().
    // @see https://api.drupal.org/api/drupal/includes!database!database.inc/function/db_change_field/7
    //
    // What we are going to do in the SQL Server Driver is a best-effort try to preserve original keys if they do not conflict
    // with the new_keys parameter, and if the callee has done it's job (droping constraints/keys) then they will of course not be recreated.
    // Retrive the original field specification.
    $original_field_spec = $this->connection->schema()->getColumnIntrospection($table, $field);
    // Introspect the schema and save the current primary key if the column
    // we are modifying is part of it. Make sure the schema is FRESH.
    $this->getTableIntrospectionInvalidate($table);
    $primary_key_fields = $this->introspectPrimaryKeyFields($table);
    if (in_array($field, $primary_key_fields)) {
      // Let's drop the PK
      $this->cleanUpPrimaryKey($table);
    }
    // If there is a generated unique key for this field, we will need to
    // add it back in when we are done
    $unique_key = $this->uniqueKeyExists($table, $field);
    // Drop the related objects.
    $this->dropFieldRelatedObjects($table, $field);
    // Start by renaming the current column.
    $this->connection->query_direct('EXEC sp_rename :old, :new, :type', array(
      ':old' => "{$real_table}.{$field}",
      ':new' => "{$field}_old",
      ':type' => 'COLUMN',
    ));
    // If the new column does not allow nulls, we need to
    // create it first as nullable, then either migrate
    // data from previous column or populate default values.
    $fixnull = FALSE;
    if (!empty($spec['not null'])) {
      $fixnull = TRUE;
      $spec['not null'] = FALSE;
    }
    // Create a new field.
    $this->addField($table, $field_new, $spec);
    // Conversiones between data types are not trivial...
    $collation = isset($spec['sqlsrv_collation']) ? $spec['sqlsrv_collation'] : null;
    $converted_expression = DatabaseUtils::convertTypes("[{$field}_old]", $original_field_spec['type'], $spec['sqlsrv_type'], $collation);
    $this->connection->query_direct("UPDATE [$real_table] SET [$field_new] = $converted_expression");
    // Switch to NOT NULL now.
    if ($fixnull === TRUE) {
      // There is no warranty that the old data did not have NULL values, we need to populate
      // nulls with the default value because this won't be done by MSSQL by default.
      if (isset($spec['default'])) {
        $default_expression = $this->connection->Scheme()->DefaultValueExpression($spec['sqlsrv_type'], $spec['default']);
        $this->connection->query_direct("UPDATE [$real_table] SET [$field_new] = $default_expression WHERE [$field_new] IS NULL", [], array('prefix_tables' => FALSE));
      }
      // Now it's time to make this non-nullable.
      $spec['not null'] = TRUE;
      $this->connection->query_direct("ALTER TABLE [$real_table] ALTER COLUMN " . $this->createFieldSql($table, $field_new, $spec, TRUE), [], array('prefix_tables' => FALSE));
    }
    // Initialize new keys.
    if (!isset($new_keys)) {
      $new_keys = array(
        'unique keys' => [],
        'primary keys' => []
      );
    }
    // Recreate the primary key if no new primary key
    // has been sent along with the change field.
    if (in_array($field, $primary_key_fields) && (!isset($new_keys['primary keys']) || empty($new_keys['primary keys']))) {
      // The new primary key needs to have
      // the new column name.
      unset($primary_key_fields[$field]);
      $primary_key_fields[$field_new] = $field_new;
      $new_keys['primary key'] = $primary_key_fields;
    }
    // Recreate the unique constraint if it existed.
    if ($unique_key && !isset($new_keys['unique keys']) && !in_array($field_new, $new_keys['unique keys'])) {
      $new_keys['unique keys'][] = $field_new;
    }
    // Drop the old field.
    $this->dropField($table, $field . '_old');
    // Add the new keys.
    $this->recreateTableKeys($table, $new_keys);
    // Refresh introspection for this table.
    $this->getTableIntrospectionInvalidate($table);
    // Commit.
    $transaction->commit();
  }
  /**
   * Get the list of fields participating in the Primary Key
   *
   * @param string $table
   * @param string $field
   *
   * @return string[]
   */
  public function introspectPrimaryKeyFields($table) {
    $data = $this->getTableIntrospection($table);
    // All primary keys have a default index,
    // use that to see if we have a primary key
    // before iterating.
    if (!isset($data['primary_key_index']) || !isset($data['indexes'][$data['primary_key_index']])) {
      return [];
    }
    $result = [];
    $index = $data['indexes'][$data['primary_key_index']];
    foreach ($index['columns'] as $column) {
      if ($column['name'] != $this->COMPUTED_PK_COLUMN_NAME) {
        $result[$column['name']] = $column['name'];
      }
      // Get full column definition
      $c = $data['columns'][$column['name']];
      // If this column depends on other columns
      // the other columns are also part of the index!
      // We don't support nested computed columns here.
      foreach ($c['dependencies'] as $name => $order) {
        $result[$name] = $name;
      }
    }
    return $result;
  }
  /**
   * Re-create keys associated to a table.
   */
  protected function recreateTableKeys($table, $new_keys) {
    if (isset($new_keys['primary key'])) {
      $this->addPrimaryKey($table, $new_keys['primary key']);
    }
    if (isset($new_keys['unique keys'])) {
      foreach ($new_keys['unique keys'] as $name => $fields) {
        $this->addUniqueKey($table, $name, $fields);
      }
    }
    if (isset($new_keys['indexes'])) {
      foreach ($new_keys['indexes'] as $name => $fields) {
        $this->addIndex($table, $name, $fields);
      }
    }
  }
  /**
   * Override DatabaseSchema::dropField().
   *
   * @status complete
   */
  public function dropField($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      return FALSE;
    }
    // Drop the related objects.
    $this->dropFieldRelatedObjects($table, $field);
    $this->connection->query('ALTER TABLE {' . $table . '} DROP COLUMN ' . $field);
    // Clear introspection cache.
    $this->getTableIntrospectionInvalidate($table);
    return TRUE;
  }
  /**
   * Drop the related objects of a column (indexes, constraints, etc.).
   *
   * @status complete
   */
  protected function dropFieldRelatedObjects($table, $field) {
    // Fetch the list of indexes referencing this column.
    $indexes = $this->connection->query('SELECT DISTINCT i.name FROM sys.columns c INNER JOIN sys.index_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id INNER JOIN sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id WHERE i.is_primary_key = 0 AND i.is_unique_constraint = 0 AND c.object_id = OBJECT_ID(:table) AND c.name = :name', array(
      ':table' => $this->connection->prefixTables('{' . $table . '}'),
      ':name' => $field,
    ));
    foreach ($indexes as $index) {
      $this->connection->query('DROP INDEX [' . $index->name . '] ON [{' . $table . '}]');
    }
    // Fetch the list of check constraints referencing this column.
    $constraints = $this->connection->query('SELECT DISTINCT cc.name FROM sys.columns c INNER JOIN sys.check_constraints cc ON cc.parent_object_id = c.object_id AND cc.parent_column_id = c.column_id WHERE c.object_id = OBJECT_ID(:table) AND c.name = :name', array(
      ':table' => $this->connection->prefixTables('{' . $table . '}'),
      ':name' => $field,
    ));
    foreach ($constraints as $constraint) {
      $this->connection->query('ALTER TABLE [{' . $table . '}] DROP CONSTRAINT [' . $constraint->name . ']');
    }
    // Fetch the list of default constraints referencing this column.
    $constraints = $this->connection->query('SELECT DISTINCT dc.name FROM sys.columns c INNER JOIN sys.default_constraints dc ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id WHERE c.object_id = OBJECT_ID(:table) AND c.name = :name', array(
      ':table' => $this->connection->prefixTables('{' . $table . '}'),
      ':name' => $field,
    ));
    foreach ($constraints as $constraint) {
      $this->connection->query('ALTER TABLE [{' . $table . '}] DROP CONSTRAINT [' . $constraint->name . ']');
    }
    // Drop any indexes on related computed columns when we have some.
    if ($this->uniqueKeyExists($table, $field)) {
      $this->dropUniqueKey($table, $field);
    }
    // If this column is part of a computed primary key, drop the key.
    $data = $this->getTableIntrospection($table);
    if (isset($data['columns'][$this->COMPUTED_PK_COLUMN_NAME]['dependencies'][$field])) {
      $this->cleanUpPrimaryKey($table);
    }
    // If this column is part of a primary key, drop the key.
    if (isset($data['primary_key_index']) && !empty($data['primary_key_index'])) {
      $pk_index = $data['primary_key_index'];
      if (in_array($field, array_column($data['indexes'][$pk_index]['columns'], 'name'))) {
        // Get rid of the primary key...
        $this->cleanUpPrimaryKey($table);
      }
    }
  }
  /**
   * Override DatabaseSchema::fieldSetDefault().
   *
   * @status complete
   */
  public function fieldSetDefault($table, $field, $default) {
    if (!$this->fieldExists($table, $field)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot set default value of field %table.%field: field doesn't exist.", array('%table' => $table, '%field' => $field)));
    }

    $transaction = $this->connection->startTransaction(NULL, DatabaseTransactionSettings::GetDDLCompatibleDefaults());

    $real_table = $this->connection->prefixTable($table);
    $constraint_name = "{$real_table}_{$field}_df";

    $spec = $this->getTableIntrospection($table);
    $default_expression = $this->connection->Scheme()->DefaultValueExpression($spec['columns'][$field]['sqlsrv_type'], $default);

    // Try to remove any existing default first.
    try {
      $this->fieldSetNoDefault($table, $field);
    }
    catch (Exception $e) {}

    // Create the new default.
    $this->connection->query_direct("ALTER TABLE [$real_table] ADD CONSTRAINT [$constraint_name] DEFAULT $default_expression FOR [$field]", [], array('prefix_tables' => FALSE));

    $transaction->commit();
  }
  /**
   * Override DatabaseSchema::fieldSetNoDefault().
   *
   * @status complete
   */
  public function fieldSetNoDefault($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot remove default value of field %table.%field: field doesn't exist.", array('%table' => $table, '%field' => $field)));
    }

    $real_table = $this->connection->prefixTable($table);
    $constraint_name = "{$real_table}_{$field}_df";

    // Avoid PDO exception!!
    if (!$this->connection->Scheme()->ConstraintExists($constraint_name, new ConstraintTypes(ConstraintTypes::CDEFAULT))) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot remove default value of field %table.%field: default value constraint doesn't exist.", array('%table' => $table, '%field' => $field)));
    }

    $this->connection->query_direct("ALTER TABLE [$real_table] DROP CONSTRAINT [$constraint_name]");
  }
  /**
   * Override DatabaseSchema::addPrimaryKey().
   *
   * @status tested
   */
  public function addPrimaryKey($table, $fields) {
    if (!$this->tableExists($table)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot add primary key to table %table: table doesn't exist.", array('%table' => $table)));
    }
    if ($primary_key_name = $this->primaryKeyName($table)) {
      if ($this->isTechnicalPrimaryKey($primary_key_name)) {
        // Destroy the existing technical primary key.
        $this->connection->query_direct('ALTER TABLE [' . $this->connection->prefixTable($table) . '] DROP CONSTRAINT [' . $primary_key_name . ']');
        $this->cleanUpTechnicalPrimaryColumn($table);
      }
      else {
        throw new DatabaseSchemaObjectExistsException(t("Cannot add primary key to table %table: primary key already exists.", array('%table' => $table)));
      }
    }
    // The size limit of the primary key depends on the
    // cohexistance with an XML field.
    if ($this->tableHasXmlIndex($table)) {
      $this->createPrimaryKey($table, $fields, 128);
    }
    else {
      $this->createPrimaryKey($table, $fields);
    }
    return TRUE;
  }
  /**
   * Override DatabaseSchema::dropPrimaryKey().
   *
   * @status tested
   */
  public function dropPrimaryKey($table) {
    if (!$this->primaryKeyName($table)) {
      return FALSE;
    }
    $this->cleanUpPrimaryKey($table);
    $this->createTechnicalPrimaryColumn($table);
    return TRUE;
  }
  /**
   * Return the name of the primary key of a table if it exists.
   */
  protected function primaryKeyName($table) {
    $table = $this->connection->prefixTables('{' . $table . '}');
    return $this->connection->query('SELECT name FROM sys.key_constraints WHERE parent_object_id = OBJECT_ID(:table) AND type = :type', array(
      ':table' => $table,
      ':type' => 'PK',
    ))->fetchField();
  }
  /**
   * Check if a key is a technical primary key.
   */
  protected function isTechnicalPrimaryKey($key_name) {
    return $key_name && preg_match('/_pkey_technical$/', $key_name);
  }
  /**
   * Make sure that this table has a technical primary key.
   *
   * @param string $table
   */
  protected function createTechnicalPrimaryColumn($table) {
    $real_table = $this->connection->prefixTable($table);
    // Make sure the column exists.
    if (!$this->fieldExists($table, $this->TECHNICAL_PK_COLUMN_NAME)) {
      $this->connection->query_direct("ALTER TABLE $real_table ADD $this->TECHNICAL_PK_COLUMN_NAME UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL");
    }
    // Make sure the index exists.
    $index = $this->getTechnicalPrimaryKeyIndexName($table);
    if (!$this->connection->Scheme()->IndexExists($real_table, $index)) {
      // Wether or not the TKP is a PRIMARY KEY depends
      // on the existence of OTHER primary keys.
      if (empty($this->introspectPrimaryKeyFields($table))) {
        $this->connection->query_direct("ALTER TABLE $real_table ADD CONSTRAINT $index PRIMARY KEY CLUSTERED ($this->TECHNICAL_PK_COLUMN_NAME)");
      }
      else {
        $this->connection->query_direct("CREATE UNIQUE INDEX $index ON [$real_table] ($this->TECHNICAL_PK_COLUMN_NAME)");
      }
    }
  }
  /**
   * Drop the primary key constraint.
   * @param mixed $table
   */
  protected function cleanUpPrimaryKey($table) {
    // We are droping the constraint, but not the column.
    if ($existing_primary_key = $this->primaryKeyName($table)) {
      $this->connection->query("ALTER TABLE [{{$table}}] DROP CONSTRAINT {$existing_primary_key}");
    }
    // We are using computed columns to store primary keys,
    // try to remove it if it exists.
    if ($this->fieldExists($table, $this->COMPUTED_PK_COLUMN_NAME)) {
      // The TCPK has compensation indexes that need to be cleared.
      $this->dropIndex($table, $this->COMPUTED_PK_COLUMN_INDEX);
      $this->dropField($table, $this->COMPUTED_PK_COLUMN_NAME);
    }
    // Try to get rid of the TPC
    $this->cleanUpTechnicalPrimaryColumn($table);
  }
  /**
   * Tries to clean up the technical primary column. It will
   * be deleted if
   * (a) It is not being used as the current primary key and...
   * (b) There is no unique constraint because they depend on this column (see addUniqueKey())
   *
   * @param string $table
   */
  protected function cleanUpTechnicalPrimaryColumn($table) {
    // Get the number of remaining unique indexes on the table, that
    // are not primary keys and prune the technical primary column if possible.
    $unique_indexes = $this->connection->query('SELECT COUNT(*) FROM sys.indexes WHERE object_id = OBJECT_ID(:table) AND is_unique = 1 AND is_primary_key = 0', array(':table' => $this->connection->prefixTables('{' . $table . '}')))->fetchField();
    $primary_key_is_technical = $this->isTechnicalPrimaryKey($this->primaryKeyName($table));
    if (!$unique_indexes && !$primary_key_is_technical) {
      $this->dropField($table, $this->TECHNICAL_PK_COLUMN_NAME);
    }
  }
  /**
   * Override DatabaseSchema::addUniqueKey().
   *
   * Why are we not simply adding a UNIQUE index
   * on the columns? Because on MySQL you can have a NULL
   * value on one of these columns, but not on MSSQL. So
   * we build the UNIQUE constraint on top of a HASH.
   *
   * @status tested
   */
  public function addUniqueKey($table, $name, $fields) {

    if (!$this->tableExists($table)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot add unique key %name to table %table: table doesn't exist.", array('%table' => $table, '%name' => $name)));
    }

    if ($this->uniqueKeyExists($table, $name)) {
      throw new DatabaseSchemaObjectExistsException(t("Cannot add unique key %name to table %table: unique key already exists.", array('%table' => $table, '%name' => $name)));
    }

    // The ANSI standard says that unique constraints should allow
    // multiple nulls. Check al columns in the constraint, if any of them is nullable,
    // make the constraint depend on the technical primary key.
    $info = $this->getColumnIntrospection($table, $fields);
    $has_nullables = FALSE;
    foreach ($info as $column) {
      // is_nullable has a 0 or 1 integer values.
      if ($column['is_nullable'] == TRUE) {
        $has_nullables = TRUE;
        break;
      }
    }

    // If there is a nullable column in the unique constraint
    // we need some workaround to emulate the ANSI behaviour.
    if ($has_nullables) {

      $this->createTechnicalPrimaryColumn($table);

      // Then, build a expression based on the columns.
      $column_expression = [];
      foreach ($fields as $field) {
        if (is_array($field)) {
          $column_expression[] = "SUBSTRING(CAST([$field[0]] AS varbinary(max)), 1, [$field[1]])";
        }
        else {
          $column_expression[] = "CAST([$field] AS varbinary(max))";
        }
      }
      $column_expression = implode(' + ', $column_expression);


      // Build a computed column based on the expression that replaces NULL
      // values with the globally unique identifier generated previously.
      // This is (very) unlikely to result in a collision with any actual value
      // in the columns of the unique key.
      $this->connection->query("ALTER TABLE {{$table}} ADD __unique_{$name} AS CAST(HashBytes('MD4', COALESCE({$column_expression}, CAST({$this->TECHNICAL_PK_COLUMN_NAME} AS varbinary(max)))) AS varbinary(16))");
      $this->connection->query("CREATE UNIQUE INDEX {$name}_unique ON [{{$table}}] (__unique_{$name})");
    }
    else {
      $column_expression = [];
      foreach ($fields as $field) {
        if (is_array($field)) {
          $column_expression[] = $field[0];
        }
        else {
          $column_expression[] = $field;
        }
      }
      array_walk($column_expression, [$this->connection, 'escapeField']);
      $column_expression = implode(',', $column_expression);
      $this->connection->query("CREATE UNIQUE INDEX {$name}_unique ON [{{$table}}] ({$column_expression})");
    }
  }
  /**
   * Override DatabaseSchema::dropUniqueKey().
   */
  public function dropUniqueKey($table, $name) {
    if (!$this->uniqueKeyExists($table, $name)) {
      return FALSE;
    }
    $this->connection->query("DROP INDEX {$name}_unique ON [{{$table}}]");
    // Some unique keys do not have an emulated unique column.
    if ($this->connection->Scheme()->FieldExists($table, "__unique_{$name}")) {
      $this->connection->query("ALTER TABLE [{{$table}}] DROP COLUMN __unique_$name");
    }
    // Try to clean-up the technical primary key if possible.
    $this->cleanUpTechnicalPrimaryColumn($table);
    return TRUE;
  }
  /**
   * Find if an unique key exists.
   *
   * @status tested
   */
  protected function uniqueKeyExists($table, $name) {
    $table = $this->connection->prefixTables('{' . $table . '}');
    return (bool) $this->connection->query('SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(:table) AND name = :name', array(
      ':table' => $table,
      ':name' => $name . '_unique',
    ))->fetchField();
  }
  /**
   * Override DatabaseSchema::addIndex().
   *
   * @status tested
   */
  public function addIndex($table, $name, $fields, array $spec = []) {
    if (!$this->tableExists($table)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot add index %name to table %table: table doesn't exist.", array('%table' => $table, '%name' => $name)));
    }
    if ($this->indexExists($table, $name)) {
      throw new DatabaseSchemaObjectExistsException(t("Cannot add index %name to table %table: index already exists.", array('%table' => $table, '%name' => $name)));
    }
    $xml_field = NULL;
    $sql = $this->createIndexSql($table, $name, $fields, $xml_field);
    if (!empty($xml_field)) {
      // We can create an XML field, but the current primary key index
      // size needs to be under 128bytes.
      $pk_fields = $this->introspectPrimaryKeyFields($table);
      $size = $this->calculateClusteredIndexRowSizeBytes($table, $pk_fields, TRUE);
      if ($size > Scheme::INDEX_MAX_SIZE_WITH_XML) {
        // Alright the compress the index.
        $this->compressPrimaryKeyIndex($table, Scheme::INDEX_MAX_SIZE_WITH_XML);
      }
    }
    $this->connection->query($sql);
    $this->getTableIntrospectionInvalidate($table);
  }
  /**
   * Override DatabaseSchema::dropIndex().
   *
   * @status tested
   */
  public function dropIndex($table, $name) {
    if (!$this->indexExists($table, $name)) {
      return FALSE;
    }
    $expand = FALSE;
    if (($index = $this->tableHasXmlIndex($table)) && $index == ($name . '_idx')) {
      $expand = TRUE;
    }
    $this->connection->Scheme()->IndexDrop($this->connection->prefixTable($table), $name . '_idx');
    // If we just dropped an XML index, we can re-expand the original primary key index.
    if ($expand) {
      $this->compressPrimaryKeyIndex($table);
    }
    $this->getTableIntrospectionInvalidate($table);
    return TRUE;
  }
  protected function tableHasXmlIndex($table) {
    return $this->connection->Scheme()->TableHasXmlIndex($this->connection->prefixTable($table));
  }
  /**
   * Override DatabaseSchema::indexExists().
   *
   * @status tested
   */
  public function indexExists($table, $name) {
    return $this->connection->Scheme()->IndexExists($this->connection->prefixTable($table), $name . '_idx');
  }
  public function copyTable($name, $table) {
    throw new \Exception("Method not implemented.");
  }
  /**
   * @param $comment
   *
   * @param int $length
   *
   * @return bool|string
   */
  public function prepareComment($comment, $length = NULL) {
    // Truncate comment to maximum comment length.
    if (isset($length)) {
      // Add table prefixes before truncating.
      $comment = Unicode::truncateBytes($this->connection->prefixTables($comment), $length, TRUE, TRUE);
    }
    return $this->connection->quote($comment);
  }
  /**
   * Retrieve a table or column comment.
   */
  public function getComment($table, $column = NULL) {
    return $this->connection->Scheme()->CommentGet($this->connection->prefixTables("{{$table}}"), $column);
  }
  /**
   * Return the default schema.
   *
   * @return mixed
   */
  public function GetDefaultSchema() {
    return $this->connection->Scheme()->GetDefaultSchema();
  }
}
/**
 * @} End of "addtogroup schemaapi".
 */