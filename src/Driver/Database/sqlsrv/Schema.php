<?php

namespace Drupal\sqlsrv\Driver\Database\sqlsrv;

use Drupal\Core\Database\Schema as DatabaseSchema;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\SchemaObjectExistsException;

/**
 * @addtogroup schemaapi
 * @{
 */
class Schema extends DatabaseSchema {

  /**
   * The database connection.
   *
   * @var \Drupal\sqlsrv\Driver\Database\sqlsrv\Connection
   */
  protected $connection;

  /**
   * Default schema for SQL Server databases.
   *
   * @var string
   */
  protected $defaultSchema;

  /**
   * Maximum length of a comment in SQL Server.
   *
   * @var int
   */
  const COMMENT_MAX_BYTES = 7500;

  /**
   * Maximum length of a Primary Key.
   *
   * @var int
   */
  const PRIMARY_KEY_BYTES = 900;

  /**
   * Maximum length of a clustered index.
   *
   * @var int
   */
  const CLUSTERED_INDEX_BYTES = 900;

  /**
   * Maximum length of a non-clustered index.
   *
   * @var int
   */
  const NONCLUSTERED_INDEX_BYTES = 1700;

  /**
   * Maximum index length with XML field.
   *
   * @var int
   */
  const XML_INDEX_BYTES = 128;

  // Name for the technical column used for computed key sor technical primary
  // key.
  // IMPORTANT: They both start with "__" because the statement class will
  // remove those columns from the final result set.

  /**
   * Computed primary key name.
   *
   * @var string
   */
  const COMPUTED_PK_COLUMN_NAME = '__pkc';

  /**
   * Computed primary key index.
   *
   * @var string
   */
  const COMPUTED_PK_COLUMN_INDEX = '__ix_pkc';

  /**
   * Technical primary key name.
   *
   * @var string
   */
  const TECHNICAL_PK_COLUMN_NAME = '__pk';

  /**
   * Version information for the SQL Server engine.
   *
   * @var array
   */
  protected $engineVersion;

  /**
   * Should we cache table schema?
   *
   * @var bool
   */
  private $cacheSchema;

  /**
   * Table schema.
   *
   * @var mixed
   */
  private $columnInformation = [];

  /**
   * {@inheritdoc}
   */
  public function getFieldTypeMap() {
    // Put :normal last so it gets preserved by array_flip.  This makes
    // it much easier for modules (such as schema.module) to map
    // database types back into schema types.
    $utf8_string_types = [
      'varchar:normal' => 'varchar',
      'char:normal' => 'char',

      'text:tiny' => 'varchar(255)',
      'text:small' => 'varchar(255)',
      'text:medium' => 'varchar(max)',
      'text:big' => 'varchar(max)',
      'text:normal' => 'varchar(max)',
    ];

    $ucs2_string_types = [
      'varchar:normal' => 'nvarchar',
      'char:normal' => 'nchar',

      'text:tiny' => 'nvarchar(255)',
      'text:small' => 'nvarchar(255)',
      'text:medium' => 'nvarchar(max)',
      'text:big' => 'nvarchar(max)',
      'text:normal' => 'nvarchar(max)',
    ];

    $standard_types = [
      'varchar_ascii:normal' => 'varchar(255)',

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

      'date:normal'     => 'date',
      'datetime:normal' => 'datetime2(0)',
      'time:normal'     => 'time(0)',
    ];
    $standard_types += $this->isUtf8() ? $utf8_string_types : $ucs2_string_types;
    return $standard_types;
  }

