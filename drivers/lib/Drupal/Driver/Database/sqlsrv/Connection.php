<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Connection
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * @addtogroup database
 * @{
 */

class Connection extends DatabaseConnection {

  public $bypassQueryPreprocess = FALSE;
  private $dsn = NULL;
  
  /**
   * Error code for Login Failed, usually happens when
   * the database does not exist.
   */
  const DATABASE_NOT_FOUND = 28000;
  
  /**
   * Default recommended collation for SQL Server.
   */
  const DEFAULT_COLLATION = 'UTF8_UNICODE_CI';

  public function lastInsertId() {
    return $this->connection->lastInsertId();
  }

  /**
   * Constructs a connection object.
   */
  public function __construct(\PDO $connection, array $connection_options) {
    //parent::__construct($connection, $connection_options);

    // This driver defaults to transaction support, except if explicitly passed FALSE.
    $this->transactionSupport = !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);

    // Transactional DDL is always available in PostgreSQL,
    // but we'll only enable it if standard transactions are.
    $this->transactionalDDLSupport = $this->transactionSupport;

    $this->connectionOptions = $connection_options;
    
    parent::__construct($connection, $connection_options);
    
    // Launch the connection to the server.
    /*parent::__construct('sqlsrv:' . implode(';', $options), $connection_options['username'], $connection_options['password'], array(
    // We run the statements in "direct mode" because the way PDO prepares
    // statement in non-direct mode cause temporary tables to be destroyed
    // at the end of the statement.
    \PDO::SQLSRV_ATTR_DIRECT_QUERY => TRUE,
    // We ask PDO to perform the placeholders replacement itself because
    // SQL Server is not able to detect duplicated placeholders in
    // complex statements.
    // E.g. This query is going to fail because SQL Server cannot
    // detect that length1 and length2 are equals.
    // SELECT SUBSTRING(title, 1, :length1)
    // FROM node
    // GROUP BY SUBSTRING(title, 1, :length2);
    // This is only going to work in PDO 3 but doesn't hurt in PDO 2.
    \PDO::ATTR_EMULATE_PREPARES => TRUE,
    ));*/

    // Force PostgreSQL to use the UTF-8 character set by default.
    //$this->connection->exec("SET NAMES 'UTF8'");

    // Execute PostgreSQL init_commands.
    //if (isset($connection_options['init_commands'])) {
    //  $this->connection->exec(implode('; ', $connection_options['init_commands']));
    //}
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = array()) {
    // Default to TCP connection on port 1433.
    if (empty($connection_options['port'])) {
      $connection_options['port'] = 1433;
    }
    
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

    $connection_options['database'] = (!empty($connection_options['database']) ? $connection_options['database'] : 'template1');
    
    // Allow PDO options to be overridden.
    $connection_options += array(
'pdo' => array(),
    );
    
    $connection_options['pdo'] += array(
    // We run the statements in "direct mode" because the way PDO prepares
    // statement in non-direct mode cause temporary tables to be destroyed
    // at the end of the statement.
\PDO::SQLSRV_ATTR_DIRECT_QUERY => TRUE,
    // We ask PDO to perform the placeholders replacement itself because
    // SQL Server is not able to detect duplicated placeholders in
    // complex statements.
    // E.g. This query is going to fail because SQL Server cannot
    // detect that length1 and length2 are equals.
    // SELECT SUBSTRING(title, 1, :length1)
    // FROM node
    // GROUP BY SUBSTRING(title, 1, :length2);
    // This is only going to work in PDO 3 but doesn't hurt in PDO 2.
\PDO::ATTR_EMULATE_PREPARES => TRUE,
    );
    
    $pdo = new \PDO($dsn, $connection_options['username'], $connection_options['password'], $connection_options['pdo']);

    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    
    // Fetch the name of the user-bound schema. It is the schema that SQL Server
    // will use for non-qualified tables.
    //$this->schema()->defaultSchema = parent::query("SELECT SCHEMA_NAME()")->fetchField();
    
    return $pdo;
  }
  
  /**
   * Override of PDO::prepare(): prepare a prefetching database statement.
   *
   * @status tested
   */
  public function prepare($statement, array $driver_options = array()) {
    return new Statement($this->connection, $this, $statement, $driver_options);
  }

  /**
   * Temporary override of DatabaseConnection::prepareQuery().
   *
   * @todo: remove that when DatabaseConnection::prepareQuery() is fixed to call
   *   $this->prepare() and not parent::prepare().
   * @status: tested, temporary
   */
  public function prepareQuery($query) {
    $query = $this->prefixTables($query);

    // Call our overriden prepare.
    return $this->prepare($query);
  }
  
  
  
  /**
   * Internal function: prepare a query by calling PDO directly.
   *
   * This function has to be public because it is called by other parts of the
   * database layer, but do not call it directly, as you risk locking down the
   * PHP process.
   */
  public function PDOPrepare($query, array $options = array()) {
    if (!$this->bypassQueryPreprocess) {
      $query = $this->preprocessQuery($query);
    }


    // If we've reached the 300 parameter,
    // add the query arguments directly onto the query using the
    // internal replacePlaceholders() function.
    // @see DatabaseStatement_sqlsrv::getStatement().
    if (isset($options['placeholder_args'])) {
      $args = $options['placeholder_args'];
      $query = $this->replacePlaceholders($query, $args);
      // Keeping this around could cause unforseen problems.
      unset($options['placeholder_args']);
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
                            
(action|admin|alias|any|are|array|at|begin|boolean|class|commit|contains|current|data|date|day|depth|domain|external|file|full|function|get|go|host|input|
language|last|less|local|map|min|module|new|no|object|old|open|operation|parameter|parameters|path|plan|prefix|proc|public|ref|result|returns|role|row|row
s|rule|save|search|second|section|session|size|state|statistics|temporary|than|time|timestamp|tran|translate|translation|trim|user|value|variable|view|wit
hout)
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

  protected function replaceReservedCallback($matches) {
    if ($matches[1] !== '') {
      // Replace reserved words.
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
    if (strlen($field) > 0) {
      $res = implode('.', array_map(array($this, 'quoteIdentifier'), explode('.', preg_replace('/[^A-Za-z0-9_.]+/', '', $field))));
    }
    return $res;
  }

  public function quoteIdentifiers($identifiers) {
    return array_map(array($this, 'quoteIdentifier'), $identifiers);;
  }

  /**
   * Override of DatabaseConnection::queryRange().
   */
  public function queryRange($query, $from, $count, array $args = array(), array $options = array()) {
    $query = $this->addRangeToQuery($query, $from, $count);
    return $this->query($query, $args, $options);
  }

  /**
   * Override of DatabaseConnection::queryTemporary().
   *
   * @status tested
   */
  public function queryTemporary($query, array $args = array(), array $options = array()) {
    // Generate a new temporary table name and protect it from prefixing.
    // SQL Server requires that temporary tables to be non-qualified.
    $tablename = '#' . $this->generateTemporaryTableName();
    $prefixes = $this->prefixes;
    $prefixes[$tablename] = '';
    $this->setPrefix($prefixes);

    // Replace SELECT xxx FROM table by SELECT xxx INTO #table FROM table.
    $query = preg_replace('/^SELECT(.*?)FROM/i', 'SELECT$1 INTO ' . $tablename . ' FROM', $query);

    $this->query($query, $args, $options);
    return $tablename;
  }

  /**
   * Internal function: do a manual placeholder replacement for a query.
   */
  protected function replacePlaceholders($query, array $args = array()) {
    // Check if $args is a simple numeric array.
    if (range(0, count($args) - 1) === array_keys($args)) {
      // In that case, we have unnamed placeholders.
      $count = 0;
      $new_args = array();
      foreach ($args as $value) {
        if (is_float($value)) {
          // Force the conversion to float so as not to loose precision
          // in the automatic cast.
          $value = sprintf('%F', $value);
        }
        elseif (!is_int($value)) {
          $value = $this->quote($value);
        }
        $query = substr_replace($query, $value, strpos($query, '?'), 1);
      }
      $args = $new_args;
    }
    else {
      // Else, this is using named placeholders.
      foreach ($args as $placeholder => $value) {
        if (is_float($value)) {
          // Force the conversion to float so as not to loose precision
          // in the automatic cast.
          $value = sprintf('%F', $value);
        }
        elseif (!is_int($value)) {
          $value = $this->quote((string) $value);
        }

        // PDO allows placeholders to not be prefixed by a colon. See
        // http://marc.info/?l=php-internals&m=111234321827149&w=2 for
        // more.
        if ($placeholder[0] != ':') {
          $placeholder = ":$placeholder";
        }
        // When replacing the placeholders, make sure we search for the
        // exact placeholder. For example, if searching for
        // ':db_placeholder_1', do not replace ':db_placeholder_11'.
        $query = preg_replace('/' . preg_quote($placeholder) . '\b/', $value, $query);
      }
    }
    return $query;
  }
	
  protected function expandArguments(&$query, &$args) {
    
    $modified = FALSE;

    // If the placeholder value to insert is an array, assume that we need
    // to expand it out into a comma-delimited set of placeholders.
    foreach (array_filter($args, 'is_array') as $key => $data) {
      $new_keys = array();
      $pos = 0;
      foreach ($data as $i => $value) {
        // This assumes that there are no other placeholders that use the same
        // name.  For example, if the array placeholder is defined as :example
        // and there is already an :example_2 placeholder, this will generate
        // a duplicate key.  We do not account for that as the calling code
        // is already broken if that happens.
        $new_keys[$key . '_' . $pos] = $value;
        $pos++;
      }

      // Update the query with the new placeholders.
      // preg_replace is necessary to ensure the replacement does not affect
      // placeholders that start with the same exact text. For example, if the
      // query contains the placeholders :foo and :foobar, and :foo has an
      // array of values, using str_replace would affect both placeholders,
      // but using the following preg_replace would only affect :foo because
      // it is followed by a non-word character.
      $query = preg_replace('#' . $key . '\b#', implode(', ', array_keys($new_keys)), $query);

      // Update the args array with the new placeholders.
      unset($args[$key]);
      $args += $new_keys;

      $modified = TRUE;
    }

    return $modified;
  }

  /**
   * Internal function: massage a query to make it compliant with SQL Server.
   */
  public function preprocessQuery($query) {

    $initial_query = $query;

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
    );
    $query = preg_replace(array_keys($replacements), $replacements, $query);

    // Add prefixes to Drupal-specific functions.
    $functions = array(
        'SUBSTRING',
        'SUBSTRING_INDEX',
        'GREATEST',
        'MD5',
        'LPAD',
        'GROUP_CONCAT',
        'CONCAT',
        'IF',
        );
    foreach ($functions as $function) {
      $query = preg_replace('/\b(?<![:.])(' . preg_quote($function) . ')\(/i', $this->schema()->defaultSchema . '.$1(', $query);
    }

    $replacements = array(
        'LENGTH' => 'LEN',
        'POW' => 'POWER',
        );
    foreach ($replacements as $function => $replacement) {
      $query = preg_replace('/\b(?<![:.])(' . preg_quote($function) . ')\(/i', $replacement . '(', $query);
    }

    // Replace the ANSI concatenation operator with SQL Server poor one.
    $query = preg_replace('/\|\|/', '+', $query);

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

      
      $initial_query = $query;
      
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
        $this->query('SET IDENTITY_INSERT {sequences} ON; INSERT INTO {sequences} (value) VALUES(:existing); SET IDENTITY_INSERT {sequences} OFF', array(':existing' => 
        $existing));
      }
      catch (Exception $e) {
        // Doesn't matter if this fails, it just means that this value is already
        // present in the table.
      }
    }

    return $this->query('INSERT INTO {sequences} DEFAULT VALUES', array(), array('return' => Database::RETURN_INSERT_ID));
  }

  /**
   * Override DatabaseConnection::escapeTable().
   *
   * @status needswork
   */
  public function escapeTable($table) {
    // Rescue the # prefix from the escaping.
    $res = ($table[0] == '#' ? '#' : '') . preg_replace('/[^A-Za-z0-9_.]+/', '', $table);
		
    return $res;
  }
  

  public function driver() {
    return 'sqlsrv';
  }

  public function databaseType() {
    return 'sqlsrv';
  }

  /**
   * Overrides \Drupal\Core\Database\Connection::createDatabase().
   *
   * @param string $database
   *   The name of the database to create.
   *
   * @throws \Drupal\Core\Database\DatabaseNotFoundException
   */
  public function createDatabase($database) {
    // Escape the database name.
    $database = Database::getConnection()->escapeDatabase($database);

    // If the PECL intl extension is installed, use it to determine the proper
    // locale.  Otherwise, fall back to en_US.
    if (class_exists('Locale')) {
      $locale = \Locale::getDefault();
    }
    else {
      $locale = 'en_US';
    }

    try {
      // Create the database and set it as active.
      $this->connection->exec("CREATE DATABASE $database COLLATE " . Connection::DEFAULT_COLLATION);
    }
    catch (\Exception $e) {
      throw new DatabaseNotFoundException($e->getMessage());
    }
  }
}

/**
 * @} End of "addtogroup database".
 */
