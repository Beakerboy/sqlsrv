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
 *
 * Temporary tables: temporary table support is done by means of global
 * temporary tables (#) to avoid the use of DIRECT QUERIES. You can enable and
 * disable the use of direct queries with:
 * $this->driver_settings->defaultDirectQuery = TRUE|FALSE.
 * http://blogs.msdn.com/b/brian_swan/archive/2010/06/15/ctp2-of-microsoft-driver-for-php-for-sql-server-released.aspx.
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
   * Database driver settings.
   *
   * Should be renamed to driverSettings.
   *
   * @var \Drupal\Driver\Database\sqlsrv\DriverSettings
   */
  public $driverSettings;

  /**
   * Error code for Login Failed.
   *
   * Usually happens when the database does not exist.
   */
  const DATABASE_NOT_FOUND = 28000;

  /**
   * Prepared PDO statements only makes sense if we cache them...
   *
   * @var mixed
   */
  private $statementCache = [];

  /**
   * This is the original replacement regexp from Microsoft.
   *
   * We could probably simplify it a lot because queries only contain
   * placeholders when we modify them.
   *
   * NOTE: removed 'escape' from the list, because it explodes
   * with LIKE xxx ESCAPE yyy syntax.
   */
  const RESERVED_REGEXP = '/\G
    # Everything that follows a boundary that is not : or _.
    \b(?<![:\[_])(?:
      # Any reserved words, followed by a boundary that is not an opening parenthesis.
      (action|admin|alias|any|are|array|at|begin|boolean|class|commit|contains|current|
      data|date|day|depth|domain|external|file|full|function|get|go|host|input|language|
      last|less|local|map|min|module|new|no|object|old|open|operation|parameter|parameters|
      path|plan|prefix|proc|public|ref|result|returns|role|row|rule|save|search|second|
      section|session|size|state|statistics|temporary|than|time|timestamp|tran|translate|
      translation|trim|user|value|variable|view|without)
      (?!\()
      |
      # Or a normal word.
      ([a-z]+)
    )\b
    |
    \b(
      [^a-z\'"\\\\]+
    )\b
    |
    (?=[\'"])
    (
      "  [^\\\\"] * (?: \\\\. [^\\\\"] *) * "
      |
      \' [^\\\\\']* (?: \\\\. [^\\\\\']*) * \'
    )
  /Six';

  /**
   * The list of SQLServer reserved key words.
   *
   * @var array
   */
  private $reservedKeyWords = [
    'action',
    'admin',
    'alias',
    'any',
    'are',
    'array',
    'at',
    'begin',
    'boolean',
    'class',
    'commit',
    'contains',
    'current',
    'data',
    'date',
    'day',
    'depth',
    'domain',
    'external',
    'file',
    'full',
    'function',
    'get',
    'go',
    'host',
    'input',
    'language',
    'last',
    'less',
    'local',
    'map',
    'min',
    'module',
    'new',
    'no',
    'object',
    'old',
    'open',
    'operation',
    'parameter',
    'parameters',
    'path',
    'plan',
    'prefix',
    'proc',
    'public',
    'ref',
    'result',
    'returns',
    'role',
    'row',
    'rule',
    'save',
    'search',
    'second',
    'section',
    'session',
    'size',
    'state',
    'statistics',
    'temporary',
    'than',
    'time',
    'timestamp',
    'tran',
    'translate',
    'translation',
    'trim',
    'user',
    'value',
    'variable',
    'view',
    'without',
  ];

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
    // Generate a new GLOBAL temporary table name and protect it from prefixing.
    // SQL Server requires that temporary tables to be non-qualified.
    $tablename = '##' . $this->generateTemporaryTableName();
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
    // Initialize settings.
    $this->driverSettings = DriverSettings::instanceFromSettings();

    $connection->setAttribute(\PDO::ATTR_STRINGIFY_FETCHES, TRUE);
    parent::__construct($connection, $connection_options);

    // This driver defaults to transaction support, except if explicitly passed
    // FALSE.
    $this->transactionSupport = !isset($connection_options['transactions']) || $connection_options['transactions'] !== FALSE;
    $this->transactionalDDLSupport = $this->transactionSupport;

    // Store connection options for future reference.
    $this->connectionOptions = $connection_options;
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

    // Get driver settings.
    $driverSettings = DriverSettings::instanceFromSettings();

    // Build the DSN.
    $options = [];
    $options['Server'] = $connection_options['host'] . (!empty($connection_options['port']) ? ',' . $connection_options['port'] : '');
    // We might not have a database in the
    // connection options, for example, during
    // database creation in Install.
    if (!empty($connection_options['database'])) {
      $options['Database'] = $connection_options['database'];
    }

    // Set isolation level if specified.
    if ($level = $driverSettings->GetDefaultIsolationLevel()) {
      $options['TransactionIsolation'] = $level;
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
   * Temporary override of DatabaseConnection::prepareQuery().
   *
   * @todo: remove that when DatabaseConnection::prepareQuery() is fixed to call
   *   $this->prepare() and not parent::prepare().
   *   https://www.drupal.org/node/2345451
   * @status: tested, temporary
   */
  public function prepareQuery($query, array $options = []) {

    // Merge default statement options. These options are
    // only specific for this preparation and will only override
    // the global configuration if set to different than NULL.
    $options = array_merge([
      'insecure' => FALSE,
      'statement_caching' => $this->driverSettings->GetStatementCachingMode(),
      'direct_query' => $this->driverSettings->GetDefaultDirectQueries(),
      'prefix_tables' => TRUE,
    ], $options);

    // Prefix tables. There is no global setting for this.
    if ($options['prefix_tables'] !== FALSE) {
      $query = $this->prefixTables($query);
    }

    // The statement caching settings only affect the storage
    // in the cache, but if a statement is already available
    // why not reuse it!
    if (isset($this->statementCache[$query])) {
      return $this->statementCache[$query];
    }

    // Region PDO Options.
    $pdo_options = [];

    // Set insecure options if requested so.
    if ($options['insecure'] === TRUE) {
      // We have to log this, prepared statements are a security RISK.
      // watchdog(
      // 'SQL Server Driver',
      // 'An insecure query has been executed against the database.'
      // . 'This is not critical, but worth looking into: %query',
      // array('%query' => $query)
      // );
      // These are defined in class Connection.
      // This PDO options are INSECURE, but will overcome the following issues:
      // (1) Duplicate placeholders
      // (2) > 2100 parameter limit
      // (3) Using expressions for group by with parameters are not detected as
      // equal. This options are not applied by default, they are just stored in
      // the connection options and applied when needed. See {Statement} class.
      // We ask PDO to perform the placeholders replacement itself because SQL
      // Server is not able to detect duplicated placeholders in complex
      // statements.
      // E.g. This query is going to fail because SQL Server cannot
      // detect that length1 and length2 are equals.
      // SELECT SUBSTRING(title, 1, :length1)
      // FROM node
      // GROUP BY SUBSTRING(title, 1, :length2
      // This is only going to work in PDO 3 but doesn't hurt in PDO 2. The
      // security of parameterized queries is not in effect when you use
      // PDO::ATTR_EMULATE_PREPARES => true. Your application should ensure that
      // the data that is bound to the parameter(s) does not contain malicious
      //
      // Transact-SQL code.
      // Never use this when you need special column binding.
      // THIS ONLY WORKS IF SET AT THE STATEMENT LEVEL.
      $pdo_options[\PDO::ATTR_EMULATE_PREPARES] = TRUE;
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
    // PDO::SQLSRV_ATTR_DIRECT_QUERY must be set to True.
    if ($this->driverSettings->GetStatementCachingMode() != 'always' || $options['direct_query'] == TRUE) {
      $pdo_options[\PDO::SQLSRV_ATTR_DIRECT_QUERY] = TRUE;
    }

    // It creates a cursor for the query, which allows you to iterate over the
    // result set without fetching the whole result at once. A scrollable
    // cursor, specifically, is one that allows iterating backwards.
    // https://msdn.microsoft.com/en-us/library/hh487158%28v=sql.105%29.aspx
    $pdo_options[\PDO::ATTR_CURSOR] = \PDO::CURSOR_SCROLL;

    // Lets you access rows in any order. Creates a client-side cursor query.
    $pdo_options[\PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE] = \PDO::SQLSRV_CURSOR_BUFFERED;

    // Endregion
    // Call our overriden prepare.
    $stmt = $this->PDOPrepare($query, $pdo_options);

    // If statement caching is enabled, store current statement for reuse.
    if ($options['statement_caching'] === TRUE) {
      $this->statementCache[$query] = $stmt;
    }

    return $stmt;
  }

  /**
   * Internal function: prepare a query by calling PDO directly.
   *
   * This function has to be public because it is called by other parts of the
   * database layer, but do not call it directly, as you risk locking down the
   * PHP process.
   *
   * @param mixed $query
   *   The query to prepare.
   * @param array $options
   *   Query options.
   *
   * @return mixed
   *   Prepared query.
   */
  public function pdoPrepare($query, array $options = []) {

    // Preprocess the query.
    if (!$this->driverSettings->GetDeafultBypassQueryPreprocess()) {
      $query = $this->preprocessQuery($query);
    }

    // You can set the MSSQL_APPEND_CALLSTACK_COMMENT to TRUE
    // to append to each query, in the form of comments, the current
    // backtrace plus other details that aid in debugging deadlocks
    // or long standing locks. Use in combination with MSSQL profiler.
    global $conf;
    if ($this->driverSettings->GetAppendCallstackComment()) {
      $oUser = \Drupal::currentUser();
      $uid = NULL;
      if ($oUser != NULL) {
        $uid = $oUser->getAccount()->id();
      }
      $trim = strlen(DRUPAL_ROOT);
      $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
      static $request_id;
      if (empty($request_id)) {
        $request_id = uniqid('', TRUE);
      }
      // Remove las item (it's alwasy PDOPrepare)
      $trace = array_splice($trace, 1);
      $comment = PHP_EOL . PHP_EOL;
      $comment .= '-- uid:' . (($uid) ? $uid : 'NULL') . PHP_EOL;
      $uri = (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'none');
      $uri = preg_replace("/[^a-zA-Z0-9]/i", "_", $uri);
      $comment .= '-- url:' . $uri . PHP_EOL;
      // $comment .= '-- request_id:' . $request_id . PHP_EOL;
      foreach ($trace as $t) {
        $function = isset($t['function']) ? $t['function'] : '';
        $file = '';
        if (isset($t['file'])) {
          $len = strlen($t['file']);
          if ($len > $trim) {
            $file = substr($t['file'], $trim, $len - $trim) . " [{$t['line']}]";
          }
        }
        $comment .= '-- ' . str_pad($function, 35) . '  ' . $file . PHP_EOL;
      }
      $query = $comment . PHP_EOL . $query;
    }

    return parent::prepare($query, $options);
  }

  /**
   * Replace reserved words.
   *
   * This method gets called between 3,000 and 10,000 times
   * on cold caches. Make sure it is simple and fast.
   *
   * @param mixed $matches
   *   What is this?
   *
   * @return string
   *   The match surrounded with brackets.
   */
  protected function replaceReservedCallback($matches) {
    if ($matches[1] !== '') {
      // Replace reserved words. We are not calling
      // quoteIdentifier() on purpose.
      return '[' . $matches[1] . ']';
    }
    // Let other value passthru.
    // by the logic of the regex above, this will always be the last match.
    return end($matches);
  }

  /**
   * Quotes an identifier if it matches a SQL Server reserved keyword.
   *
   * @param string $identifier
   *   The field to check.
   *
   * @return string
   *   The identifier, quoted if it matches a SQL Server reserved keyword.
   */
  protected function quoteIdentifier($identifier) {
    if (strpos($identifier, '.') !== FALSE) {
      list($table, $identifier) = explode('.', $identifier, 2);
    }
    if (in_array(strtolower($identifier), $this->reservedKeyWords, TRUE)) {
      // Quote the string for SQLServer reserved keywords.
      $identifier = '[' . $identifier . ']';
    }
    return isset($table) ? $table . '.' . $identifier : $identifier;
  }

  /**
   * {@inheritdoc}
   */
  public function escapeField($field) {
    $field = parent::escapeField($field);
    return $this->quoteIdentifier($field);
  }

  /**
   * {@inheritdoc}
   *
   * Because we are using global temporary tables, these are visible between
   * connections so we need to make sure that their names are as unique as
   * possible to prevent collisions.
   */
  protected function generateTemporaryTableName() {
    static $temp_key;
    if (!isset($temp_key)) {
      $temp_key = strtoupper(md5(uniqid("", TRUE)));
    }
    return "db_temp_" . $this->temporaryNameIndex++ . '_' . $temp_key;
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
        $stmt = $this->prepareQuery($query, ['insecure' => $insecure]);
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

      // Bypass query preprocessing and use direct queries.
      $ctx = new Context($this, TRUE, TRUE);

      $stmt = $this->prepareQuery($query, $options);
      $stmt->execute($args, $options);

      // Reset the context settings.
      unset($ctx);

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

  // phpcs:disable
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
   *
   * @deprecated in 8.x-1.0-rc6 and is removed from 8.x-1.0
   * @see https://www.drupal.org/project/sqlsrv/issues/3108368
   */
  public function query_direct($query, array $args = [], $options = []) {
    return $this->queryDirect($query, $args, $options);
  }
  // phpcs:enable

  /**
   * Internal function: massage a query to make it compliant with SQL Server.
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

    // Force quotes around some SQL Server reserved keywords.
    if (preg_match('/^SELECT/i', $query)) {
      $query = preg_replace_callback(self::RESERVED_REGEXP, [$this, 'replaceReservedCallback'], $query);
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
   * Includes special handling for temporary tables.
   */
  public function escapeTable($table) {
    // A static cache is better suited for this.
    static $tables = [];
    if (isset($tables[$table])) {
      return $tables[$table];
    }

    // Rescue the # prefix from the escaping.
    $is_temporary = $table[0] == '#';
    $is_temporary_global = $is_temporary && isset($table[1]) && $table[1] == '#';

    // Any temporary table prefix will be removed.
    $result = preg_replace('/[^A-Za-z0-9_.]+/', '', $table);

    // Restore the temporary prefix.
    if ($is_temporary) {
      if ($is_temporary_global) {
        $result = '##' . $result;
      }
      else {
        $result = '#' . $result;
      }
    }

    $tables[$table] = $result;

    return $result;
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
    }
    return $database;
  }

}

/**
 * @} End of "addtogroup database".
 */
