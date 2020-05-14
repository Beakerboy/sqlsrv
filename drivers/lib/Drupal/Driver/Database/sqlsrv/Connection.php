<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\DatabaseException;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\TransactionNoActiveException;
use Drupal\Core\Database\TransactionOutOfOrderException;
use Drupal\Core\Database\TransactionNameNonUniqueException;

/**
 * @addtogroup database
 * @{
 */

/**
 * Sqlsvr implementation of \Drupal\Core\Database\Connection.
 */
class Connection extends DatabaseConnection {

  /**
   * The schema object for this connection.
   *
   * Set to NULL when the schema is destroyed.
   *
   * @var \Drupal\Driver\Database\sqlsrv\Schema|null
   */
  protected $schema = NULL;

  /**
   * Error code for Login Failed.
   *
   * Usually happens when the database does not exist.
   */
  const DATABASE_NOT_FOUND = 28000;

  /**
   * A map of condition operators to sqlsrv operators.
   *
   * SQL Server doesn't need special escaping for the \ character in a string
   * literal, because it uses '' to escape the single quote, not \'.
   *
   * @var array
   */
  protected static $sqlsrvConditionOperatorMap = [
    // These can be changed to 'LIKE' => ['postfix' => " ESCAPE '\\'"],
    // if https://bugs.php.net/bug.php?id=79276 is fixed.
    'LIKE' => [],
    'NOT LIKE' => [],
    'LIKE BINARY' => ['operator' => 'LIKE'],
  ];

