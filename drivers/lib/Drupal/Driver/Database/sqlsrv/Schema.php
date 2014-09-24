<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Schema
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Component\Utility\String;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\Query\Condition;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\Schema as DatabaseSchema;

/**
 * @addtogroup schemaapi
 * @{
 */

class Schema extends DatabaseSchema {

  /**
   * Maximum length of a comment in SQL Server.
   */
  const COMMENT_MAX_BYTES = 7500;

  /**
   * Default schema for SQL Server databases.
   */
  public $defaultSchema = 'dbo';

  protected $additionalColumnInformation = NULL;

  public function resetTableQuerySchemaCache($table){
    $table_info = $this->getPrefixInfo($table);
    $key = $table_info['schema'] . '.' . $table_info['table'];
  }
  
  /**
   * Database introspection: fetch technical information about a table.
   */
  public function queryColumnInformation($table) {
    $table_info = $this->getPrefixInfo($table);
    $key = $table_info['schema'] . '.' . $table_info['table'];
    
    if (!isset($this->additionalColumnInformation[$key])) {

      
      $this->additionalColumnInformation[$key] = array();

      // Don't use {} around information_schema.columns table.
      $result = $this->connection->query("SELECT name AS column_name FROM sys.columns WHERE object_id = OBJECT_ID(:table) AND user_type_id = TYPE_ID(:type)", array(':table' => $table_info['schema'] . '.' . $table_info['table'], ':type' => 'varbinary'));
      foreach ($result as $column) {
        $this->additionalColumnInformation[$key]['blobs'][$column->column_name] = TRUE;
      }

      // Don't use {} around system tables.
      $result = $this->connection->query('SELECT name column_name FROM sys.identity_columns WHERE object_id = OBJECT_ID(:table)', array(':table' => $table_info['schema'] . '.' . $table_info['table']));
      foreach ($result as $column) {
        $this->additionalColumnInformation[$key]['identities'][$column->column_name] = TRUE;
      }
      
    }

    return $this->additionalColumnInformation[$key];
  }

  public function copyTable($name, $table) {
    throw new \Error("Method not implemented.");
  }
  
  // TODO: implement the same for alter table and add/remove fields.
  public function createTable($name, $table) {
    // Reset the additional column information because the schema changed.
    $this->additionalColumnInformation = NULL;
    $this->resetTableQuerySchemaCache($name);

    if ($this->tableExists($name)) {
      throw new DatabaseSchemaObjectExistsException(t('Table %name already exists.', array('%name' => $name)));
    }

    // Build the table and its unique keys in a transaction, and fail the whole
    // creation in case of an error.
    $transaction = $this->connection->startTransaction();
    try {
      $this->connection->query($this->createTableSql($name, $table));

      if (isset($table['unique keys']) && is_array($table['unique keys'])) {
        foreach ($table['unique keys'] as $key_name => $key) {
          $this->addUniqueKey($name, $key_name, $key);
        }
      }
    }
    catch (Exception $e) {
      $transaction->rollback();
      throw $e;
    }

    // Everything went well, commit the transaction.
    unset($transaction);
    
    // Add table comment.
    if (!empty($table['description'])) {
      if ($this->tableExists($name)) {
        $this->connection->query($this->createDescriptionSql($this->prepareComment($table['description'], self::COMMENT_MAX_BYTES), $name));
      }
    }

    // Add column comments.
    foreach ($table['fields'] as $field_name => $field) {
      if (!empty($field['description'])) {
        $this->connection->query($this->createDescriptionSql($this->prepareComment($field['description'], self::COMMENT_MAX_BYTES), $name, $field_name));
      }
    }

    // Create the indexes but ignore any error during the creation. We do that
    // do avoid pulling the carpet under modules that try to implement indexes
    // with invalid data types (long columns), before we come up with a better
    // solution.
    if (isset($table['indexes']) && is_array($table['indexes'])) {
      foreach ($table['indexes'] as $key_name => $key) {
        try {
          $this->connection->query($this->createIndexSql($name, $key_name, $key));
        }
        catch (DatabaseExceptionWrapper $e) {
          // Log the exception but do not rollback the transaction.
          watchdog_exception('database', $e);
        }
      }
    }

  }

  /**
   * Find if a table already exists.
   *
   * @param $table
   *   Name of the table.
   * @return
   *   True if the table exists, false otherwise.
   */
  public function tableExists($table) {
    return $this->connection
    ->query("SELECT 1 FROM INFORMATION_SCHEMA.tables WHERE table_name = '" . $this->connection->prefixTables('{' . $table . '}') . "'")
    ->fetchField() !== FALSE;
  }
  
