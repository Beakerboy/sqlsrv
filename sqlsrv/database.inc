<?php

/**
 * @file
 * Database interface code for Microsoft SQL Server.
 */

/**
 * @ingroup database
 * @{
 */

include_once DRUPAL_ROOT . '/includes/database/prefetch.inc';
include_once 'fastcache.inc';

/**
 * Defines a behaviour scope for the database
 * driver that lasts until the object is destroyed.
 */
class DatabaseContext {

  // @var \DatabaseConnection_sqlsrv $connection;
  var $connection;
  
  // @var string $state_bypass
  var $state_bypass;
  
  // @var string $state_direct
  var $state_direct;
  
  /**
   * Define the behaviour of the database driver during the scope of the
   * life of this instance.
   *
   * @param DatabaseConnection_sqlsrv $connection
   *  Instance of the connection to be configured.
   *
   * @param mixed $bypass_queries 
   *  Do not preprocess the query before execution.
   *
   * @param mixed $direct_query
   *  Prepare statements with SQLSRV_ATTR_DIRECT_QUERY = TRUE.
   */
  public function __construct(\DatabaseConnection_sqlsrv $connection, $bypass_queries = FALSE, $direct_query = FALSE) {
    $this->connection = $connection;
    $this->state_bypass = $this->connection->bypassQueryPreprocess;
    $this->state_direct = $this->connection->directQuery;
    $this->connection->bypassQueryPreprocess = $bypass_queries;
    $this->connection->directQuery = $bypass_queries;
  }
  
  public function __destruct() {
    // Restore previous driver configuration.
    $this->connection->bypassQueryPreprocess = $this->state_bypass;
    $this->connection->directQuery = $this->state_direct;
  }
}

/**
 * Summary of DatabaseConnection_sqlsrv
 *
 * Temporary tables: temporary table support is done by means of global temporary tables (#)
 * to avoid the use of DIRECT QUERIES. You can enable and disable the use of direct queries
 * with $conn->directQuery = TRUE|FALSE.
 * http://blogs.msdn.com/b/brian_swan/archive/2010/06/15/ctp2-of-microsoft-driver-for-php-for-sql-server-released.aspx
 *
 */
class DatabaseConnection_sqlsrv extends DatabaseConnection {

  // Do not preprocess the query before execution.
  public $bypassQueryPreprocess = FALSE;
  
  // Prepare statements with SQLSRV_ATTR_DIRECT_QUERY = TRUE.
  public $directQuery = FALSE;

  /**
   * Override of DatabaseConnection::driver().
   *
   * @status tested
   */
  public function driver() {
    return 'sqlsrv';
  }

  /**
   * Override of DatabaseConnection::databaseType().
   *
   * @status tested
   */
  public function databaseType() {
    return 'sqlsrv';
  }
  
  
  /**
   * Get the schema() object.
   *
   * @return DatabaseSchema_sqlsrv
   */
  public function schema() {
    return parent::schema();
  }