  /**
   * {@inheritdoc}
   */
  public function queryRange($query, $from, $count, array $args = [], array $options = []) {
    if (strpos($query, " ORDER BY ") === FALSE) {
      $query .= " ORDER BY (SELECT NULL)";
    }
    $query .= " OFFSET {$from} ROWS FETCH NEXT {$count} ROWS ONLY";
    return $this->query($query, $args, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function queryTemporary($query, array $args = [], array $options = []) {
    $tablename = '#' . $this->generateTemporaryTableName();
    // Temporary tables cannot be introspected so using them is limited on some
    // scenarios.
    if (isset($options['real_table']) && $options['real_table'] === TRUE) {
      $tablename = trim($tablename, "#");
    }
    $prefixes = $this->prefixes;
    $prefixes[$tablename] = '';
    $this->setPrefix($prefixes);

    // Having comments in the query can be tricky and break the
    // SELECT FROM  -> SELECT INTO conversion.
    /** @var \Drupal\Driver\Database\sqlsrv\Schema $schema */
    $schema = $this->schema();
    $query = $schema->removeSQLComments($query);

    // Replace SELECT xxx FROM table by SELECT xxx INTO #table FROM table.
    $query = preg_replace('/^SELECT(.*?)FROM/is', 'SELECT$1 INTO ' . $tablename . ' FROM', $query);
    $this->query($query, $args, $options);

    return $tablename;
  }

  /**
   * {@inheritdoc}
   */
  public function driver() {
    return 'sqlsrv';
  }

  /**
   * {@inheritdoc}
   */
  public function databaseType() {
    return 'sqlsrv';
  }

  /**
   * {@inheritdoc}
   */
  public function createDatabase($database) {
    // Escape the database name.
    $database = Database::getConnection()->escapeDatabase($database);

    try {
      // Create the database and set it as active.
      $this->connection->exec("CREATE DATABASE $database COLLATE " . Schema::DEFAULT_COLLATION_CI);
    }
    catch (DatabaseException $e) {
      throw new DatabaseNotFoundException($e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function mapConditionOperator($operator) {
    return isset(static::$sqlsrvConditionOperatorMap[$operator]) ? static::$sqlsrvConditionOperatorMap[$operator] : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function nextId($existing = 0) {
    // If an exiting value is passed, for its insertion into the sequence table.
    if ($existing > 0) {
      try {
        $sql = 'SET IDENTITY_INSERT {sequences} ON;';
        $sql .= ' INSERT INTO {sequences} (value) VALUES(:existing);';
        $sql .= ' SET IDENTITY_INSERT {sequences} OFF';
        $this->queryDirect($sql, [':existing' => $existing]);
      }
      catch (\Exception $e) {
        // Doesn't matter if this fails, it just means that this value is
        // already present in the table.
      }
    }

    // Refactored to use OUTPUT because under high concurrency LAST_INSERTED_ID
    // does not work properly.
    return $this->queryDirect('INSERT INTO {sequences} OUTPUT (Inserted.[value]) DEFAULT VALUES')->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(\PDO $connection, array $connection_options) {
    $connection->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, TRUE);
    $this->identifierQuotes = ['[', ']'];
    parent::__construct($connection, $connection_options);

    // This driver defaults to transaction support, except if explicitly passed
    // FALSE.
    $this->transactionSupport = !isset($connection_options['transactions']) || $connection_options['transactions'] !== FALSE;
    $this->transactionalDDLSupport = $this->transactionSupport;
  }

  /**
   * {@inheritdoc}
   */
  public function getFullQualifiedTableName($table) {
    $options = $this->getConnectionOptions();
    $prefix = $this->tablePrefix($table);
    $schema_name = $this->schema->getDefaultSchema();
    return $options['database'] . '.' . $schema_name . '.' . $prefix . $table;
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = []) {

    // Build the DSN.
    $options = [];
    $options['Server'] = $connection_options['host'] . (!empty($connection_options['port']) ? ',' . $connection_options['port'] : '');
    // We might not have a database in the
    // connection options, for example, during
    // database creation in Install.
    if (!empty($connection_options['database'])) {
      $options['Database'] = $connection_options['database'];
    }

    // Build the DSN.
    $dsn = 'sqlsrv:';
    foreach ($options as $key => $value) {
      $dsn .= (empty($key) ? '' : "{$key}=") . $value . ';';
    }

    // Allow PDO options to be overridden.
    $connection_options += [
      'pdo' => [],
    ];
    $connection_options['pdo'] += [
      \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ];

    // Set a Statement class, unless the driver opted out.
    // $connection_options['pdo'][PDO::ATTR_STATEMENT_CLASS] =
    // array(Statement::class, array(Statement::class));.
    // Actually instantiate the PDO.
    try {
      $pdo = new \PDO($dsn, $connection_options['username'], $connection_options['password'], $connection_options['pdo']);
    }
    catch (\Exception $e) {
      if ($e->getCode() == static::DATABASE_NOT_FOUND) {
        throw new DatabaseNotFoundException($e->getMessage(), $e->getCode(), $e);
      }
      throw new $e();
    }

    return $pdo;
  }

  /**
   * {@inheritdoc}
   */
  public function prepareQuery($query, $quote_identifiers = TRUE, array $options = []) {
    $default_options = [
      'insecure' => FALSE,
      // Caching mode can be 'disabled', 'on-demand', or 'always'.
      'caching_mode' => 'disabled',
      'cache_statements' => FALSE,
      'direct_query' => FALSE,
      'bypass_preprocess' => FALSE,
    ];
    // Merge default statement options. These options are
    // only specific for this preparation and will only override
    // the global configuration if set to different than NULL.
    $options += $default_options;

    $query = $this->prefixTables($query);
    if ($quote_identifiers) {
      $query = $this->quoteIdentifiers($query);
    }

    // Preprocess the query.
    if (!$options['bypass_preprocess']) {
      $query = $this->preprocessQuery($query);
    }

    $driver_options = [];

    // Set insecure options if requested so.
    if ($options['insecure'] === TRUE) {
      // Never use this when you need special column binding.
      // Unlike other PDO drivers, sqlsrv requires this attribute be set
      // on the statement, not the connection.
      $driver_options[\PDO::ATTR_EMULATE_PREPARES] = TRUE;
    }

    // We run the statements in "direct mode" because the way PDO prepares
    // statement in non-direct mode cause temporary tables to be destroyed
    // at the end of the statement.
    // If you are using the PDO_SQLSRV driver and you want to execute a query
    // that changes a database setting (e.g. SET NOCOUNT ON), use the PDO::query
    // method with the PDO::SQLSRV_ATTR_DIRECT_QUERY attribute.
    // http://blogs.iis.net/bswan/archive/2010/12/09/how-to-change-database-settings-with-the-pdo-sqlsrv-driver.aspx
    // If a query requires the context that was set in a previous query,
    // you should execute your queries with PDO::SQLSRV_ATTR_DIRECT_QUERY set to
    // True. For example, if you use temporary tables in your queries,
    // PDO::SQLSRV_ATTR_DrIRECT_QUERY must be set to True.
    // Why does caching mode taken into account for queryDirect?
    if ($options['caching_mode'] != 'always' || $options['direct_query'] == TRUE) {
      $driver_options[\PDO::SQLSRV_ATTR_DIRECT_QUERY] = TRUE;
    }

    // It creates a cursor for the query, which allows you to iterate over the
    // result set without fetching the whole result at once. A scrollable
    // cursor, specifically, is one that allows iterating backwards.
    // https://msdn.microsoft.com/en-us/library/hh487158%28v=sql.105%29.aspx
    $driver_options[\PDO::ATTR_CURSOR] = \PDO::CURSOR_SCROLL;

    // Lets you access rows in any order. Creates a client-side cursor query.
    $driver_options[\PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE] = \PDO::SQLSRV_CURSOR_BUFFERED;
    fwrite(STDOUT, $query . "\n");
    // Call our overriden prepare.
    return  $this->connection->prepare($query, $driver_options);
  }

  /**
   * {@inheritdoc}
   *
   * This method is overriden to manage the insecure (EMULATE_PREPARE)
   * behaviour to prevent some compatibility issues with SQL Server.
   */
  public function query($query, array $args = [], $options = []) {

    // Use default values if not already set.
    $options += $this->defaultOptions();
    if (isset($options['target'])) {
      @trigger_error('Passing a \'target\' key to \\Drupal\\Core\\Database\\Connection::query $options argument is deprecated in drupal:8.8.0 and will be removed before drupal:9.0.0. Instead, use \\Drupal\\Core\\Database\\Database::getConnection($target)->query(). See https://www.drupal.org/node/2993033', E_USER_DEPRECATED);
    }
    $stmt = NULL;

    try {
      // We allow either a pre-bound statement object or a literal string.
      // In either case, we want to end up with an executed statement object,
      // which we pass to PDOStatement::execute.
      if ($query instanceof StatementInterface) {
        $stmt = $query;
        $stmt->execute(NULL, $options);
      }
      else {
        $this->expandArguments($query, $args);
        $query = rtrim($query, ";  \t\n\r\0\x0B");
        if (strpos($query, ';') !== FALSE && empty($options['allow_delimiter_in_query'])) {
          throw new \InvalidArgumentException('; is not supported in SQL strings. Use only one statement at a time.');
        }

        $insecure = isset($options['insecure']) ? $options['insecure'] : FALSE;
        // Try to detect duplicate place holders, this check's performance
        // is not a good addition to the driver, but does a good job preventing
        // duplicate placeholder errors.
        $argcount = count($args);
        if ($insecure === TRUE || $argcount >= 2100 || ($argcount != substr_count($query, ':'))) {
          $insecure = TRUE;
        }
        $stmt = $this->prepareQuery($query, TRUE, ['insecure' => $insecure]);
        $stmt->execute($args, $options);
      }

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return']) {
        case Database::RETURN_STATEMENT:
          return $stmt;

        case Database::RETURN_AFFECTED:
          $stmt->allowRowCount = TRUE;
          return $stmt->rowCount();

        case Database::RETURN_INSERT_ID:
          return $this->connection->lastInsertId();

        case Database::RETURN_NULL:
          return NULL;

        default:
          throw new \PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (\PDOException $e) {
      // Most database drivers will return NULL here, but some of them
      // (e.g. the SQLite driver) may need to re-run the query, so the return
      // value will be the same as for static::query().
      return $this->handleQueryException($e, $query, $args, $options);
    }
  }

  /**
   * Like query but with no insecure detection or query preprocessing.
   *
   * The caller is sure that the query is MS SQL compatible! Used internally
   * from the schema class, but could be called from anywhere.
   *
   * @param mixed $query
   *   Query.
   * @param array $args
   *   Query arguments.
   * @param mixed $options
   *   Query options.
   *
   * @throws \PDOException
   *
   * @return mixed
   *   Query result.
   */
  public function queryDirect($query, array $args = [], $options = []) {

    // Use default values if not already set.
    $options += $this->defaultOptions();
    $stmt = NULL;

    try {
      $direct_query_options = [
        'direct_query' => TRUE,
        'bypass_preprocess' => TRUE,
      ];
      $stmt = $this->prepareQuery($query, TRUE, $direct_query_options + $options);
      $stmt->execute($args, $options);

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return']) {
        case Database::RETURN_STATEMENT:
          return $stmt;

        case Database::RETURN_AFFECTED:
          $stmt->allowRowCount = TRUE;
          return $stmt->rowCount();

        case Database::RETURN_INSERT_ID:
          return $this->connection->lastInsertId();

        case Database::RETURN_NULL:
          return NULL;

        default:
          throw new \PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (\PDOException $e) {
      // Most database drivers will return NULL here, but some of them
      // (e.g. the SQLite driver) may need to re-run the query, so the return
      // value will be the same as for static::query().
      return $this->handleQueryException($e, $query, $args, $options);
    }
  }

  /**
   * Massage a query to make it compliant with SQL Server.
   *
   * @param mixed $query
   *   Query string.
   *
   * @return string
   *   Query string in MS SQL format.
   */
  public function preprocessQuery($query) {
    // Generate a cache signature for this query.
    $query_signature = 'query_cache_' . md5($query);

    // Drill through everything...
    $success = FALSE;
    $cache = '';
    if (extension_loaded('wincache')) {
      $cache = wincache_ucache_get($query_signature, $success);
    }
    elseif (extension_loaded('apcu') && (PHP_SAPI !== 'cli' || (bool) ini_get('apc.enable_cli'))) {
      $cache = apcu_fetch($query_signature, $success);
    }
    if ($success) {
      return $cache;
    }

    // Last chance to modify some SQL Server-specific syntax.
    $replacements = [];

    // Add prefixes to Drupal-specific functions.
    /** @var \Drupal\Driver\Database\sqlsrv\Schema $schema */
    $schema = $this->schema();
    $defaultSchema = $schema->GetDefaultSchema();
    foreach ($schema->DrupalSpecificFunctions() as $function) {
      $replacements['/\b(?<![:.])(' . preg_quote($function) . ')\(/i'] = "{$defaultSchema}.$1(";
    }

    // Rename some functions.
    $funcs = [
      'LENGTH' => 'LEN',
      'POW' => 'POWER',
    ];

    foreach ($funcs as $function => $replacement) {
      $replacements['/\b(?<![:.])(' . preg_quote($function) . ')\(/i'] = $replacement . '(';
    }

    // Replace the ANSI concatenation operator with SQL Server poor one.
    $replacements['/\|\|/'] = '+';

    // Now do all the replacements at once.
    $query = preg_replace(array_keys($replacements), array_values($replacements), $query);

    // Store the processed query, and make sure we expire it some time
    // so that scarcely used queries don't stay in the cache forever.
    if (extension_loaded('wincache')) {
      wincache_ucache_set($query_signature, $query, rand(600, 3600));
    }
    elseif (extension_loaded('apcu') && (PHP_SAPI !== 'cli' || (bool) ini_get('apc.enable_cli'))) {
      apcu_store($query_signature, $query, rand(600, 3600));
    }

    return $query;
  }

  /**
   * {@inheritdoc}
   *
   * Using SQL Server query syntax.
   */
  public function rollBack($savepoint_name = 'drupal_transaction') {
    if (!$this->supportsTransactions()) {
      return;
    }
    if (!$this->inTransaction()) {
      throw new TransactionNoActiveException();
    }
    // A previous rollback to an earlier savepoint may mean that the savepoint
    // in question has already been accidentally committed.
    if (!isset($this->transactionLayers[$savepoint_name])) {
      throw new TransactionNoActiveException();
    }
    // We need to find the point we're rolling back to, all other savepoints
    // before are no longer needed. If we rolled back other active savepoints,
    // we need to throw an exception.
    $rolled_back_other_active_savepoints = FALSE;
    while ($savepoint = array_pop($this->transactionLayers)) {
      if ($savepoint == $savepoint_name) {
        // If it is the last the transaction in the stack, then it is not a
        // savepoint, it is the transaction itself so we will need to roll back
        // the transaction rather than a savepoint.
        if (empty($this->transactionLayers)) {
          break;
        }
        $this->query('ROLLBACK TRANSACTION ' . $savepoint);
        $this->popCommittableTransactions();
        if ($rolled_back_other_active_savepoints) {
          throw new TransactionOutOfOrderException();
        }
        return;
      }
      else {
        $rolled_back_other_active_savepoints = TRUE;
      }
    }
    // Notify the callbacks about the rollback.
    $callbacks = $this->rootTransactionEndCallbacks;
    $this->rootTransactionEndCallbacks = [];
    foreach ($callbacks as $callback) {
      call_user_func($callback, FALSE);
    }
    $this->connection->rollBack();
    if ($rolled_back_other_active_savepoints) {
      throw new TransactionOutOfOrderException();
    }
  }

  /**
   * {@inheritdoc}
   *
   * Using SQL Server query syntax.
   */
  public function pushTransaction($name) {
    if (!$this->supportsTransactions()) {
      return;
    }
    if (isset($this->transactionLayers[$name])) {
      throw new TransactionNameNonUniqueException($name . " is already in use.");
    }
    // If we're already in a transaction then we want to create a savepoint
    // rather than try to create another transaction.
    if ($this->inTransaction()) {
      $this->queryDirect('SAVE TRANSACTION ' . $name);
    }
    else {
      $this->connection->beginTransaction();
    }
    $this->transactionLayers[$name] = $name;
  }

  /**
   * Commit all the transaction layers that can commit.
   */
  protected function popCommittableTransactions() {
    // Commit all the committable layers.
    foreach (array_reverse($this->transactionLayers) as $name => $active) {
      // Stop once we found an active transaction.
      if ($active) {
        break;
      }
      // If there are no more layers left then we should commit.
      unset($this->transactionLayers[$name]);
      if (empty($this->transactionLayers)) {
        $this->doCommit();
      }
      else {
        // Nothing to do in SQL Server.
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * Adding schema to the connection URL.
   */
  public static function createConnectionOptionsFromUrl($url, $root) {
    $database = parent::createConnectionOptionsFromUrl($url, $root);
    $url_components = parse_url($url);
    if (isset($url_components['query'])) {
      $query = [];
      parse_str($url_components['query'], $query);
      if (isset($query['schema'])) {
        $database['schema'] = $query['schema'];
      }
      $database['cache_schema'] = isset($query['cache_schema']) && $query['cache_schema'] == 'true' ? TRUE : FALSE;
    }
    return $database;
  }

  /**
   * {@inheritdoc}
   *
   * Adding schema to the connection URL.
   */
  public static function createUrlFromConnectionOptions(array $connection_options) {
    if (!isset($connection_options['driver'], $connection_options['database'])) {
      throw new \InvalidArgumentException("As a minimum, the connection options array must contain at least the 'driver' and 'database' keys");
    }

    $user = '';
    if (isset($connection_options['username'])) {
      $user = $connection_options['username'];
      if (isset($connection_options['password'])) {
        $user .= ':' . $connection_options['password'];
      }
      $user .= '@';
    }

    $host = empty($connection_options['host']) ? 'localhost' : $connection_options['host'];

    $db_url = $connection_options['driver'] . '://' . $user . $host;

    if (isset($connection_options['port'])) {
      $db_url .= ':' . $connection_options['port'];
    }

    $db_url .= '/' . $connection_options['database'];
    $query = [];
    if (isset($connection_options['module'])) {
      $query['module'] = $connection_options['module'];
    }
    if (isset($connection_options['schema'])) {
      $query['schema'] = $connection_options['schema'];
    }
    if (isset($connection_options['cache_schema'])) {
      $query['cache_schema'] = $connection_options['cache_schema'];
    }

    if (count($query) > 0) {
      $parameters = [];
      foreach ($query as $key => $values) {
        $parameters[] = $key . '=' . $values;
      }
      $query_string = implode("&amp;", $parameters);
      $db_url .= '?' . $query_string;
    }
    if (isset($connection_options['prefix']['default']) && $connection_options['prefix']['default'] !== '') {
      $db_url .= '#' . $connection_options['prefix']['default'];
    }

    return $db_url;
  }

}

/**
 * @} End of "addtogroup database".
 */