  public function EngineVersion() {
    return $this->connection
    ->query("SELECT SERVERPROPERTY('productversion') AS VERSION, SERVERPROPERTY('productlevel') AS LEVEL, SERVERPROPERTY('edition') AS EDITION")
    ->fetchObject();
  }
  
  /**
   * Find if a table function exists.
   *
   * @param $function
   *   Name of the function.
   * @return
   *   True if the function exists, false otherwise.
   */
  public function functionExists($function) {
    return $this->connection
    ->query("SELECT 1 FROM Information_schema.Routines where Specific_schema='" . $this->defaultSchema . "' and specific_name = '" . $function . "' and Routine_Type='FUNCTION'")
    ->fetchField() !== FALSE;
  }
  
  public function setRecoveryModel($model) {
    $this->connection->query("ALTER " . $this->connection->options['name'] . " model SET RECOVERY " . $model);
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
    $sql_fields = array();
    foreach ($table['fields'] as $field_name => $field) {
      $sql_fields[] = $this->createFieldSql($name, $field_name, $this->processField($field));
    }

    // If the table has no primary key, create one for us.
    // TODO: only necessary on Azure.
    if (isset($table['primary key']) && is_array($table['primary key'])) {
      $sql_fields[] = 'CONSTRAINT {' . $name . '}_pkey PRIMARY KEY CLUSTERED (' . implode(', ', $this->connection->quoteIdentifiers($table['primary key'])) . ')';
    }
    else {
      $sql_fields[] = '__pk UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL';
      $sql_fields[] = 'CONSTRAINT {' . $name . '}_pkey_technical PRIMARY KEY CLUSTERED (__pk)';
    }

    $sql = "CREATE TABLE [{" . $name . "}] (\n\t";
    $sql .= implode(",\n\t", $sql_fields);
    $sql .= "\n)";

    // Add table comment.
    if (!empty($table['description'])) {
      $sql .= '; ' . $this->createDescriptionSql($this->prepareComment($table['description'], self::COMMENT_MAX_BYTES), $name, NULL);
    }
    return $sql;
  }

  /**
   * Create an SQL string for a field to be used in table creation or
   * alteration.
   *
   * Before passing a field out of a schema definition into this
   * function it has to be processed by _db_process_field().
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

    if (in_array($spec['sqlsrv_type'], array('char', 'varchar', 'text', 'nchar', 'nvarchar', 'ntext')) && !empty($spec['length'])) {
      $sql .= '(' . $spec['length'] . ')';
    }
    elseif (in_array($spec['sqlsrv_type'], array('numeric', 'decimal')) && isset($spec['precision']) && isset($spec['scale'])) {
      $sql .= '(' . $spec['precision'] . ', ' . $spec['scale'] . ')';
    }

    if (isset($spec['not null']) && $spec['not null']) {
      $sql .= ' NOT NULL';
    }

    if (!$skip_checks) {
      if (isset($spec['default'])) {
        $default = is_string($spec['default']) ? "'" . addslashes($spec['default']) . "'" : $spec['default'];
        $sql .= ' CONSTRAINT {' . $table . '}_' . $name . '_df DEFAULT ' . $default;
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
   * Return a list of columns for an index definition.
   */
  protected function createKeySql($fields) {
    $ret = array();
    foreach ($fields as $field) {
      if (is_array($field)) {
        $ret[] = $field[0];
      }
      else {
        $ret[] = $field;
      }
    }
    return implode(', ', $ret);
  }