  /**
   * {@inheritdoc}
   */
  public function renameTable($table, $new_name) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot rename %table to %table_new: table %table doesn't exist.", ['%table' => $table, '%table_new' => $new_name]));
    }
    if ($this->tableExists($new_name)) {
      throw new SchemaObjectExistsException(t("Cannot rename %table to %table_new: table %table_new already exists.", ['%table' => $table, '%table_new' => $new_name]));
    }

    $old_table_info = $this->getPrefixInfo($table);
    $new_table_info = $this->getPrefixInfo($new_name);

    // We don't support renaming tables across schemas (yet).
    if ($old_table_info['schema'] != $new_table_info['schema']) {
      throw new \PDOException(t('Cannot rename a table across schema.'));
    }

    $this->connection->queryDirect('EXEC sp_rename :old, :new', [
      ':old' => $old_table_info['schema'] . '.' . $old_table_info['table'],
      ':new' => $new_table_info['table'],
    ]);

    // Constraint names are global in SQL Server, so we need to rename them
    // when renaming the table. For some strange reason, indexes are local to
    // a table.
    $objects = $this->connection->queryDirect('SELECT name FROM sys.objects WHERE parent_object_id = OBJECT_ID(:table)', [':table' => $new_table_info['schema'] . '.' . $new_table_info['table']]);
    foreach ($objects as $object) {
      if (preg_match('/^' . preg_quote($old_table_info['table']) . '_(.*)$/', $object->name, $matches)) {
        $this->connection->queryDirect('EXEC sp_rename :old, :new, :type', [
          ':old' => $old_table_info['schema'] . '.' . $object->name,
          ':new' => $new_table_info['table'] . '_' . $matches[1],
          ':type' => 'OBJECT',
        ]);
      }
    }
    $this->resetColumnInformation($table);
  }

  /**
   * {@inheritdoc}
   */
  public function dropTable($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    $this->connection->queryDirect('DROP TABLE {' . $table . '}');
    $this->resetColumnInformation($table);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function fieldExists($table, $field) {
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    return $this->connection
      ->queryDirect('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE table_name = :table AND column_name = :name', [
        ':table' => $prefixInfo['table'],
        ':name' => $field,
      ])
      ->fetchField() !== FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function addField($table, $field, $spec, $keys_new = []) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add field @table.@field: table doesn't exist.", ['@field' => $field, '@table' => $table]));
    }
    if ($this->fieldExists($table, $field)) {
      throw new SchemaObjectExistsException(t("Cannot add field @table.@field: field already exists.", ['@field' => $field, '@table' => $table]));
    }

    // Fields that are part of a PRIMARY KEY must be added as NOT NULL.
    $is_primary_key = isset($keys_new['primary key']) && in_array($field, $keys_new['primary key'], TRUE);
    if ($is_primary_key) {
      $this->ensureNotNullPrimaryKey($keys_new['primary key'], [$field => $spec]);
    }

    $transaction = $this->connection->startTransaction();

    // Prepare the specifications.
    $spec = $this->processField($spec);

    // Use already prefixed table name.
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    $table_prefixed = $prefixInfo['table'];

    if ($this->findPrimaryKeyColumns($table) !== [] && isset($keys_new['primary key']) && in_array($field, $keys_new['primary key'])) {
      $this->cleanUpPrimaryKey($table);
    }
    // If the field is declared NOT NULL, we have to first create it NULL insert
    // the initial data (or populate default values) and then switch to NOT
    // NULL.
    $fixnull = FALSE;
    if (!empty($spec['not null'])) {
      $fixnull = TRUE;
      $spec['not null'] = FALSE;
    }

    // Create the field.
    // Because the default values of fields can contain string literals
    // with braces, we CANNOT allow the driver to prefix tables because the
    // algorithm to do so is a crappy str_replace.
    $query = "ALTER TABLE {{$table}} ADD ";
    $query .= $this->createFieldSql($table, $field, $spec);
    $this->connection->queryDirect($query, []);
    $this->resetColumnInformation($table);
    // Load the initial data.
    if (isset($spec['initial_from_field'])) {
      if (isset($spec['initial'])) {
        $expression = 'COALESCE(' . $spec['initial_from_field'] . ', :default_initial_value)';
        $arguments = [':default_initial_value' => $spec['initial']];
      }
      else {
        $expression = $spec['initial_from_field'];
        $arguments = [];
      }
      $this->connection->update($table)
        ->expression($field, $expression, $arguments)
        ->execute();
    }
    elseif (isset($spec['initial'])) {
      $this->connection->update($table)
        ->fields([$field => $spec['initial']])
        ->execute();
    }

    // Switch to NOT NULL now.
    if ($fixnull === TRUE) {
      // There is no warranty that the old data did not have NULL values, we
      // need to populate nulls with the default value because this won't be
      // done by MSSQL by default.
      if (isset($spec['default'])) {
        $default_expression = $this->defaultValueExpression($spec['sqlsrv_type'], $spec['default']);
        $sql = "UPDATE {{$table}} SET {$field}={$default_expression} WHERE {$field} IS NULL";
        $this->connection->queryDirect($sql);
      }

      // Now it's time to make this non-nullable.
      $spec['not null'] = TRUE;
      $field_sql = $this->createFieldSql($table, $field, $spec, TRUE);
      $this->connection->queryDirect("ALTER TABLE {{$table}} ALTER COLUMN {$field_sql}");
      $this->resetColumnInformation($table);
    }

    $this->recreateTableKeys($table, $keys_new);

    if (isset($spec['description'])) {
      $this->connection->queryDirect($this->createCommentSql($spec['description'], $table, $field));
    }
  }

  /**
   * {@inheritdoc}
   *
   * Should this be in a Transaction?
   */
  public function dropField($table, $field) {
    if (!$this->fieldExists($table, $field)) {
      return FALSE;
    }
    $primary_key_fields = $this->findPrimaryKeyColumns($table);

    if (in_array($field, $primary_key_fields)) {
      // Let's drop the PK.
      $this->cleanUpPrimaryKey($table);
      $this->createTechnicalPrimaryColumn($table);
    }

    // Drop the related objects.
    $this->dropFieldRelatedObjects($table, $field);

    // Drop field comments.
    if ($this->getComment($table, $field) !== FALSE) {
      $this->connection->queryDirect($this->deleteCommentSql($table, $field));
    }

    $this->connection->query('ALTER TABLE {' . $table . '} DROP COLUMN ' . $field);
    $this->resetColumnInformation($table);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function indexExists($table, $name) {
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    return (bool) $this->connection->query('SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(:table) AND name = :name', [
      ':table' => $prefixInfo['table'],
      ':name' => $name . '_idx',
    ])->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function addPrimaryKey($table, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add primary key to table %table: table doesn't exist.", ['%table' => $table]));
    }

    if ($primary_key_name = $this->primaryKeyName($table)) {
      if ($this->isTechnicalPrimaryKey($primary_key_name)) {
        // Destroy the existing technical primary key.
        $this->connection->queryDirect('ALTER TABLE {' . $table . '} DROP CONSTRAINT [' . $primary_key_name . ']');
        $this->resetColumnInformation($table);
        $this->cleanUpTechnicalPrimaryColumn($table);
      }
      else {
        throw new SchemaObjectExistsException(t("Cannot add primary key to table %table: primary key already exists.", ['%table' => $table]));
      }
    }

    // The size limit of the primary key depends on the
    // coexistence with an XML field.
    if ($this->tableHasXmlIndex($table)) {
      $this->createPrimaryKey($table, $fields, self::XML_INDEX_BYTES);
    }
    else {
      $this->createPrimaryKey($table, $fields);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function dropPrimaryKey($table) {
    if (!$this->primaryKeyName($table)) {
      return FALSE;
    }
    $this->cleanUpPrimaryKey($table);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function findPrimaryKeyColumns($table) {
    if (!$this->tableExists($table)) {
      return FALSE;
    }
    // Use already prefixed table name.
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    $query = "SELECT column_name FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS AS TC "
      . "INNER JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE AS KU "
      . "ON TC.CONSTRAINT_TYPE = 'PRIMARY KEY' AND "
      . "TC.CONSTRAINT_NAME = KU.CONSTRAINT_NAME AND "
      . "KU.table_name=:table AND column_name != '__pk' AND column_name != '__pkc' "
      . "ORDER BY KU.ORDINAL_POSITION";
    $result = $this->connection->query($query, [':table' => $prefixInfo['table']])->fetchAllAssoc('column_name');
    return array_keys($result);
  }

  /**
   * {@inheritdoc}
   */
  public function addUniqueKey($table, $name, $fields) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add unique key %name to table %table: table doesn't exist.", ['%table' => $table, '%name' => $name]));
    }
    if ($this->uniqueKeyExists($table, $name)) {
      throw new SchemaObjectExistsException(t("Cannot add unique key %name to table %table: unique key already exists.", ['%table' => $table, '%name' => $name]));
    }

    $this->createTechnicalPrimaryColumn($table);

    // Then, build a expression based on the columns.
    $column_expression = [];
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
    $this->connection->query("ALTER TABLE {{$table}} ADD __unique_{$name} AS CAST(HashBytes('MD4', COALESCE({$column_expression}, CAST(" . self::TECHNICAL_PK_COLUMN_NAME . " AS varbinary(max)))) AS varbinary(16))");
    $this->connection->query("CREATE UNIQUE INDEX {$name}_unique ON {{$table}} (__unique_{$name})");
    $this->resetColumnInformation($table);
  }

  /**
   * {@inheritdoc}
   */
  public function dropUniqueKey($table, $name) {
    if (!$this->uniqueKeyExists($table, $name)) {
      return FALSE;
    }

    $this->connection->query("DROP INDEX {$name}_unique ON {{$table}}");
    $this->connection->query("ALTER TABLE {{$table}} DROP COLUMN __unique_{$name}");
    $this->resetColumnInformation($table);
    // Try to clean-up the technical primary key if possible.
    $this->cleanUpTechnicalPrimaryColumn($table);

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function addIndex($table, $name, $fields, array $spec = []) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot add index %name to table %table: table doesn't exist.", ['%table' => $table, '%name' => $name]));
    }
    if ($this->indexExists($table, $name)) {
      throw new SchemaObjectExistsException(t("Cannot add index %name to table %table: index already exists.", ['%table' => $table, '%name' => $name]));
    }
    $xml_field = NULL;
    foreach ($fields as $field) {
      if (isset($info['columns'][$field]['type']) && $info['columns'][$field]['type'] == 'xml') {
        $xml_field = $field;
        break;
      }
    }
    $sql = $this->createIndexSql($table, $name, $fields, $xml_field);
    $pk_fields = $this->introspectPrimaryKeyFields($table);
    $size = $this->calculateClusteredIndexRowSizeBytes($table, $pk_fields, TRUE);
    if (!empty($xml_field)) {
      // We can create an XML field, but the current primary key index
      // size needs to be under 128bytes.
      if ($size > self::XML_INDEX_BYTES) {
        // Alright the compress the index.
        $this->compressPrimaryKeyIndex($table, self::XML_INDEX_BYTES);
      }
      $this->connection->query($sql);
      $this->resetColumnInformation($table);
    }
    elseif ($size <= self::NONCLUSTERED_INDEX_BYTES) {
      $this->connection->query($sql);
      $this->resetColumnInformation($table);
    }
    // If the field is too large, do not create an index.
  }

  /**
   * {@inheritdoc}
   */
  public function dropIndex($table, $name) {
    if (!$this->indexExists($table, $name)) {
      return FALSE;
    }

    $expand = FALSE;
    if (($index = $this->tableHasXmlIndex($table)) && $index == ($name . '_idx')) {
      $expand = TRUE;
    }

    $this->connection->query('DROP INDEX ' . $name . '_idx ON {' . $table . '}');
    $this->resetColumnInformation($table);
    // If we just dropped an XML index, we can re-expand the original primary
    // key index.
    if ($expand) {
      $this->compressPrimaryKeyIndex($table);
    }

    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function introspectIndexSchema($table) {
    if (!$this->tableExists($table)) {
      throw new SchemaObjectDoesNotExistException("The table $table doesn't exist.");
    }
    $index_schema = [
      'primary key' => $this->findPrimaryKeyColumns($table),
      'unique keys' => [],
      'indexes' => [],
    ];
    $column_information = $this->queryColumnInformation($table);
    foreach ($column_information['indexes'] as $key => $values) {
      if ($values['is_primary_key'] !== 1 && $values['data_space_id'] == 1 && $values['is_unique'] == 0) {
        foreach ($values['columns'] as $num => $stats) {
          $index_schema['indexes'][substr($key, 0, -4)][] = $stats['name'];
        }
      }
    }
    foreach ($column_information['columns'] as $name => $spec) {
      if (substr($name, 0, 9) == '__unique_' && $column_information['indexes'][substr($name, 9) . '_unique']['is_unique'] == 1) {
        $definition = $spec['definition'];
        $matches = [];
        preg_match_all("/CONVERT\(\[varbinary\]\(max\),\[([a-zA-Z0-9_]*)\]/", $definition, $matches);
        foreach ($matches[1] as $match) {
          if ($match != '__pk') {
            $index_schema['unique keys'][substr($name, 9)][] = $match;
          }
        }
      }
    }
    return $index_schema;
  }

  /**
   * {@inheritdoc}
   */
  public function changeField($table, $field, $field_new, $spec, $keys_new = []) {
    if (!$this->fieldExists($table, $field)) {
      throw new SchemaObjectDoesNotExistException(t("Cannot change the definition of field %table.%name: field doesn't exist.", [
        '%table' => $table,
        '%name' => $field,
      ]));
    }
    if (($field != $field_new) && $this->fieldExists($table, $field_new)) {
      throw new SchemaObjectExistsException(t("Cannot rename field %table.%name to %name_new: target field already exists.", [
        '%table' => $table,
        '%name' => $field,
        '%name_new' => $field_new,
      ]));
    }
    if (isset($keys_new['primary key']) && in_array($field_new, $keys_new['primary key'], TRUE)) {
      $this->ensureNotNullPrimaryKey($keys_new['primary key'], [$field_new => $spec]);
    }
    // Check if we need to drop field comments.
    $drop_field_comment = FALSE;
    if ($this->getComment($table, $field) !== FALSE) {
      $drop_field_comment = TRUE;
    }

    // SQL Server supports transactional DDL, so we can just start a transaction
    // here and pray for the best.
    $transaction = $this->connection->startTransaction();

    // Prepare the specifications.
    $spec = $this->processField($spec);

    /*
     * IMPORTANT NOTE: To maintain database portability, you have to explicitly
     * recreate all indices and primary keys that are using the changed field.
     * That means that you ohave to drop all affected keys and indexes with
     * db_drop_{primary_key,unique_key,index}() before calling
     * db_change_field().
     *
     * @see https://api.drupal.org/api/drupal/includes!database!database.inc/function/db_change_field/7
     *
     * What we are going to do in the SQL Server Driver is a best-effort try to
     * preserve original keys if they do not conflict with the keys_new
     * parameter, and if the callee has done it's job (droping constraints/keys)
     * then they will of course not be recreated.
     *
     * Introspect the schema and save the current primary key if the column
     * we are modifying is part of it. Make sure the schema is FRESH.
     */
    $primary_key_fields = $this->findPrimaryKeyColumns($table);

    if (in_array($field, $primary_key_fields)) {
      // Let's drop the PK.
      $this->cleanUpPrimaryKey($table);
    }

    // If there is a generated unique key for this field, we will need to
    // add it back in when we are done.
    $unique_key = $this->uniqueKeyExists($table, $field);

    // Drop the related objects.
    $this->dropFieldRelatedObjects($table, $field);

    if ($drop_field_comment) {
      $this->connection->queryDirect($this->deleteCommentSql($table, $field));
    }
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    // Start by renaming the current column.
    $this->connection->queryDirect('EXEC sp_rename :old, :new, :type', [
      ':old' => $prefixInfo['table'] . '.' . $field,
      ':new' => $field . '_old',
      ':type' => 'COLUMN',
    ]);
    $this->resetColumnInformation($table);

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

    // Don't need to do this if there is no data
    // Cannot do this it column is serial.
    if ($spec['type'] != 'serial') {
      $new_data_type = $this->createDataType($table, $field_new, $spec);
      // Migrate the data over.
      // Explicitly cast the old value to the new value to avoid conversion
      // errors.
      $sql = "UPDATE {{$table}} SET {$field_new}=CAST({$field}_old AS {$new_data_type})";
      $this->connection->queryDirect($sql);
      $this->resetColumnInformation($table);
    }

    // Switch to NOT NULL now.
    if ($fixnull === TRUE) {
      // There is no warranty that the old data did not have NULL values, we
      // need to populate nulls with the default value because this won't be
      // done by MSSQL by default.
      if (!empty($spec['default'])) {
        $default_expression = $this->defaultValueExpression($spec['sqlsrv_type'], $spec['default']);
        $sql = "UPDATE {{$table}} SET {$field_new} = {$default_expression} WHERE {$field_new} IS NULL";
        $this->connection->queryDirect($sql);
        $this->resetColumnInformation($table);
      }
      // Now it's time to make this non-nullable.
      $spec['not null'] = TRUE;
      $field_sql = $this->createFieldSql($table, $field_new, $spec, TRUE);
      $sql = "ALTER TABLE {{$table}} ALTER COLUMN {$field_sql}";
      $this->connection->queryDirect($sql);
      $this->resetColumnInformation($table);
    }
    // Recreate the primary key if no new primary key has been sent along with
    // the change field.
    if (in_array($field, $primary_key_fields) && (!isset($keys_new['primary keys']) || empty($keys_new['primary keys']))) {
      // The new primary key needs to have the new column name, and be in the
      // same order.
      if ($field !== $field_new) {
        $primary_key_fields[array_search($field, $primary_key_fields)] = $field_new;
      }
      $keys_new['primary key'] = $primary_key_fields;
    }

    // Recreate the unique constraint if it existed.
    if ($unique_key && (!isset($keys_new['unique keys']) || !in_array($field_new, $keys_new['unique keys']))) {
      $keys_new['unique keys'][$field] = [$field_new];
    }

    // Drop the old field.
    $this->dropField($table, $field . '_old');

    // Add the new keys.
    $this->recreateTableKeys($table, $keys_new);
  }

  /**
   * {@inheritdoc}
   *
   * Adding abilty to pass schema in configuration.
   */
  public function __construct($connection) {
    parent::__construct($connection);
    $options = $connection->getConnectionOptions();
    if (isset($options['schema'])) {
      $this->defaultSchema = $options['schema'];
    }
    $this->cacheSchema = $options['cache_schema'] ?? FALSE;
  }

  /**
   * {@inheritdoc}
   *
   * Temporary tables and regular tables cannot be verified in the same way.
   */
  public function tableExists($table) {
    if (empty($table)) {
      return FALSE;
    }
    // Temporary tables and regular tables cannot be verified in the same way.
    $query = NULL;
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    $args = [];
    if ($this->connection->isTemporaryTable($table)) {
      $query = "SELECT 1 FROM tempdb.sys.tables WHERE [object_id] = OBJECT_ID(:table)";
      $args = [':table' => 'tempdb.[' . $this->getDefaultSchema() . '].[' . $prefixInfo['table'] . ']'];
    }
    else {
      $query = "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE [table_name] = :table";
      $args = [':table' => $prefixInfo['table']];
    }

    return (bool) $this->connection->queryDirect($query, $args)->fetchField();
  }

  /**
   * Drupal specific functions.
   *
   * Returns a list of functions that are not available by default on SQL
   * Server, but used in Drupal Core or contributed modules because they are
   * available in other databases such as MySQL.
   *
   * @return array
   *   List of functions.
   */
  public function drupalSpecificFunctions() {
    $functions = [
      'SUBSTRING',
      'SUBSTRING_INDEX',
      'GREATEST',
      'MD5',
      'LPAD',
      'REGEXP',
      'IF',
      'CONNECTION_ID',
    ];
    return $functions;
  }

  /**
   * Return active default Schema.
   */
  public function getDefaultSchema() {
    if (!isset($this->defaultSchema)) {
      $result = $this->connection->queryDirect("SELECT SCHEMA_NAME()")->fetchField();
      $this->defaultSchema = $result;
    }
    return $this->defaultSchema;
  }

  /**
   * Database introspection: fetch technical information about a table.
   *
   * @return array
   *   An array with the following structure:
   *   - blobs[]: Array of column names that should be treated as blobs in this
   *     table.
   *   - identities[]: Array of column names that are identities in this table.
   *   - identity: The name of the identity column
   *   - columns[]: An array of specification details for the columns
   *      - name: Column name.
   *      - max_length: Maximum length.
   *      - precision: Precision.
   *      - collation_name: Collation.
   *      - is_nullable: Is nullable.
   *      - is_ansi_padded: Is ANSI padded.
   *      - is_identity: Is identity.
   *      - definition: If a computed column, the computation formulae.
   *      - default_value: Default value for the column (if any).
   */
  public function queryColumnInformation($table) {

    if (empty($table) || !$this->tableExists($table)) {
      return [];
    }

    if ($this->cacheSchema && isset($this->columnInformation[$table])) {
      return $this->columnInformation[$table];
    }

    $table_info = $this->getPrefixInfo($table);

    // We could adapt the current code to support temporary table introspection,
    // but for now this is not supported.
    if ($this->connection->isTemporaryTable($table)) {
      throw new \Exception('Temporary table introspection is not supported.');
    }

    $info = [];

    // Don't use {} around information_schema.columns table.
    $sql = "SELECT sysc.name, sysc.max_length, sysc.precision, sysc.collation_name,
      sysc.is_nullable, sysc.is_ansi_padded, sysc.is_identity, sysc.is_computed, TYPE_NAME(sysc.user_type_id) as type,
      syscc.definition, sm.[text] as default_value
      FROM sys.columns AS sysc
      INNER JOIN sys.syscolumns AS sysc2 ON sysc.object_id = sysc2.id and sysc.name = sysc2.name
      LEFT JOIN sys.computed_columns AS syscc ON sysc.object_id = syscc.object_id AND sysc.name = syscc.name
      LEFT JOIN sys.syscomments sm ON sm.id = sysc2.cdefault
      WHERE sysc.object_id = OBJECT_ID(:table)";
    $args = [':table' => $table_info['schema'] . '.' . $table_info['table']];
    $result = $this->connection->queryDirect($sql, $args);

    foreach ($result as $column) {
      if ($column->type == 'varbinary') {
        $info['blobs'][$column->name] = TRUE;
      }
      $info['columns'][$column->name] = (array) $column;
      // Provide a clean list of columns that excludes the ones internally
      // created by the database driver.
      if (!(isset($column->name[1]) && substr($column->name, 0, 2) == "__")) {
        $info['columns_clean'][$column->name] = (array) $column;
      }
    }

    // If we have computed columns, it is important to know what other columns
    // they depend on!
    $column_names = array_keys($info['columns']);
    $column_regex = implode('|', $column_names);
    foreach ($info['columns'] as &$column) {
      $dependencies = [];
      if (!empty($column['definition'])) {
        $matches = [];
        if (preg_match_all("/\[[{$column_regex}\]]*\]/", $column['definition'], $matches) > 0) {
          $dependencies = array_map(function ($m) {
            return trim($m, "[]");
          }, array_shift($matches));
        }
      }
      $column['dependencies'] = array_flip($dependencies);
    }

    // Don't use {} around system tables.
    $result = $this->connection->queryDirect('SELECT name FROM sys.identity_columns WHERE object_id = OBJECT_ID(:table)', [':table' => $table_info['schema'] . '.' . $table_info['table']]);
    unset($column);
    $info['identities'] = [];
    $info['identity'] = NULL;
    foreach ($result as $column) {
      $info['identities'][$column->name] = $column->name;
      $info['identity'] = $column->name;
    }

    // Now introspect information about indexes.
    $result = $this->connection->queryDirect("select tab.[name]  as [table_name],
         idx.[name]  as [index_name],
         allc.[name] as [column_name],
         idx.[type_desc],
         idx.[is_unique],
         idx.[data_space_id],
         idx.[ignore_dup_key],
         idx.[is_primary_key],
         idx.[is_unique_constraint],
         idx.[fill_factor],
         idx.[is_padded],
         idx.[is_disabled],
         idx.[is_hypothetical],
         idx.[allow_row_locks],
         idx.[allow_page_locks],
         idxc.[is_descending_key],
         idxc.[is_included_column],
         idxc.[index_column_id],
         idxc.[key_ordinal]
    FROM sys.[tables] as tab
    INNER join sys.[indexes]       idx  ON tab.[object_id] =  idx.[object_id]
    INNER join sys.[index_columns] idxc ON idx.[object_id] = idxc.[object_id] and  idx.[index_id]  = idxc.[index_id]
    INNER join sys.[all_columns]   allc ON tab.[object_id] = allc.[object_id] and idxc.[column_id] = allc.[column_id]
    WHERE tab.object_id = OBJECT_ID(:table)
    ORDER BY tab.[name], idx.[index_id], idxc.[index_column_id]
                    ",
                  [':table' => $table_info['schema'] . '.' . $table_info['table']]);

    foreach ($result as $index_column) {
      if (!isset($info['indexes'][$index_column->index_name])) {
        $ic = clone $index_column;
        // Only retain index specific details.
        unset($ic->column_name);
        unset($ic->index_column_id);
        unset($ic->is_descending_key);
        unset($ic->table_name);
        unset($ic->key_ordinal);
        $info['indexes'][$index_column->index_name] = (array) $ic;
        if ($index_column->is_primary_key) {
          $info['primary_key_index'] = $ic->index_name;
        }
      }
      $index = &$info['indexes'][$index_column->index_name];
      $index['columns'][$index_column->key_ordinal] = [
        'name' => $index_column->column_name,
        'is_descending_key' => $index_column->is_descending_key,
        'key_ordinal' => $index_column->key_ordinal,
      ];
      // Every columns keeps track of what indexes it is part of.
      $info['columns'][$index_column->column_name]['indexes'][] = $index_column->index_name;
      if (isset($info['columns_clean'][$index_column->column_name])) {
        $info['columns_clean'][$index_column->column_name]['indexes'][] = $index_column->index_name;
      }
    }
    if ($this->cacheSchema) {
      $this->columnInformation[$table] = $info;
    }

    return $info;
  }

  /**
   * Unset cached table schema.
   */
  public function resetColumnInformation($table) {
    unset($this->columnInformation[$table]);
  }

  /**
   * {@inheritdoc}
   */
  public function createTable($name, $table) {

    // Build the table and its unique keys in a transaction, and fail the whole
    // creation in case of an error.
    $transaction = $this->connection->startTransaction();

    parent::createTable($name, $table);

    // If the spec had a primary key, set it now after all fields have been
    // created. We are creating the keys after creating the table so that
    // createPrimaryKey is able to introspect column definition from the
    // database to calculate index sizes. This adds quite quite some overhead,
    // but is only noticeable during table creation.
    if (!empty($table['primary key']) && is_array($table['primary key'])) {
      $this->ensureNotNullPrimaryKey($table['primary key'], $table['fields']);
      $this->createPrimaryKey($name, $table['primary key']);
    }
    // Now all the unique keys.
    if (isset($table['unique keys']) && is_array($table['unique keys'])) {
      foreach ($table['unique keys'] as $key_name => $key) {
        $this->addUniqueKey($name, $key_name, $key);
      }
    }

    unset($transaction);

    // Create the indexes but ignore any error during the creation. We do that
    // do avoid pulling the carpet under modules that try to implement indexes
    // with invalid data types (long columns), before we come up with a better
    // solution.
    if (isset($table['indexes']) && is_array($table['indexes'])) {
      foreach ($table['indexes'] as $key_name => $key) {
        try {
          $this->addIndex($name, $key_name, $key);
        }
        catch (\Exception $e) {
          // Log the exception but do not rollback the transaction.
          if ($this->tableExists('watchdog')) {
            watchdog_exception('database', $e);
          }
        }
      }
    }
  }

  /**
   * Remove comments from an SQL statement.
   *
   * @param mixed $sql
   *   SQL statement to remove the comments from.
   * @param mixed $comments
   *   Comments removed from the statement.
   *
   * @return string
   *   SQL statement without comments.
   *
   * @see http://stackoverflow.com/questions/9690448/regular-expression-to-remove-comments-from-sql-statement
   */
  public function removeSqlComments($sql, &$comments = NULL) {
    $sqlComments = '@(([\'"]).*?[^\\\]\2)|((?:\#|--).*?$|/\*(?:[^/*]|/(?!\*)|\*(?!/)|(?R))*\*\/)\s*|(?<=;)\s+@ms';
    /* Commented version
    $sqlComments = '@
    (([\'"]).*?[^\\\]\2) # $1 : Skip single & double quoted expressions
    |(                   # $3 : Match comments
    (?:\#|--).*?$    # - Single line comments
    |                # - Multi line (nested) comments
    /\*             #   . comment open marker
    (?: [^/*]    #   . non comment-marker characters
    |/(?!\*) #   . ! not a comment open
    |\*(?!/) #   . ! not a comment close
    |(?R)    #   . recursive case
    )*           #   . repeat eventually
    \*\/             #   . comment close marker
    )\s*                 # Trim after comments
    |(?<=;)\s+           # Trim after semi-colon
    @msx';
     */
    $uncommentedSQL = trim(preg_replace($sqlComments, '$1', $sql));
    if (is_array($comments)) {
      preg_match_all($sqlComments, $sql, $comments);
      $comments = array_filter($comments[3]);
    }
    return $uncommentedSQL;
  }

  /**
   * Returns an array of current connection user options.
   *
   * Textsize    2147483647
   * language    us_english
   * dateformat    mdy
   * datefirst    7
   * lock_timeout    -1
   * quoted_identifier    SET
   * arithabort    SET
   * ansi_null_dflt_on    SET
   * ansi_warnings    SET
   * ansi_padding    SET
   * ansi_nulls    SET
   * concat_null_yields_null    SET
   * isolation level    read committed.
   *
   * @return mixed
   *   User options.
   */
  public function userOptions() {
    $result = $this->connection->queryDirect('DBCC UserOptions')->fetchAllKeyed();
    return $result;
  }

  /**
   * Retrieve Engine Version information.
   *
   * @return array
   *   Engine version.
   */
  public function engineVersion() {
    if (!isset($this->engineVersion)) {
      $this->engineVersion = $this->connection
        ->queryDirect(<<< EOF
          SELECT CONVERT (varchar,SERVERPROPERTY('productversion')) AS VERSION,
          CONVERT (varchar,SERVERPROPERTY('productlevel')) AS LEVEL,
          CONVERT (varchar,SERVERPROPERTY('edition')) AS EDITION
EOF
        )->fetchAssoc();
    }
    return $this->engineVersion;
  }

  /**
   * Retrieve Major Engine Version Number as integer.
   *
   * @return int
   *   Engine Version Number.
   */
  public function engineVersionNumber() {
    $version = $this->EngineVersion();
    $start = strpos($version['VERSION'], '.');
    return intval(substr($version['VERSION'], 0, $start));
  }

  /**
   * Find if a table function exists.
   *
   * @param string $function
   *   Name of the function.
   *
   * @return bool
   *   True if the function exists, false otherwise.
   */
  public function functionExists($function) {
    // FN = Scalar Function
    // IF = Inline Table Function
    // TF = Table Function
    // FS | AF = Assembly (CLR) Scalar Function
    // FT | AT = Assembly (CLR) Table Valued Function.
    return $this->connection
      ->queryDirect("SELECT 1 FROM sys.objects WHERE object_id = OBJECT_ID('" . $function . "') AND type in (N'FN', N'IF', N'TF', N'FS', N'FT', N'AF')")
      ->fetchField() !== FALSE;
  }

  /**
   * Check if CLR is enabled.
   *
   * Required to run GROUP_CONCAT.
   *
   * @return bool
   *   Is CLR enabled?
   */
  public function clrEnabled() {
    return $this->connection
      ->queryDirect("SELECT CONVERT(int, [value]) as [enabled] FROM sys.configurations WHERE name = 'clr enabled'")
      ->fetchField() !== 1;
  }

  /**
   * Check if a column is of variable length.
   */
  private function isVariableLengthType($type) {
    $types = [
      'nvarchar' => TRUE,
      'ntext' => TRUE,
      'varchar' => TRUE,
      'varbinary' => TRUE,
      'image' => TRUE,
    ];
    return isset($types[$type]);
  }

  /**
   * Load field spec.
   *
   * Retrieve an array of field specs from
   * an array of field names.
   *
   * @param array $fields
   *   Table fields.
   * @param mixed $table
   *   Table name.
   */
  private function loadFieldsSpec(array $fields, $table) {
    $result = [];
    $info = $this->queryColumnInformation($table);
    foreach ($fields as $field) {
      $result[$field] = $info['columns'][$field];
    }
    return $result;
  }

  /**
   * Estimates the row size of a clustered index.
   *
   * @see https://msdn.microsoft.com/en-us/library/ms178085.aspx
   */
  public function calculateClusteredIndexRowSizeBytes($table, $fields, $unique = TRUE) {
    // The fields must already be in the database to retrieve their real size.
    $info = $this->queryColumnInformation($table);

    // Specify the number of fixed-length and variable-length columns
    // and calculate the space that is required for their storage.
    $num_cols = count($fields);
    $num_variable_cols = 0;
    $max_var_size = 0;
    $max_fixed_size = 0;
    foreach ($fields as $field) {
      if ($this->isVariableLengthType($info['columns'][$field]['type'])) {
        $num_variable_cols++;
        $max_var_size += $info['columns'][$field]['max_length'];
      }
      else {
        $max_fixed_size += $info['columns'][$field]['max_length'];
      }
    }

    // If the clustered index is nonunique, account for the uniqueifier column.
    if (!$unique) {
      $num_cols++;
      $num_variable_cols++;
      $max_var_size += 4;
    }

    // Part of the row, known as the null bitmap, is reserved to manage column
    // nullability. Calculate its size.
    $null_bitmap = 2 + (($num_cols + 7) / 8);

    // Calculate the variable-length data size.
    $variable_data_size = empty($num_variable_cols) ? 0 : 2 + ($num_variable_cols * 2) + $max_var_size;

    // Calculate total row size.
    $row_size = $max_fixed_size + $variable_data_size + $null_bitmap + 4;

    return $row_size;
  }

  /**
   * Recreate primary key.
   *
   * Drops the current primary key and creates
   * a new one. If the previous primary key
   * was an internal primary key, it tries to cleant it up.
   *
   * @param string $table
   *   Table name.
   * @param mixed $fields
   *   Array of fields.
   */
  protected function recreatePrimaryKey($table, $fields) {
    // Drop the existing primary key if exists, if it was a TPK
    // it will get completely dropped.
    $this->cleanUpPrimaryKey($table);
    $this->createPrimaryKey($table, $fields);
  }

  /**
   * Create primary key.
   *
   * Create a Primary Key for the table, does not drop
   * any prior primary keys neither it takes care of cleaning
   * technical primary column. Only call this if you are sure
   * the table does not currently hold a primary key.
   *
   * @param string $table
   *   Table name.
   * @param mixed $fields
   *   Array of fields.
   * @param int $limit
   *   Size limit.
   */
  private function createPrimaryKey($table, $fields, $limit = 900) {
    // To be on the safe side, on the most restrictive use case the limit
    // for a primary key clustered index is of 128 bytes (usually 900).
    // @see https://web.archive.org/web/20140510074940/http://blogs.msdn.com/b/jgalla/archive/2005/08/18/453189.aspx
    // If that is going to be exceeded, use a computed column.
    $csv_fields = $this->createKeySql($fields);
    $size = $this->calculateClusteredIndexRowSizeBytes($table, $this->createKeySql($fields, TRUE));
    $result = [];
    $index = FALSE;
    // Add support for nullable columns in a primary key.
    $nullable = FALSE;
    $field_specs = $this->loadFieldsSpec($fields, $table);
    foreach ($field_specs as $field) {
      if ($field['is_nullable'] == TRUE) {
        $nullable = TRUE;
        break;
      }
    }

    if ($nullable || $size >= $limit) {
      // Use a computed column instead, and create a custom index.
      $result[] = self::COMPUTED_PK_COLUMN_NAME . " AS (CONVERT(VARCHAR(32), HASHBYTES('MD5', CONCAT('',{$csv_fields})), 2)) PERSISTED NOT NULL";
      $result[] = "CONSTRAINT {{$table}_pkey} PRIMARY KEY CLUSTERED (" . self::COMPUTED_PK_COLUMN_NAME . ")";
      $index = TRUE;
    }
    else {
      $result[] = "CONSTRAINT {{$table}_pkey} PRIMARY KEY CLUSTERED ({$csv_fields})";
    }

    $this->connection->queryDirect('ALTER TABLE {' . $table . '} ADD ' . implode(' ', $result));
    $this->resetColumnInformation($table);
    // If we relied on a computed column for the Primary Key,
    // at least index the fields with a regular index.
    if ($index) {
      $this->addIndex($table, self::COMPUTED_PK_COLUMN_INDEX, $fields);
    }
  }

  /**
   * Create technical primary key index SQL.
   *
   * Create the SQL needed to add a new technical primary key based on a
   * computed column.
   *
   * @param string $table
   *   Table name.
   *
   * @return string
   *   SQL string.
   */
  private function createTechnicalPrimaryKeyIndexSql($table) {
    $result = [];
    $result[] = self::TECHNICAL_PK_COLUMN_NAME . " UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL";
    $result[] = "CONSTRAINT {{$table}_pkey_technical} PRIMARY KEY CLUSTERED (" . self::TECHNICAL_PK_COLUMN_NAME . ")";
    return implode(' ', $result);
  }

  /**
   * Generate SQL to create a new table from a Drupal schema definition.
   *
   * @param string $name
   *   The name of the table to create.
   * @param array $table
   *   A Schema API table definition array.
   *
   * @return array
   *   A collection of SQL statements to create the table.
   */
  protected function createTableSql($name, array $table) {
    $statements = [];
    $sql_fields = [];
    foreach ($table['fields'] as $field_name => $field) {
      $sql_fields[] = $this->createFieldSql($name, $field_name, $this->processField($field));
      if (isset($field['description'])) {
        $statements[] = $this->createCommentSQL($field['description'], $name, $field_name);
      }
    }

    $sql = "CREATE TABLE {{$name}} (" . PHP_EOL;
    $sql .= implode("," . PHP_EOL, $sql_fields);
    $sql .= PHP_EOL . ")";
    array_unshift($statements, $sql);
    if (!empty($table['description'])) {
      $statements[] = $this->createCommentSql($table['description'], $name);
    }
    return $statements;
  }

  /**
   * Create Field SQL.
   *
   * Create an SQL string for a field to be used in table creation or
   * alteration.
   *
   * Before passing a field out of a schema definition into this
   * function it has to be processed by _db_process_field().
   *
   * @param string $table
   *   The name of the table.
   * @param string $name
   *   Name of the field.
   * @param mixed $spec
   *   The field specification, as per the schema data structure format.
   * @param bool $skip_checks
   *   Skip checks.
   *
   * @return string
   *   The SQL statement to create the field.
   */
  protected function createFieldSql($table, $name, $spec, $skip_checks = FALSE) {
    $sql = $this->connection->escapeField($name) . ' ';

    $sql .= $this->createDataType($table, $name, $spec);

    $sqlsrv_type = $spec['sqlsrv_type'];
    $sqlsrv_type_native = $spec['sqlsrv_type_native'];

    $is_text = in_array($sqlsrv_type_native, [
      'char',
      'varchar',
      'text',
      'nchar',
      'nvarchar',
      'ntext',
    ]);
    if ($is_text === TRUE) {
      // If collation is set in the spec array, use it.
      // Otherwise use the database default.
      if (isset($spec['binary'])) {
        $default_collation = $this->getCollation();
        if ($spec['binary'] === TRUE) {
          $sql .= ' COLLATE ' . preg_replace("/_C[IS]_/", "_CS_", $default_collation);
        }
        elseif ($spec['binary'] === FALSE) {
          $sql .= ' COLLATE ' . preg_replace("/_C[IS]_/", "_CI_", $default_collation);
        }
      }
    }

    if (isset($spec['not null']) && $spec['not null']) {
      $sql .= ' NOT NULL';
    }

    if (!$skip_checks) {
      if (isset($spec['default'])) {
        $default = $this->defaultValueExpression($sqlsrv_type, $spec['default']);
        $sql .= " CONSTRAINT {{$table}_{$name}_df} DEFAULT $default";
      }
      if (!empty($spec['identity'])) {
        $sql .= ' IDENTITY';
      }
      if (!empty($spec['unsigned'])) {
        $sql .= ' CHECK (' . $this->connection->escapeField($name) . ' >= 0)';
      }
    }
    return $sql;
  }

  /**
   * Create the data type from a field specification.
   */
  protected function createDataType($table, $name, $spec) {
    $sqlsrv_type = $spec['sqlsrv_type'];
    $sqlsrv_type_native = $spec['sqlsrv_type_native'];

    $lengthable = in_array($sqlsrv_type_native, [
      'char',
      'varchar',
      'nchar',
      'nvarchar',
    ]);

    if (!empty($spec['length']) && $lengthable) {
      $length = $spec['length'];
      if (is_int($length) && $this->isUtf8()) {
        // Do we need to check if this exceeds the max length?
        // If so, use varchar(max).
        $length *= 3;
      }
      return $sqlsrv_type_native . '(' . $length . ')';
    }
    elseif (in_array($sqlsrv_type_native, ['numeric', 'decimal']) && isset($spec['precision']) && isset($spec['scale'])) {
      // Maximum precision for SQL Server 2008 or greater is 38.
      // For previous versions it's 28.
      if ($spec['precision'] > 38) {
        // Logs an error.
        \Drupal::logger('sqlsrv')->warning("Field '@field' in table '@table' has had it's precision dropped from @precision to 38",
                [
                  '@field' => $name,
                  '@table' => $table,
                  '@precision' => $spec['precision'],
                ]
                );
        $spec['precision'] = 38;
      }
      return $sqlsrv_type_native . '(' . $spec['precision'] . ', ' . $spec['scale'] . ')';
    }
    else {
      return $sqlsrv_type;
    }
  }

  /**
   * Get the SQL expression for a default value.
   *
   * @param string $sqlsr_type
   *   Database data type.
   * @param mixed $default
   *   Default value.
   *
   * @return string
   *   An SQL Default expression.
   */
  private function defaultValueExpression($sqlsr_type, $default) {
    // The actual expression depends on the target data type as it might require
    // conversions.
    $result = is_string($default) ? $this->connection->quote($default) : $default;
    if (
      Utils::GetMSSQLType($sqlsr_type) == 'varbinary') {
      $default = addslashes($default);
      $result = "CONVERT({$sqlsr_type}, '{$default}')";
    }
    return $result;
  }

  /**
   * Create key SQL.
   *
   * Returns a list of field names comma separated ready
   * to be used in a SQL Statement.
   *
   * @param array $fields
   *   Array of field names.
   * @param bool $as_array
   *   Return an array or a string?
   *
   * @return array|string
   *   The comma separated fields, or an array of fields
   */
  protected function createKeySql(array $fields, $as_array = FALSE) {
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
    return implode(', ', $ret);
  }

  /**
   * Returns the SQL needed to create an index.
   *
   * Supports XML indexes. Incomplete.
   *
   * @param string $table
   *   Table to create the index on.
   * @param string $name
   *   Name of the index.
   * @param array $fields
   *   Fields to be included in the Index.
   * @param mixed $xml_field
   *   The xml field.
   *
   * @return string
   *   SQL string.
   */
  protected function createIndexSql($table, $name, array $fields, $xml_field) {
    // Get information about current columns.
    $info = $this->queryColumnInformation($table);
    // Flatten $fields array if neccesary.
    $fields = $this->createKeySql($fields, TRUE);
    // XML indexes can only have 1 column.
    if (!empty($xml_field) && isset($fields[1])) {
      throw new \Exception("Cannot include an XML field on a multiple column index.");
    }
    // No more than one XML index per table.
    if ($xml_field && $this->tableHasXmlIndex($table)) {
      throw new \Exception("Only one primary clustered XML index is allowed per table.");
    }
    if (empty($xml_field)) {
      $fields_csv = implode(', ', $fields);
      return "CREATE INDEX {$name}_idx ON {{$table}} ({$fields_csv})";
    }
    else {
      return "CREATE PRIMARY XML INDEX {$name}_idx ON {{$table}} ({$xml_field})";
    }
  }

  /**
   * Set database-engine specific properties for a field.
   *
   * @param mixed $field
   *   A field description array, as specified in the schema documentation.
   */
  protected function processField($field) {
    $field['size'] = $field['size'] ?? 'normal';
    if (isset($field['type']) && ($field['type'] == 'serial' || $field['type'] == 'int') && isset($field['unsigned']) && $field['unsigned'] === TRUE && ($field['size'] == 'normal')) {
      $field['size'] = 'big';
    }
    // Set the correct database-engine specific datatype.
    // In case one is already provided, force it to lowercase.
    if (isset($field['sqlsrv_type'])) {
      $field['sqlsrv_type'] = mb_strtolower($field['sqlsrv_type']);
    }
    else {
      $map = $this->getFieldTypeMap();
      $field['sqlsrv_type'] = $map[$field['type'] . ':' . $field['size']];
    }

    $field['sqlsrv_type_native'] = Utils::GetMSSQLType($field['sqlsrv_type']);

    if (isset($field['type']) && $field['type'] == 'serial') {
      $field['identity'] = TRUE;
    }
    return $field;
  }

  /**
   * Compress Primary key Index.
   *
   * Sometimes the size of a table's primary key index needs
   * to be reduced to allow for Primary XML Indexes.
   *
   * @param string $table
   *   Table name.
   * @param int $limit
   *   Limit size.
   */
  public function compressPrimaryKeyIndex($table, $limit = 900) {
    // Introspect the schema and save the current primary key if the column
    // we are modifying is part of it.
    $primary_key_fields = $this->introspectPrimaryKeyFields($table);

    // SQL Server supports transactional DDL, so we can just start a transaction
    // here and pray for the best.
    $transaction = $this->connection->startTransaction();

    // Clear current Primary Key.
    $this->cleanUpPrimaryKey($table);

    // Recreate the Primary Key with the given limit size.
    $this->createPrimaryKey($table, $primary_key_fields, $limit);

  }

  /**
   * Return size information for current database.
   *
   * @return mixed
   *   Size info.
   */
  public function getSizeInfo() {
    $sql = <<< EOF
      SELECT
    DB_NAME(db.database_id) DatabaseName,
    (CAST(mfrows.RowSize AS FLOAT)*8)/1024 RowSizeMB,
    (CAST(mflog.LogSize AS FLOAT)*8)/1024 LogSizeMB,
    (CAST(mfstream.StreamSize AS FLOAT)*8)/1024 StreamSizeMB,
    (CAST(mftext.TextIndexSize AS FLOAT)*8)/1024 TextIndexSizeMB
FROM sys.databases db
    LEFT JOIN (SELECT database_id, SUM(size) RowSize FROM sys.master_files WHERE type = 0 GROUP BY database_id, type) mfrows ON mfrows.database_id = db.database_id
    LEFT JOIN (SELECT database_id, SUM(size) LogSize FROM sys.master_files WHERE type = 1 GROUP BY database_id, type) mflog ON mflog.database_id = db.database_id
    LEFT JOIN (SELECT database_id, SUM(size) StreamSize FROM sys.master_files WHERE type = 2 GROUP BY database_id, type) mfstream ON mfstream.database_id = db.database_id
    LEFT JOIN (SELECT database_id, SUM(size) TextIndexSize FROM sys.master_files WHERE type = 4 GROUP BY database_id, type) mftext ON mftext.database_id = db.database_id
    WHERE DB_NAME(db.database_id) = :database
EOF;
    // Database is defaulted from active connection.
    $options = $this->connection->getConnectionOptions();
    $database = $options['database'];
    return $this->connection->query($sql, [':database' => $database])->fetchObject();
  }

  /**
   * Get database information from sys.databases.
   *
   * @return mixed
   *   Database info.
   */
  public function getDatabaseInfo() {
    static $result;
    if (isset($result)) {
      return $result;
    }
    $sql = <<< EOF
      select name
        , db.snapshot_isolation_state
        , db.snapshot_isolation_state_desc
        , db.is_read_committed_snapshot_on
        , db.recovery_model
        , db.recovery_model_desc
        , db.collation_name
    from sys.databases db
    WHERE DB_NAME(db.database_id) = :database
EOF;
    // Database is defaulted from active connection.
    $options = $this->connection->getConnectionOptions();
    $database = $options['database'];
    $result = $this->connection->queryDirect($sql, [':database' => $database])->fetchObject();
    return $result;
  }

  /**
   * Get the collation.
   *
   * Get the collation of current connection whether
   * it has or not a database defined in it.
   *
   * @param string $table
   *   Table name.
   * @param string $column
   *   Column name.
   *
   * @return string
   *   Collation type.
   */
  public function getCollation($table = NULL, $column = NULL) {
    // No table or column provided, then get info about
    // database (if exists) or server default collation.
    if (empty($table) && empty($column)) {
      // Database is defaulted from active connection.
      $options = $this->connection->getConnectionOptions();
      $database = $options['database'];
      if (!empty($database)) {
        // Default collation for specific table.
        // CONVERT defaults to returning only 30 chars.
        $sql = "SELECT CONVERT (varchar(50), DATABASEPROPERTYEX('$database', 'collation'))";
        return $this->connection->queryDirect($sql)->fetchField();
      }
      else {
        // Server default collation.
        $sql = "SELECT SERVERPROPERTY ('collation') as collation";
        return $this->connection->queryDirect($sql)->fetchField();
      }
    }

    $sql = <<< EOF
      SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, COLLATION_NAME, DATA_TYPE
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = ':schema'
        AND TABLE_NAME = ':table'
        AND COLUMN_NAME = ':column'
EOF;
    $params = [];
    $params[':schema'] = $this->getDefaultSchema();
    $params[':table'] = $table;
    $params[':column'] = $column;
    $result = $this->connection->queryDirect($sql, $params)->fetchObject();
    return $result->COLLATION_NAME;
  }

  /**
   * Get the list of fields participating in the Primary Key.
   *
   * @param string $table
   *   Table name.
   *
   * @return string[]
   *   Fields participating in the Primary Key.
   */
  public function introspectPrimaryKeyFields($table) {
    $data = $this->queryColumnInformation($table);
    // All primary keys have a default index,
    // use that to see if we have a primary key
    // before iterating.
    if (!isset($data['primary_key_index']) || !isset($data['indexes'][$data['primary_key_index']])) {
      return [];
    }
    $result = [];
    $index = $data['indexes'][$data['primary_key_index']];
    foreach ($index['columns'] as $column) {
      if ($column['name'] != self::COMPUTED_PK_COLUMN_NAME) {
        $result[$column['name']] = $column['name'];
      }
      // Get full column definition.
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
   * Drop a constraint.
   *
   * @param string $table
   *   Table name.
   * @param string $name
   *   Constraint name.
   * @param bool $check
   *   Check if the constraint exists?
   */
  public function dropConstraint($table, $name, $check = TRUE) {
    // Check if constraint exists.
    if ($check) {
      // Do Something.
    }
    $sql = 'ALTER TABLE {' . $table . '} DROP CONSTRAINT [' . $name . ']';
    $this->connection->query($sql);
    $this->resetColumnInformation($table);
  }

  /**
   * Drop the related objects of a column (indexes, constraints, etc.).
   *
   * @param mixed $table
   *   Table name.
   * @param mixed $field
   *   Field name.
   */
  protected function dropFieldRelatedObjects($table, $field) {
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    // Fetch the list of indexes referencing this column.
    $sql = 'SELECT DISTINCT i.name FROM sys.columns c INNER JOIN sys.index_columns ic ON ic.object_id = c.object_id AND ic.column_id = c.column_id INNER JOIN sys.indexes i ON i.object_id = ic.object_id AND i.index_id = ic.index_id WHERE i.is_primary_key = 0 AND i.is_unique_constraint = 0 AND c.object_id = OBJECT_ID(:table) AND c.name = :name';
    $indexes = $this->connection->query($sql, [
      ':table' => $prefixInfo['table'],
      ':name' => $field,
    ]);
    foreach ($indexes as $index) {
      $this->connection->query('DROP INDEX [' . $index->name . '] ON {' . $table . '}');
      $this->resetColumnInformation($table);
    }

    // Fetch the list of check constraints referencing this column.
    $sql = 'SELECT DISTINCT cc.name FROM sys.columns c INNER JOIN sys.check_constraints cc ON cc.parent_object_id = c.object_id AND cc.parent_column_id = c.column_id WHERE c.object_id = OBJECT_ID(:table) AND c.name = :name';
    $constraints = $this->connection->query($sql, [
      ':table' => $prefixInfo['table'],
      ':name' => $field,
    ]);
    foreach ($constraints as $constraint) {
      $this->dropConstraint($table, $constraint->name, FALSE);
    }

    // Fetch the list of default constraints referencing this column.
    $sql = 'SELECT DISTINCT dc.name FROM sys.columns c INNER JOIN sys.default_constraints dc ON dc.parent_object_id = c.object_id AND dc.parent_column_id = c.column_id WHERE c.object_id = OBJECT_ID(:table) AND c.name = :name';
    $constraints = $this->connection->query($sql, [
      ':table' => $prefixInfo['table'],
      ':name' => $field,
    ]);
    foreach ($constraints as $constraint) {
      $this->dropConstraint($table, $constraint->name, FALSE);
    }

    // Drop any indexes on related computed columns when we have some.
    if ($this->uniqueKeyExists($table, $field)) {
      $this->dropUniqueKey($table, $field);
    }

    // If this column is part of a computed primary key, drop the key.
    $data = $this->queryColumnInformation($table);
    if (isset($data['columns'][self::COMPUTED_PK_COLUMN_NAME]['dependencies'][$field])) {
      $this->cleanUpPrimaryKey($table);
    }
  }

  /**
   * Return the name of the primary key of a table if it exists.
   *
   * @param mixed $table
   *   Table name.
   */
  protected function primaryKeyName($table) {
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    $sql = 'SELECT name FROM sys.key_constraints WHERE parent_object_id = OBJECT_ID(:table) AND type = :type';
    return $this->connection->query($sql, [
      ':table' => $prefixInfo['table'],
      ':type' => 'PK',
    ])->fetchField();
  }

  /**
   * Check if a key is a technical primary key.
   *
   * @param string $key_name
   *   Key name.
   */
  protected function isTechnicalPrimaryKey($key_name) {
    return $key_name && preg_match('/_pkey_technical$/', $key_name);
  }

  /**
   * Is the database configured as UTF8 character encoding?
   */
  protected function isUtf8() {
    $collation = $this->getCollation();
    return stristr($collation, '_UTF8') !== FALSE;
  }

  /**
   * Add a primary column to the table.
   *
   * @param mixed $table
   *   Table name.
   */
  protected function createTechnicalPrimaryColumn($table) {
    if (!$this->fieldExists($table, self::TECHNICAL_PK_COLUMN_NAME)) {
      $this->connection->query("ALTER TABLE {{$table}} ADD " . self::TECHNICAL_PK_COLUMN_NAME . " UNIQUEIDENTIFIER DEFAULT NEWID() NOT NULL");
      $this->resetColumnInformation($table);
    }
  }

  /**
   * Drop the primary key constraint.
   *
   * @param mixed $table
   *   Table name.
   */
  protected function cleanUpPrimaryKey($table) {
    // We are droping the constraint, but not the column.
    $existing_primary_key = $this->primaryKeyName($table);
    if ($existing_primary_key !== FALSE) {
      $this->dropConstraint($table, $existing_primary_key, FALSE);
    }
    // We are using computed columns to store primary keys,
    // try to remove it if it exists.
    if ($this->fieldExists($table, self::COMPUTED_PK_COLUMN_NAME)) {
      // The TCPK has compensation indexes that need to be cleared.
      $this->dropIndex($table, self::COMPUTED_PK_COLUMN_INDEX);
      $this->dropField($table, self::COMPUTED_PK_COLUMN_NAME);
    }
    // Try to get rid of the TPC.
    $this->cleanUpTechnicalPrimaryColumn($table);
  }

  /**
   * Tries to clean up the technical primary column.
   *
   * It will be deleted if:
   * (a) It is not being used as the current primary key and...
   * (b) There is no unique constraint because they depend on this column
   * (see addUniqueKey())
   *
   * @param string $table
   *   Table name.
   */
  protected function cleanUpTechnicalPrimaryColumn($table) {
    // Get the number of remaining unique indexes on the table, that
    // are not primary keys and prune the technical primary column if possible.
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    $sql = 'SELECT COUNT(*) FROM sys.indexes WHERE object_id = OBJECT_ID(:table) AND is_unique = 1 AND is_primary_key = 0';
    $args = [':table' => $prefixInfo['table']];
    $unique_indexes = $this->connection->query($sql, $args)->fetchField();
    $primary_key_is_technical = $this->isTechnicalPrimaryKey($this->primaryKeyName($table));
    if (!$unique_indexes && !$primary_key_is_technical) {
      $this->dropField($table, self::TECHNICAL_PK_COLUMN_NAME);
    }
  }

  /**
   * Find if an unique key exists.
   *
   * @param mixed $table
   *   Table name.
   * @param mixed $name
   *   Index name.
   *
   * @return bool
   *   Does the key exist?
   */
  protected function uniqueKeyExists($table, $name) {
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    return (bool) $this->connection->query('SELECT 1 FROM sys.indexes WHERE object_id = OBJECT_ID(:table) AND name = :name', [
      ':table' => $prefixInfo['table'],
      ':name' => $name . '_unique',
    ])->fetchField();
  }

  /**
   * Check if a table already has an XML index.
   *
   * @param string $table
   *   Table name.
   *
   * @return mixed
   *   Name if exists, else FALSE.
   */
  public function tableHasXmlIndex($table) {
    $info = $this->queryColumnInformation($table);
    if (isset($info['indexes']) && is_array($info['indexes'])) {
      foreach ($info['indexes'] as $name => $index) {
        if (strcasecmp($index['type_desc'], 'XML') == 0) {
          return $name;
        }
      }
    }
    return FALSE;
  }

  /**
   * Create an SQL statement to delete a comment.
   */
  protected function deleteCommentSql($table = NULL, $column = NULL) {
    $schema = $this->getDefaultSchema();
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    $prefixed_table = $prefixInfo['table'];
    $sql = "EXEC sp_dropextendedproperty @name=N'MS_Description'";
    $sql .= ",@level0type = N'Schema', @level0name = '" . $schema . "'";
    if (isset($table)) {
      $sql .= ",@level1type = N'Table', @level1name = '{$prefixed_table}'";
      if (isset($column)) {
        $sql .= ",@level2type = N'Column', @level2name = '{$column}'";
      }
    }
    return $sql;
  }

  /**
   * Create the SQL statement to add a new comment.
   */
  protected function createCommentSql($value, $table = NULL, $column = NULL) {
    $schema = $this->getDefaultSchema();
    $value = $this->prepareComment($value);
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    $prefixed_table = $prefixInfo['table'];
    $sql = "EXEC sp_addextendedproperty @name=N'MS_Description', @value={$value}";
    $sql .= ",@level0type = N'Schema', @level0name = '{$schema}'";
    if (isset($table)) {
      $sql .= ",@level1type = N'Table', @level1name = '{$prefixed_table}'";
      if (isset($column)) {
        $sql .= ",@level2type = N'Column', @level2name = '{$column}'";
      }
    }
    return $sql;
  }

  /**
   * Retrieve a table or column comment.
   */
  public function getComment($table, $column = NULL) {
    $prefixInfo = $this->getPrefixInfo($table, TRUE);
    $prefixed_table = $prefixInfo['table'];
    $schema = $this->getDefaultSchema();
    $column_string = isset($column) ? "'Column','{$column}'" : "NULL,NULL";
    $sql = "SELECT value FROM fn_listextendedproperty ('MS_Description','Schema','{$schema}','Table','{$prefixed_table}',{$column_string})";
    $comment = $this->connection->query($sql)->fetchField();
    return $comment;
  }

}

/**
 * @} End of "addtogroup schemaapi".
 */