  /**
   * Override of DatabaseConnection::databaseType().
   *
   * @status complete
   */
  public function __construct(array $connection_options = array()) {
    // Store connection options for future reference.
    $this->connectionOptions = &$connection_options;

    // Set our custom statement class.
    $this->statementClass = 'DatabaseStatement_sqlsrv';

    // This driver defaults to transaction support, except if explicitly passed FALSE.
    $this->transactionSupport = !isset($connection_options['transactions']) || $connection_options['transactions'] !== FALSE;
    $this->transactionalDDLSupport = $this->transactionSupport;
    
    // Build the DSN.
    $options = array();
    $options[] = 'Server=' . $connection_options['host'] . (!empty($connection_options['port']) ? ',' . $connection_options['port'] : '');
    // We might not have a database in the
    // connection options, for example, during
    // database creation in Install.
    if (!empty($connection_options['database'])) {
      $options[] = 'Database=' . $connection_options['database'];
    }

    $dsn = 'sqlsrv:' . implode(';', $options);

    // PDO Options are set at a connection level.
    // and apply to all statements.
    $connection_options['pdo'] = array();

    // Set proper error mode for all statements
    $connection_options['pdo'][PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;

    // Set a Statement class, unless the driver opted out.
    if (!empty($this->statementClass)) {
      $connection_options['pdo'][PDO::ATTR_STATEMENT_CLASS] = array($this->statementClass, array($this));
    }

    // Initialize and prepare the connection prefix.
    $this->setPrefix(isset($this->connectionOptions['prefix']) ? $this->connectionOptions['prefix'] : '');

    // Call PDO::__construct and PDO::setAttribute.
    PDO::__construct($dsn, $connection_options['username'], $connection_options['password'], $connection_options['pdo']);
  }

  /**
   * Temporary override of DatabaseConnection::prepareQuery().
   *
   * @todo: remove that when DatabaseConnection::prepareQuery() is fixed to call
   *   $this->prepare() and not parent::prepare().
   * @status: tested, temporary
   *
   * @param mixed $query 
   * @param mixed $insecure 
   * @return PDOStatement
   */
  public function prepareQuery($query, $insecure = FALSE) {
    $query = $this->prefixTables($query);

    #region PDO Options
    
    $pdo_options = array();
    
    // Set insecure options if requested so.
    if ($insecure) {
      // We have to log this, prepared statements are a security RISK.
      // watchdog('SQL Server Driver', 'An insecure query has been executed against the database. This is not critical, but worth looking into: %query', array('%query' => $query));
      // These are defined in class Connection.
      // This PDO options are INSECURE, but will overcome the following issues:
      // (1) Duplicate placeholders
      // (2) > 2100 parameter limit
      // (3) Using expressions for group by with parameters are not detected as equal.
      // This options are not applied by default, they are just stored in the connection
      // options and applied when needed. See {Statement} class.

      // We ask PDO to perform the placeholders replacement itself because
      // SQL Server is not able to detect duplicated placeholders in
      // complex statements.
      // E.g. This query is going to fail because SQL Server cannot
      // detect that length1 and length2 are equals.
      // SELECT SUBSTRING(title, 1, :length1)
      // FROM node
      // GROUP BY SUBSTRING(title, 1, :length2
      // This is only going to work in PDO 3 but doesn't hurt in PDO 2.
      // The security of parameterized queries is not in effect when you use PDO::ATTR_EMULATE_PREPARES => true.
      // Your application should ensure that the data that is bound to the parameter(s) does not contain malicious
      // Transact-SQL code.
      // Never use this when you need special column binding.
      // THIS ONLY WORKS IF SET AT THE STATEMENT LEVEL.
      $pdo_options[PDO::ATTR_EMULATE_PREPARES] = TRUE;
    }

    // We run the statements in "direct mode" because the way PDO prepares
    // statement in non-direct mode cause temporary tables to be destroyed
    // at the end of the statement.
    // If you are using the PDO_SQLSRV driver and you want to execute a query that 
    // changes a database setting (e.g. SET NOCOUNT ON), use the PDO::query method with 
    // the PDO::SQLSRV_ATTR_DIRECT_QUERY attribute.
    // http://blogs.iis.net/bswan/archive/2010/12/09/how-to-change-database-settings-with-the-pdo-sqlsrv-driver.aspx
    // If a query requires the context that was set in a previous query, 
    // you should execute your queries with PDO::SQLSRV_ATTR_DIRECT_QUERY set to True. 
    // For example, if you use temporary tables in your queries, PDO::SQLSRV_ATTR_DIRECT_QUERY must be set 
    // to True.
    if ($this->directQuery) {
      $pdo_options[PDO::SQLSRV_ATTR_DIRECT_QUERY] = TRUE;
    }
    
    // It creates a cursor for the query, which allows you to iterate over the result set 
    // without fetching the whole result at once. A scrollable cursor, specifically, is one that allows 
    // iterating backwards.
    // https://msdn.microsoft.com/en-us/library/hh487158%28v=sql.105%29.aspx
    $pdo_options[PDO::ATTR_CURSOR] = PDO::CURSOR_SCROLL;

    // Lets you access rows in any order. Creates a client-side cursor query.
    $pdo_options[PDO::SQLSRV_ATTR_CURSOR_SCROLL_TYPE] = PDO::SQLSRV_CURSOR_BUFFERED;
    
    #endregion
    
    // Call our overriden prepare.
    return $this->PDOPrepare($query, $pdo_options);
  }

  /**
   * Internal function: prepare a query by calling PDO directly.
   *
   * This function has to be public because it is called by other parts of the
   * database layer, but do not call it directly, as you risk locking down the
   * PHP process.
   */
  public function PDOPrepare($query, array $options = array()) {

    // Preprocess the query.
    if (!$this->bypassQueryPreprocess) {
      $query = $this->preprocessQuery($query);
    }
    
    return parent::prepare($query, $options);
  }

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
      (action|admin|alias|any|are|array|at|begin|boolean|class|commit|contains|current|data|date|day|depth|domain|external|file|full|function|get|go|host|input|language|last|less|local|map|min|module|new|no|object|old|open|operation|parameter|parameters|path|plan|prefix|proc|public|ref|result|returns|role|row|rows|rule|save|search|second|section|session|size|state|statistics|temporary|than|time|timestamp|tran|translate|translation|trim|user|value|variable|view|without)
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
   * This method gets called between 3,000 and 10,000 times
   * on cold caches. Make sure it is simple and fast.
   *
   * @param mixed $matches 
   * @return mixed
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

  public function quoteIdentifier($identifier) {
    return '[' . $identifier .']';
  }

  public function escapeField($field) {
    if ($cache = fastcache::cache_get($field, 'schema_escapeField')) {
      return $cache->data;
    }
    if (strlen($field) > 0) {
      $result = implode('.', array_map(array($this, 'quoteIdentifier'), explode('.', preg_replace('/[^A-Za-z0-9_.]+/', '', $field))));
    }
    else {
      $result = '';
    }
    fastcache::cache_set($field, $result, 'schema_escapeField');
    return $result;
  }

  public function quoteIdentifiers($identifiers) {
    return array_map(array($this, 'quoteIdentifier'), $identifiers);
  }

  /**
   * Override of DatabaseConnection::escapeLike().
   */
  public function escapeLike($string) {
    return addcslashes($string, '\%_[]');
  }

  /**
   * Override of DatabaseConnection::queryRange().
   */
  public function queryRange($query, $from, $count, array $args = array(), array $options = array()) {
    $query = $this->addRangeToQuery($query, $from, $count);
    return $this->query($query, $args, $options);
  }

  private static $temporary_tables = array();
  
  /**
   * Generates a temporary table name. Because we are using
   * global temporary tables, these are visible between
   * connections so we need to make sure that their
   * names are as unique as possible to prevent collisions.
   *
   * @return
   *   A table name.
   */
  protected function generateTemporaryTableName() {
    static $temp_key;
    if (!isset($temp_key)) {
      $temp_key = strtoupper(md5(uniqid(rand(), true)));
    }
    return "db_temp_" . $this->temporaryNameIndex++ . '_' . $temp_key;
  }
  
  /**
   * Override of DatabaseConnection::queryTemporary().
   *
   * @status tested
   */
  public function queryTemporary($query, array $args = array(), array $options = array()) {
    // Generate a new GLOBAL temporary table name and protect it from prefixing.
    // SQL Server requires that temporary tables to be non-qualified.
    $tablename = '##' . $this->generateTemporaryTableName();
    $prefixes = $this->prefixes;
    $prefixes[$tablename] = '';
    $this->setPrefix($prefixes);

    // Replace SELECT xxx FROM table by SELECT xxx INTO #table FROM table.
    $query = preg_replace('/^SELECT(.*?)FROM/is', 'SELECT$1 INTO ' . $tablename . ' FROM', $query);
    $this->query($query, $args, $options);
    
    return $tablename;
  }

  /**
   * {@inheritdoc}
   *
   * This method is overriden to manage the insecure (EMULATE_PREPARE)
   * behaviour to prevent some compatibility issues with SQL Server.
   */
  public function query($query, array $args = array(), $options = array()) {

    // Use default values if not already set.
    $options += $this->defaultOptions();
    $stmt = NULL;

    try {
      // We allow either a pre-bound statement object or a literal string.
      // In either case, we want to end up with an executed statement object,
      // which we pass to PDOStatement::execute.
      if ($query instanceof DatabaseStatementInterface) {
        $stmt = $query;
        $stmt->execute(NULL, $options);
      }
      else {
        $this->expandArguments($query, $args);
        $insecure = isset($options['insecure']) ? $options['insecure'] : FALSE;
        // Try to detect duplicate place holders, this check's performance
        // is not a good addition to the driver, but does a good job preventing
        // duplicate placeholder errors.
        $argcount = count($args);
        if ($insecure === TRUE || $argcount >= 2100 || ($argcount != substr_count($query, ':'))) {
          $insecure = TRUE;
        }
        $stmt = $this->prepareQuery($query, $insecure);
        $stmt->execute($args, $options);
      }

      // Depending on the type of query we may need to return a different value.
      // See DatabaseConnection::defaultOptions() for a description of each
      // value.
      switch ($options['return']) {
        case Database::RETURN_STATEMENT:
          return $stmt;
        case Database::RETURN_AFFECTED:
          return $stmt->rowCount();
        case Database::RETURN_INSERT_ID:
          return $this->lastInsertId();
        case Database::RETURN_NULL:
          return NULL;
        default:
          throw new PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (PDOException $e) {
      if ($options['throw_exception']) {
        // Add additional debug information.
        if ($stmt instanceof DatabaseStatementInterface) {
          $e->query_string = $stmt->getQueryString();
        }
        else {
          $e->query_string = $query;
        }
        $e->args = $args;
        throw $e;
      }
      return NULL;
    }
  }
  
  /**
   * Like query but with no insecure detection or query preprocessing.
   * The caller is sure that his query is MS SQL compatible! Used internally
   * from the schema class, but could be called from anywhere.
   *
   * @param mixed $query 
   * @param array $args 
   * @param mixed $options 
   * @throws PDOException 
   * @return mixed
   */
  public function query_direct($query, array $args = array(), $options = array()) {

    // Use default values if not already set.
    $options += $this->defaultOptions();
    $stmt = NULL;

    try {
    
      // Bypass query preprocessing and use direct queries.
      $ctx = new \DatabaseContext($this, TRUE, TRUE);
      
      // Prepare the statement and execute it.
      $stmt = $this->prepareQuery($query, FALSE);
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
          return $stmt->rowCount();
        case Database::RETURN_INSERT_ID:
          return $this->lastInsertId();
        case Database::RETURN_NULL:
          return NULL;
        default:
          throw new PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (PDOException $e) {
      if ($options['throw_exception']) {
        // Add additional debug information.
        if ($stmt instanceof DatabaseStatementInterface) {
          $e->query_string = $stmt->getQueryString();
        }
        else {
          $e->query_string = $query;
        }
        $e->args = $args;
        throw $e;
      }
      return NULL;
    }
  }

  /**
   * Internal function: massage a query to make it compliant with SQL Server.
   */
  public function preprocessQuery($query) {
    // Generate a cache signature for this query.
    $query_signature = 'query_cache_' . md5($query);

    // Drill through everything...
    $success = FALSE;
    $cache = wincache_ucache_get($query_signature, $success);
    if ($success) {
      return $cache;
    }

    // Force quotes around some SQL Server reserved keywords.
    if (preg_match('/^SELECT/i', $query)) {
      $query = preg_replace_callback(self::RESERVED_REGEXP, array($this, 'replaceReservedCallback'), $query);
    }

    // Last chance to modify some SQL Server-specific syntax.
    $replacements = array(
      // Normalize SAVEPOINT syntax to the SQL Server one.
      '/^SAVEPOINT (.*)$/' => 'SAVE TRANSACTION $1',
      '/^ROLLBACK TO SAVEPOINT (.*)$/' => 'ROLLBACK TRANSACTION $1',
      // SQL Server doesn't need an explicit RELEASE SAVEPOINT.
      // Run a non-operaiton query to avoid a fatal error
      // when no query is runned.
      '/^RELEASE SAVEPOINT (.*)$/' => 'SELECT 1 /* $0 */',
      // TODO: For improved compatiblity with MySQL
      // we should create a StoredProcedure that
      // maps sp_who to a MySQL compatible approach.
      // http://stackoverflow.com/questions/2234691/sql-server-filter-output-of-sp-who2
      '/^SHOW PROCESSLIST$/' => 'EXEC sp_who',
      // List all table names
      '/^SHOW TABLES$/' => 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = \'BASE TABLE\'',
      // List all table and view names.
      '/^SHOW FULL TABLES$/' => 'SELECT TABLE_NAME, TABLE_TYPE FROM INFORMATION_SCHEMA.TABLES',
    );
    
    // Add prefixes to Drupal-specific functions.
    $defaultSchema = $this->schema()->GetDefaultSchema();
    foreach ($this->schema()->DrupalSpecificFunctions() as $function) {
      $replacements['/\b(?<![:.])(' . preg_quote($function) . ')\(/i'] =  "{$defaultSchema}.$1(";
    }
    
    // Rename some functions.
    $funcs = array(
      'LENGTH' => 'LEN',
      'POW' => 'POWER',
    );
    
    foreach ($funcs as $function => $replacement) {
      $replacements['/\b(?<![:.])(' . preg_quote($function) . ')\(/i'] = $replacement . '('; 
    }
    
    // Replace the ANSI concatenation operator with SQL Server poor one.
    $replacements['/\|\|/'] =  '+';
    
    // Now do all the replacements at once.
    $query = preg_replace(array_keys($replacements), array_values($replacements), $query);

    // Store the processed query, and make sure we expire it some time
    // so that scarcely used queries don't stay in the cache forever.
    wincache_ucache_set($query_signature, $query, rand(600, 3600));

    return $query;
  }

  /**
   * Internal function: add range options to a query.
   *
   * This cannot be set protected because it is used in other parts of the
   * database engine.
   *
   * @status tested
   */
  public function addRangeToQuery($query, $from, $count) {
    if ($from == 0) {
      // Easy case: just use a TOP query if we don't have to skip any rows.
      $query = preg_replace('/^\s*SELECT(\s*DISTINCT)?/Dsi', 'SELECT$1 TOP(' . $count . ')', $query);
    }
    else {
      // More complex case: use a TOP query to retrieve $from + $count rows, and
      // filter out the first $from rows using a window function.
      $query = preg_replace('/^\s*SELECT(\s*DISTINCT)?/Dsi', 'SELECT$1 TOP(' . ($from + $count) . ') ', $query);
      $query = '
        SELECT * FROM (
          SELECT sub2.*, ROW_NUMBER() OVER(ORDER BY sub2.__line2) AS __line3 FROM (
            SELECT 1 AS __line2, sub1.* FROM (' . $query . ') AS sub1
          ) as sub2
        ) AS sub3
        WHERE __line3 BETWEEN ' . ($from + 1) . ' AND ' . ($from + $count);
    }

    return $query;
  }

  public function mapConditionOperator($operator) {
    // SQL Server doesn't need special escaping for the \ character in a string
    // literal, because it uses '' to escape the single quote, not \'. Sadly
    // PDO doesn't know that and interpret \' as an escaping character. We
    // use a function call here to be safe.
    static $specials = array(
    'LIKE' => array('postfix' => " ESCAPE CHAR(92)"),
    'NOT LIKE' => array('postfix' => " ESCAPE CHAR(92)"),
    );
    return isset($specials[$operator]) ? $specials[$operator] : NULL;
  }

  /**
   * Override of DatabaseConnection::nextId().
   *
   * @status tested
   */
  public function nextId($existing = 0) {
    // If an exiting value is passed, for its insertion into the sequence table.
    if ($existing > 0) {
      try {
        $this->query_direct('SET IDENTITY_INSERT {sequences} ON; INSERT INTO {sequences} (value) VALUES(:existing); SET IDENTITY_INSERT {sequences} OFF', array(':existing' => $existing));
      }
      catch (Exception $e) {
        // Doesn't matter if this fails, it just means that this value is already
        // present in the table.
      }
    }

    // TODO: Refactor this to use OUTPUT because under high concurrency this does not work.
    return $this->query_direct('INSERT INTO {sequences} DEFAULT VALUES', array(), array('return' => Database::RETURN_INSERT_ID));
  }

  /**
   * Override DatabaseConnection::escapeTable().
   *
   * @status needswork
   */
  public function escapeTable($table) {
    // A static cache is better suited for this.
    static $tables = array();
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
}

class DatabaseStatement_sqlsrv extends DatabaseStatementBase implements DatabaseStatementInterface {
  
  // Flag to tell if statement should be run insecure.
  private $insecure = FALSE;

  // Tells the statement to set insecure parameters
  // such as SQLSRV_ATTR_DIRECT_QUERY and ATTR_EMULATE_PREPARES.
  public function RequireInsecure() {
    $this->insecure = TRUE;
  }

  public function execute($args = array(), $options = array()) {
    if (isset($options['fetch'])) {
      if (is_string($options['fetch'])) {
        // Default to an object. Note: db fields will be added to the object
        // before the constructor is run. If you need to assign fields after
        // the constructor is run, see http://drupal.org/node/315092.
        $this->setFetchMode(PDO::FETCH_CLASS, $options['fetch']);
      }
      else {
        $this->setFetchMode($options['fetch']);
      }
    }

    $logger = $this->dbh->getLogger();
    if (!empty($logger)) {
      $query_start = microtime(TRUE);
    }

    // If parameteres have already been binded
    // to the statement and we pass an empty array here
    // we will get a PDO Exception.
    if (empty($args)) {
      $args = NULL;
    }

    // Execute the query. Bypass parent override
    // and directly call PDOStatement implementation.
    $return = PDOStatement::execute($args);
    
    if (!$return) {
      $this->throwPDOException($statement);
    }

    // Fetch all the data from the reply, in order to release any lock
    // as soon as possible.
    $this->rowCount = $this->rowCount();

    // Bind column types properly.
    $null = array();
    $this->columnNames = array();
    for ($i = 0; $i < $this->columnCount(); $i++) {
      $meta = $this->getColumnMeta($i);
      $this->columnNames[]= $meta['name'];
      $sqlsrv_type = $meta['sqlsrv:decl_type'];
      $parts = explode(' ', $sqlsrv_type);
      $type = reset($parts);
      switch($type) {
        case 'varbinary':
          $null[$i] = NULL;
          $this->bindColumn($i + 1, $null[$i], PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
          break;
        case 'int':
        case 'bit':
        case 'smallint':
        case 'tinyint':
          $null[$i] = NULL;
          $this->bindColumn($i + 1, $null[$i], PDO::PARAM_INT);
          break;
      }
    }

    if (!empty($logger)) {
      $query_end = microtime(TRUE);
      $logger->log($this, $args, $query_end - $query_start);
    }

    // Remove technical columns from the final result set.
    $droppable_columns = array_flip(isset($options['sqlsrv_drop_columns']) ? $options['sqlsrv_drop_columns'] : array());
    $dropped_columns = array();
    foreach ($this->columnNames as $k => $column) {
      if (substr($column, 0, 2) == '__' || isset($droppable_columns[$column])) {
        $dropped_columns[] = $column;
        unset($this->columnNames[$k]);
      }
    }
    
    return $return;
  }

  /**
   * Throw a PDO Exception based on the last PDO error.
   *
   * @status: Unfinished.
   */
  protected function throwPDOException(&$statement = NULL) {
    // This is what a SQL Server PDO "no error" looks like.
    $null_error = array(0 => '00000', 1 => NULL, 2 => NULL);
    // The implementation in Drupal's Core StatementPrefetch Class
    // takes for granted that the error information is in the PDOConnection
    // but it is regularly held in the PDOStatement.
    $error_info_connection = $this->dbh->errorInfo();
    $error_info_statement =  !empty($statement) ? $statement->errorInfo() : $null_error;
    // TODO: Concatenate error information when both connection
    // and statement error info are valid.
    // We rebuild a message formatted in the same way as PDO.
    $error_info = ($error_info_connection === $null_error) ? $error_info_statement : $error_info_connection;
    $exception = new PDOException("SQLSTATE[" . $error_info[0] . "]: General error " . $error_info[1] . ": " . $error_info[2]);
    $exception->errorInfo = $error_info;
    unset($statement);
    throw $exception;
  }
  
  /**
   * Experimental, do not iterate if not needed.
   *
   * @param mixed $key_index 
   * @param mixed $value_index 
   * @return array|DatabaseStatement_sqlsrv
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
    // If we are asked for the default behaviour, rely
    // on the PDO as being faster.
    if ($key_index == 0 && $value_index == 1 && $this->columnCount() == 2) {
      $this->setFetchMode(PDO::FETCH_KEY_PAIR);
      return $this->fetchAll();
    }
    // We need to do this manually.
    $return = array();
    $this->setFetchMode(PDO::FETCH_NUM);
    foreach ($this as $record) {
      $return[$record[$key_index]] = $record[$value_index];
    }
    return $return;
  }
}

/**
 * @} End of "ingroup database".
 */

/**
 * Workaround a bug in the database autoloader by defining all the child classes.
 *
 * @see http://drupal.org/node/851136
 */
class DatabaseTransaction_sqlsrv extends DatabaseTransaction { }