  /**
   * Return the SQL Statement to create an index.
   */
  protected function createIndexSql($table, $name, $fields) {
    // Here we need to create a computed PERSISTENT column, and index that, when
    // the type is not allowed in an index.
    return 'CREATE INDEX ' . $name . '_idx ON [{' . $table . '}] (' . $this->createKeySql($fields) . ')';
  }
  
  
  /**
   * Return the SQL statement to create or update a description.
   */
  protected function createDescriptionSql($value, $table = NULL, $column = NULL) {
    // Inside the same transaction, you won't be able to read uncommited extended properties
    // leading to SQL Exception if calling sp_addextendedproperty twice on same object.
    static $columns;
    if (!isset($columns)) {
      $columns = array();
    }

    $schema = $this->defaultSchema;
    $table_info = $this->getPrefixInfo($table);
    $table = $table_info['table'];
    $name = 'MS_Description';
    
    // Determine if a value exists for this database object.
    $key = $this->defaultSchema . '.' .  $table . '.' . $column;
    if(isset($columns[$key])) {
      $result = $columns[$key];
    } else {
      $result = $this->getComment($table, $column);
    }
    $columns[$key] = $value;

    // Only continue if the new value is different from the existing value.
    $sql = '';
    if ($result !== $value) {
      if ($value == '') {
        $sp = "sp_dropextendedproperty";
        $sql = "EXEC " . $sp . " @name=N'" . $name;
      }
      else {
        if ($result != '') {
          $sp = "sp_updateextendedproperty";
        }
        else {
          $sp = "sp_addextendedproperty";
        }
        $sql = "EXEC " . $sp . " @name=N'" . $name . "', @value=" . $value . "";
      }
      if (isset($schema)) {
        $sql .= ",@level0type = N'Schema', @level0name = '". $schema ."'";
        if (isset($table)) {
          $sql .= ",@level1type = N'Table', @level1name = '". $table ."'";
          if ($column !== NULL) {
            $sql .= ",@level2type = N'Column', @level2name = '". $column ."'";
          }
        }
      }
    }

    return $sql;
  }

  /**
   * Set database-engine specific properties for a field.
   *
   * @param $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function processField($field) {
    if (!isset($field['size'])) {
      $field['size'] = 'normal';
    }
    // Set the correct database-engine specific datatype.
    if (!isset($field['sqlsrv_type'])) {
      $map = $this->getFieldTypeMap();
      $field['sqlsrv_type'] = $map[$field['type'] . ':' . $field['size']];
    }
    if ($field['type'] == 'serial') {
      $field['identity'] = TRUE;
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
    return array(
      'varchar:normal' => 'nvarchar',
      'char:normal' => 'nchar',

      'text:tiny' => 'nvarchar(max)',
      'text:small' => 'nvarchar(max)',
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
    );
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
      throw new DatabaseExceptionWrapper(t('Cannot rename a table across schema.'));
    }

    $this->connection->query('EXEC sp_rename :old, :new', array(
      ':old' => $old_table_info['schema'] . '.' . $old_table_info['table'],
      ':new' => $new_table_info['table'],
    ));

    // Constraint names are global in SQL Server, so we need to rename them
    // when renaming the table. For some strange reason, indexes are local to
    // a table.
    $objects = $this->connection->query('SELECT name FROM sys.objects WHERE parent_object_id = OBJECT_ID(:table)', array(':table' => $new_table_info['schema'] . '.' . $new_table_info['table']));
    foreach ($objects as $object) {
      if (preg_match('/^' . preg_quote($old_table_info['table']) . '_(.*)$/', $object->name, $matches)) {
        $this->connection->query('EXEC sp_rename :old, :new, :type', array(
          ':old' => $old_table_info['schema'] . '.' . $object->name,
          ':new' => $new_table_info['table'] . '_' . $matches[1],
          ':type' => 'OBJECT',
        ));
      }
    }
  }

  /**
   * Override DatabaseSchema::dropTable().
   *
   * @status tested
   */
  public function dropTable($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }

    $this->connection->query('DROP TABLE {' . $table . '}');
    return TRUE;
  }

  public function fieldExists($table, $field) {
    try {
      $this->connection->query('SELECT TOP(1) [' . $field . '] FROM {' . $table . '}');
      return TRUE;
    }
    catch (DatabaseExceptionWrapper $e) {
      return FALSE;
    }
  }

  /**
   * Override DatabaseSchema::addField().
   *
   * @status complete
   */
  public function addField($table, $field, $spec, $new_keys = array()) {
    $this->resetTableQuerySchemaCache($table);
    if (!$this->tableExists($table)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot add field %table.%field: table doesn't exist.", array('%field' => $field, '%table' => $table)));
    }
    if ($this->fieldExists($table, $field)) {
      throw new DatabaseSchemaObjectExistsException(t("Cannot add field %table.%field: field already exists.", array('%field' => $field, '%table' => $table)));
    }
    
    // Prevent serial from having initial values.
    if ($spec['type'] == 'serial' && isset($spec['initial'])) {
      throw new DatabaseExceptionWrapper('Cannot add serial (IDENTITY) field with initial values in SQL Server.');
    }

    // If the field is declared NOT NULL, we have to first create it NULL insert
    // the initial data then switch to NOT NULL.
    if (!empty($spec['not null']) && !isset($spec['default'])) {
      $fixnull = TRUE;
      $spec['not null'] = FALSE;
    }

    // Create the field.
    $query = 'ALTER TABLE {' . $table . '} ADD ';
    $query .= $this->createFieldSql($table, $field, $this->processField($spec));
    $this->connection->query($query);

    // Reset the blob cache.
    $this->additionalColumnInformation = NULL;

    // Load the initial data.
    if (isset($spec['initial'])) {
      $this->connection->update($table)
        ->fields(array($field => $spec['initial']))
        ->execute();
    }

    // Switch to NOT NULL now.
    if (!empty($fixnull)) {
      $spec['not null'] = TRUE;
      $this->connection->query('ALTER TABLE {' . $table . '} ALTER COLUMN ' . $this->createFieldSql($table, $field, $this->processField($spec), TRUE));
    }

    // Add the new keys.
    if (isset($new_keys)) {
      $this->recreateTableKeys($table, $new_keys);
    }

    // Add column comment.
    if (!empty($spec['description'])) {
      $this->connection->query($this->createDescriptionSql($this->prepareComment($spec['description'], self::COMMENT_MAX_BYTES), $table, $field));
    }
  }
  
  public function prepareComment($comment, $length = NULL) {
    // Truncate comment to maximum comment length.
    if (isset($length)) {
      // Add table prefixes before truncating.
      $comment = drupal_truncate_bytes($this->connection->prefixTables($comment), $length, TRUE, TRUE);
    }
    return $this->connection->quote($comment);
  }
  
  /**
   * Retrieve a table or column comment.
   */
  public function getComment($table, $column = NULL) {
    $schema = $this->defaultSchema;
    $sql = "SELECT value FROM fn_listextendedproperty ('MS_Description','Schema','" . $schema . "','Table','" . $table . "',";
    if (isset($column)) {
      $sql .= "'Column','" . $column . "')";
    }
    else {
      $sql .= "NULL,NULL)";
    }
    $comment = $this->connection->query($sql)->fetchField();
    return $comment;
  }

  /**
   * Override DatabaseSchema::changeField().
   *
   * @status complete
   */
  public function changeField($table, $field, $field_new, $spec, $new_keys = array()) {
    $this->resetTableQuerySchemaCache($table);
    if (!$this->fieldExists($table, $field)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot change the definition of field %table.%name: field doesn't exist.", array('%table' => $table, '%name' => $field)));
    }
    if (($field != $field_new) && $this->fieldExists($table, $field_new)) {
      throw new DatabaseSchemaObjectExistsException(t("Cannot rename field %table.%name to %name_new: target field already exists.", array('%table' => $table, '%name' => $field, '%name_new' => $field_new)));
    }
    if($spec['type'] == 'serial') {
      throw new Exception(t('Cannot change %table.%field to %field_new with destination serial TYPE (IDENTITY) on SQL SERVER',
          array('%table' => $table, '%field' => $field, '%field_new' => $field_new)));
    }
    // SQL Server supports transactional DDL, so we can just start a transaction
    // here and pray for the best.
    $transaction = $this->connection->startTransaction();

    // Introspect the schema and save the current primary key if the column
    // we are modifying is part of it.
    $primary_key_sql = $this->introspectPrimaryKey($table, $field);

    // If there is a generated unique key for this field, we will need to
    // add it back in when we are done
    $unique_key = $this->uniqueKeyExists($table, $field);

    // Drop the related objects.
    $this->dropFieldRelatedObjects($table, $field);

    // Start by renaming the current column.
    $this->connection->query('EXEC sp_rename :old, :new, :type', array(
      ':old' => $this->connection->prefixTables('{' . $table . '}.' . $field),
      ':new' => $field . '_old',
      ':type' => 'COLUMN',
    ));

    // If the field is declared NOT NULL, we have to first create it NULL insert
    // the initial data then switch to NOT NULL.
    if (!empty($spec['not null']) && !isset($spec['default'])) {
      $fixnull = TRUE;
      $spec['not null'] = FALSE;
    }

    // Create a new field.
    $this->addField($table, $field_new, $spec);

    // Migrate the data over.
    // Explicitly cast the old value to the new value to avoid conversion errors.
    $field_spec = $this->processField($spec);
    $this->connection->query('UPDATE [{' . $table . '}] SET [' . $field_new . '] = CAST([' . $field . '_old] AS ' . $field_spec['sqlsrv_type'] . ')');

    // Switch to NOT NULL now.
    if (!empty($fixnull)) {
      $spec['not null'] = TRUE;
      $this->connection->query('ALTER TABLE {' . $table . '} ALTER COLUMN ' . $this->createFieldSql($table, $field_new, $this->processField($spec), TRUE));
    }

    // Recreate the primary key.
    if ($primary_key_sql) {
      $this->recreatePrimaryKey($table, $primary_key_sql);
    }
    if ($unique_key) {
      $fields = array();
      $fields[] = $field;
      $this->addUniqueKey($table, $field, $fields);
    }

    // Drop the old field.
    $this->dropField($table, $field . '_old');

    // Add the new keys.
    if (isset($new_keys)) {
      $this->recreateTableKeys($table, $new_keys);
    }
  }

  /**
   * Gets collation for current connection,
   * whether it has or not a database defined in
   * it.
   *
   * @status needs review
   */
  public function getCollation($table = NULL, $column = NULL) {
    // Database is defaulted from active connection.
    $options = $this->connection->getConnectionOptions();
    $database = $options['database'];
    if (!empty($database)) {
      // Default collation for specific table.
      $sql = "SELECT CONVERT (varchar, DATABASEPROPERTYEX('$database', 'collation'))";
      return $this->connection->query($sql)->fetchField();
    }
    else {
      // Server default collation.
      $sql = "SELECT SERVERPROPERTY ('collation') as collation";
      return $this->connection->query($sql)->fetchField();
    }
    if (!empty($table) && $empty($column)) {
      $sql = <<< EOF
      SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLLATION_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ':schema'
        AND TABLE_NAME = ':table'
        AND COLUMN_NAME = ':column'
EOF
;
      $params = array();
      $params[':schema'] = $this->defaultSchema;
      $params[':table'] = $table;
      $params[':column'] = $column;
      $result = $this->connection->query($sql, $params)->fetchObject();
      return $result->COLLATION_NAME;
    }
  }
  
  protected function introspectPrimaryKey($table, $field) {
    // Fetch the list of columns participating to the primary key.
    $result = $this->connection->query('SELECT i.name, ic.is_descending_key, c.name column_name
      FROM sys.columns c
       INNER JOIN sys.index_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id
       INNER JOIN sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id
      WHERE i.object_id = OBJECT_ID(:table) AND i.is_primary_key = 1 ORDER BY ic.key_ordinal', array(
      ':table' => $this->connection->prefixTables('{' . $table . '}'),
    ));
    $columns = array();
    $valid = FALSE;
    foreach ($result as $column) {
      if ($column->column_name == $field) {
        $valid = TRUE;
      }
      $columns[] = '[' . $column->column_name . ']' . ($column->is_descending_key ? ' DESC' : '');
    }
    if ($valid) {
      return 'ADD CONSTRAINT [' . $column->name . '] PRIMARY KEY CLUSTERED (' . implode(', ', $columns) . ')';
    }
  }

  protected function recreatePrimaryKey($table, $primary_key_sql) {
    // Drop the existing primary key if exists.
    if ($existing_primary_key = $this->primaryKeyName($table)) {
      $this->connection->query('ALTER TABLE [{' . $table . '}] DROP CONSTRAINT [' . $existing_primary_key . ']');
    }

    $this->connection->query('ALTER TABLE [{' . $table . '}] ' . $primary_key_sql);
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
    $this->resetTableQuerySchemaCache($table);
    if (!$this->fieldExists($table, $field)) {
      return FALSE;
    }

    // Drop the related objects.
    $this->dropFieldRelatedObjects($table, $field);

    $this->connection->query('ALTER TABLE {' . $table . '} DROP COLUMN ' . $field);
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

    if ($default === NULL) {
      $default = 'NULL';
    }
    elseif (is_string($default)) {
      $default = "'" . addslashes($spec['default']) . "'";      
    }

    // Try to remove any existing default first.
    try { $this->fieldSetNoDefault($table, $field); } catch (Exception $e) {}

    // Create the new default.
    $this->connection->query('ALTER TABLE [{' . $table . '}] ADD CONSTRAINT {' . $table . '}_' . $field . '_df DEFAULT ' . $default . ' FOR [' . $field . ']');
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

    $this->connection->query('ALTER TABLE [{' . $table . '}] DROP CONSTRAINT {' . $table . '}_' . $field . '_df');
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
        $this->connection->query('ALTER TABLE [{' . $table . '}] DROP CONSTRAINT [' . $primary_key_name . ']');
        $this->cleanUpTechnicalPrimaryColumn($table);
      }
      else {
        throw new DatabaseSchemaObjectExistsException(t("Cannot add primary key to table %table: primary key already exists.", array('%table' => $table)));
      }
    }

    $this->connection->query('ALTER TABLE [{' . $table . '}] ADD CONSTRAINT {' . $table . '_pkey} PRIMARY KEY (' . $this->createKeySql($fields) . ')');
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

    $this->connection->query('ALTER TABLE [{' . $table . '}] DROP CONSTRAINT {' . $table . '_pkey}');

    $this->createTechnicalPrimaryColumn($table);
    $this->connection->query('ALTER TABLE [{' . $table . '}] ADD CONSTRAINT {' . $table . '}_pkey_technical PRIMARY KEY CLUSTERED (__pk)');

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
   * Add a primary column to the table.
   */
  protected function createTechnicalPrimaryColumn($table) {
    if (!$this->fieldExists($table, '__pk')) {
      $this->connection->query('ALTER TABLE {' . $table . '} ADD __pk UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL');
    }
  }

  /**
   * Try to clean up the technical primary column if possible.
   */
  protected function cleanUpTechnicalPrimaryColumn($table) {
    // Get the number of remaining unique indexes on the table, and prune
    // the technical primary column if possible.
    $unique_indexes = $this->connection->query('SELECT COUNT(*) FROM sys.indexes WHERE object_id = OBJECT_ID(:table) AND is_unique = 1', array(':table' => $this->connection->prefixTables('{' . $table . '}')))->fetchField();
    if (!$unique_indexes && !$this->isTechnicalPrimaryKey($this->primaryKeyName($table))) {
      $this->dropField($table, '__pk');
    }
  }

  /**
   * Override DatabaseSchema::addUniqueKey().
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

    $this->createTechnicalPrimaryColumn($table);

    // Then, build a expression based on the columns.
    $column_expression = array();
    foreach ($fields as $field) {
      if (is_array($field)) {
        $column_expression[] = 'SUBSTRING(CAST(' . $field[0] . ' AS varbinary(max)),1,' . $field[1] . ')';
      }
      else {
        $column_expression[] = 'CAST(' . $field . ' AS varbinary(max))';
      }
    }
    $column_expression = implode(' + ', $column_expression);

    // Build a computed column based on the expression that replaces NULL
    // values with the globally unique identifier generated previously.
    // This is (very) unlikely to result in a collision with any actual value
    // in the columns of the unique key.
    $this->connection->query('ALTER TABLE {' . $table . '} ADD __unique_' . $name . " AS CAST(HashBytes('MD4', COALESCE(" . $column_expression . ", CAST(__pk AS varbinary(max)))) AS varbinary(16))");
    $this->connection->query('CREATE UNIQUE INDEX ' . $name . '_unique ON [{' . $table . '}] (__unique_' . $name . ')');
  }

  /**
   * Override DatabaseSchema::dropUniqueKey().
   *
   * @status tested
   */
  public function dropUniqueKey($table, $name) {
    if (!$this->uniqueKeyExists($table, $name)) {
      return FALSE;
    }

    $this->connection->query('DROP INDEX ' . $name . '_unique ON [{' . $table . '}]');
    $this->connection->query('ALTER TABLE [{' . $table . '}] DROP COLUMN __unique_' . $name);

    // Try to clean-up the technical primary key if possible.
    $this->cleanUpTechnicalPrimaryColumn($table);
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
  public function addIndex($table, $name, $fields) {
    if (!$this->tableExists($table)) {
      throw new DatabaseSchemaObjectDoesNotExistException(t("Cannot add index %name to table %table: table doesn't exist.", array('%table' => $table, '%name' => $name)));
    }
    if ($this->indexExists($table, $name)) {
      throw new DatabaseSchemaObjectExistsException(t("Cannot add index %name to table %table: index already exists.", array('%table' => $table, '%name' => $name)));
    }

    $this->connection->query($this->createIndexSql($table, $name, $fields));
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

    $this->connection->query('DROP INDEX ' . $name . '_idx ON [{' . $table . '}]');
    return TRUE;
  }

  /**
   * Override DatabaseSchema::indexExists().
   *
   * @status tested
   */
  public function indexExists($table, $name) {
    $table = $this->connection->prefixTables('{' . $table . '}');
    return (bool) $this->connection->query('SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(:table) AND name = :name', array(
      ':table' => $table,
      ':name' => $name . '_idx'
    ))->fetchField();
  }
}

/**
 * @} End of "addtogroup schemaapi".
 */
