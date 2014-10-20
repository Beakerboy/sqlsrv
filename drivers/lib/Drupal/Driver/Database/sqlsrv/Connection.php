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
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Database\DatabaseException;

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

  public function lastInsertId() {
    return $this->connection->lastInsertId();
  }

  /**
   * {@inheritdoc}
   */
  public function __construct(\PDO $connection, array $connection_options) {

    // This driver defaults to transaction support, except if explicitly passed FALSE.
    $this->transactionSupport = !isset($connection_options['transactions']) || ($connection_options['transactions'] !== FALSE);

    // Transactional DDL is always available in PostgreSQL,
    // but we'll only enable it if standard transactions are.
    $this->transactionalDDLSupport = $this->transactionSupport;

    $this->connectionOptions = $connection_options;

    parent::__construct($connection, $connection_options);

    // Fetch the name of the user-bound schema. It is the schema that SQL Server
    // will use for non-qualified tables.
    $this->schema()->defaultSchema = $this->schema()->GetDefaultSchema();
  }

  /**
   * {@inheritdoc}
   */
  public static function open(array &$connection_options = array()) {
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

    // Allow PDO options to be overridden.
    $connection_options['pdo'] = array();
    
    // This PDO options are INSECURE, but will overcome the following issues:
    // (1) Duplicate placeholders
    // (2) > 2100 parameter limit
    // (3) Using expressions for group by with parameters are not detected as equal.
    // This options are not applied by default, they are just stored in the connection
    // options and applied when needed. See {Statement} class.
    // The security of parameterized queries is not in effect when you use PDO::ATTR_EMULATE_PREPARES => true.
    // Your application should ensure that the data that is bound to the parameter(s) does not contain malicious
    // Transact-SQL code.

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

    // Actually instantiate the PDO.
    $pdo = new \PDO($dsn, $connection_options['username'], $connection_options['password'], $connection_options['pdo']);

    $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

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
   *   https://www.drupal.org/node/2345451
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
    return parent::prepare($query, $options);
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
        $stmt = $this->prepareQuery($query);
        $insecure = isset($options['insecure']) ? $options['insecure'] : FALSE;
        // Try to detect duplicate place holders, this check's performance
        // is not a good addition to the driver, but does a good job preventing
        // duplicate placeholder errors.
        $argcount = count($args);
        if ($insecure === TRUE || $argcount >= 2100 || ($argcount != substr_count($query, ':'))) {
          $stmt->RequireInsecure();
        }
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
          return;
        default:
          throw new \PDOException('Invalid return directive: ' . $options['return']);
      }
    }
    catch (\PDOException $e) {
      if ($options['throw_exception']) {
        // Wrap the exception in another exception, because PHP does not allow
        // overriding Exception::getMessage(). Its message is the extra database
        // debug information.
        $query_string = ($query instanceof StatementInterface) ? $stmt->getQueryString() : $query;
        $message = $e->getMessage() . ": " . $query_string . "; " . print_r($args, TRUE);
        // Match all SQLSTATE 23xxx errors.
        if (substr($e->getCode(), -6, -3) == '23') {
          $exception = new IntegrityConstraintViolationException($message, $e->getCode(), $e);
        }
        else {
          $exception = new DatabaseExceptionWrapper($message, 0, $e);
        }

        throw $exception;
      }
      return NULL;
    }
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
      return $this->quoteIdentifier($matches[1]);
    }
    // Let other value passthru.
    // by the logic of the regex above, this will always be the last match.
    return end($matches);
  }

  public function quoteIdentifier($identifier) {
    // Only quote reserved keywords. Strpos faster than regex.
    // We could quote all without looking for reserved
    // but that won't allow us to pass some core tests.
    $is_reserved = stripos(sqlsrv_RESERVED_KEYWORDS, "\r\n" . $identifier . "\r\n");
    if ($is_reserved !== FALSE) {
      return '[' . $identifier .']';
    }
    else {
      return $identifier;
    }
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
    $query = preg_replace(array_keys($replacements), $replacements, $query);

    $functions = $this->schema()->DrupalSpecificFunctions();
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
        $this->query('SET IDENTITY_INSERT {sequences} ON; INSERT INTO {sequences} (value) VALUES(:existing); SET IDENTITY_INSERT {sequences} OFF', array(':existing' => $existing));
      }
      catch (DatabaseException $e) {
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
    $result = ($table[0] == '#' ? '#' : '') . preg_replace('/[^A-Za-z0-9_.]+/', '', $table);
    return $result;
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

    try {
      // Create the database and set it as active.
      $this->connection->exec("CREATE DATABASE $database COLLATE " . Schema::DEFAULT_COLLATION_CI);
    }
    catch (DatabaseException $e) {
      throw new DatabaseNotFoundException($e->getMessage());
    }
  }
}

/**
 * @} End of "addtogroup database".
 */

/**
 * List of reserved keywords for query escaping.
 */
const sqlsrv_RESERVED_KEYWORDS = <<< EOF

ADD
ALL
ALTER
AND
ANY
AS
ASC
AUTHORIZATION
BACKUP
BEGIN
BETWEEN
BREAK
BROWSE
BULK
BY
CASCADE
CASE
CHECK
CHECKPOINT
CLOSE
CLUSTERED
COALESCE
COLLATE
COLUMN
COMMIT
COMPUTE
CONSTRAINT
CONTAINS
CONTAINSTABLE
CONTINUE
CONVERT
CREATE
CROSS
CURRENT
CURRENT_DATE
CURRENT_TIME
CURRENT_TIMESTAMP
CURRENT_USER
CURSOR
DATABASE
DBCC
DEALLOCATE
DECLARE
DEFAULT
DELETE
DENY
DESC
DISK
DISTINCT
DISTRIBUTED
DOUBLE
DROP
DUMMY
DUMP
ELSE
END
ERRLVL
ESCAPE
EXCEPT
EXEC
EXECUTE
EXISTS
EXIT
EXTERNAL
FETCH
FILE
FILLFACTOR
FOR
FOREIGN
FREETEXT
FREETEXTTABLE
FROM
FULL
FUNCTION
GOTO
GRANT
GROUP
HAVING
HOLDLOCK
IDENTITY
IDENTITY_INSERT
IDENTITYCOL
IF
IN
INDEX
INNER
INSERT
INTERSECT
INTO
IS
JOIN
KEY
KILL
LEFT
LIKE
LINENO
LOAD
MERGE
NATIONAL
NOCHECK
NONCLUSTERED
NOT
NULL
NULLIF
OF
OFF
OFFSETS
ON
OPEN
OPENDATASOURCE
OPENQUERY
OPENROWSET
OPENXML
OPTION
OR
ORDER
OUTER
OVER
PERCENT
PIVOT
PLAN
PRECISION
PRIMARY
PRINT
PROC
PROCEDURE
PUBLIC
RAISERROR
READ
READTEXT
RECONFIGURE
REFERENCES
REPLICATION
RESTORE
RESTRICT
RETURN
REVERT
REVOKE
RIGHT
ROLLBACK
ROWCOUNT
ROWGUIDCOL
RULE
SAVE
SCHEMA
SECURITYAUDIT
SELECT
SEMANTICKEYPHRASETABLE
SEMANTICSIMILARITYDETAILSTABLE
SEMANTICSIMILARITYTABLE
SESSION_USER
SET
SETUSER
SHUTDOWN
SOME
STATISTICS
SYSTEM_USER
TABLE
TABLESAMPLE
TEXTSIZE
THEN
TO
TOP
TRAN
TRANSACTION
TRIGGER
TRUNCATE
TRY_CONVERT
TSEQUAL
UNION
UNIQUE
UNPIVOT
UPDATE
UPDATETEXT
USE
USER
VALUES
VARYING
VIEW
WAITFOR
WHEN
WHERE
WHILE
WITH
WITHIN GROUP
WRITETEXT
ABSOLUTE
ACTION
ADA
ALLOCATE
ARE
ASSERTION
AT
AVG
BIT
BIT_LENGTH
BOTH
CASCADED
CAST
CATALOG
CHAR
CHAR_LENGTH
CHARACTER
CHARACTER_LENGTH
COLLATION
CONNECT
CONNECTION
CONSTRAINTS
CORRESPONDING
COUNT
DATE
DAY
DEC
DECIMAL
DEFERRABLE
DEFERRED
DESCRIBE
DESCRIPTOR
DIAGNOSTICS
DISCONNECT
DOMAIN
END-EXEC
EXCEPTION
EXTRACT
FALSE
FIRST
FLOAT
FORTRAN
FOUND
GET
GLOBAL
GO
HOUR
IMMEDIATE
INCLUDE
INDICATOR
INITIALLY
INPUT
INSENSITIVE
INT
INTEGER
INTERVAL
ISOLATION
LANGUAGE
LAST
LEADING
LEVEL
LOCAL
LOWER
MATCH
MAX
MIN
MINUTE
MODULE
MONTH
NAMES
NATURAL
NCHAR
NEXT
NO
NONE
NUMERIC
OCTET_LENGTH
ONLY
OUTPUT
OVERLAPS
PAD
PARTIAL
PASCAL
POSITION
PREPARE
PRESERVE
PRIOR
PRIVILEGES
REAL
RELATIVE
ROWS
SCROLL
SECOND
SECTION
SESSION
SIZE
SMALLINT
SPACE
SQL
SQLCA
SQLCODE
SQLERROR
SQLSTATE
SQLWARNING
SUBSTRING
SUM
TEMPORARY
TIME
TIMESTAMP
TIMEZONE_HOUR
TIMEZONE_MINUTE
TRAILING
TRANSLATE
TRANSLATION
TRIM
TRUE
UNKNOWN
UPPER
USAGE
USING
VALUE
VARCHAR
WHENEVER
WORK
WRITE
YEAR
ZONE
ADMIN
AFTER
AGGREGATE
ALIAS
ARRAY
ASENSITIVE
ASYMMETRIC
ATOMIC
BEFORE
BINARY
BLOB
BOOLEAN
BREADTH
CALL
CALLED
CARDINALITY
CLASS
CLOB
COLLECT
COMPLETION
CONDITION
CONSTRUCTOR
CORR
COVAR_POP
COVAR_SAMP
CUBE
CUME_DIST
CURRENT_CATALOG
CURRENT_DEFAULT_TRANSFORM_GROUP
CURRENT_PATH
CURRENT_ROLE
CURRENT_SCHEMA
CURRENT_TRANSFORM_GROUP_FOR_TYPE
CYCLE
DATA
DEPTH
DEREF
DESTROY
DESTRUCTOR
DETERMINISTIC
DICTIONARY
DYNAMIC
EACH
ELEMENT
EQUALS
EVERY
FILTER
FREE
FULLTEXTTABLE
FUSION
GENERAL
GROUPING
HOLD
HOST
IGNORE
INITIALIZE
INOUT
INTERSECTION
ITERATE
LARGE
LATERAL
LESS
LIKE_REGEX
LIMIT
LN
LOCALTIME
LOCALTIMESTAMP
LOCATOR
MAP
MEMBER
METHOD
MOD
MODIFIES
MODIFY
MULTISET
NCLOB
NEW
NORMALIZE
OBJECT
OCCURRENCES_REGEX
OLD
OPERATION
ORDINALITY
OUT
OVERLAY
PARAMETER
PARAMETERS
PARTITION
PATH
POSTFIX
PREFIX
PREORDER
PERCENT_RANK
PERCENTILE_CONT
PERCENTILE_DISC
POSITION_REGEX
RANGE
READS
RECURSIVE
REF
REFERENCING
REGR_AVGX
REGR_AVGY
REGR_COUNT
REGR_INTERCEPT
REGR_R2
REGR_SLOPE
REGR_SXX
REGR_SXY
REGR_SYY
RELEASE
RESULT
RETURNS
ROLE
ROLLUP
ROUTINE
ROW
SAVEPOINT
SCOPE
SEARCH
SENSITIVE
SEQUENCE
SETS
SIMILAR
SPECIFIC
SPECIFICTYPE
SQLEXCEPTION
START
STATE
STATEMENT
STATIC
STDDEV_POP
STDDEV_SAMP
STRUCTURE
SUBMULTISET
SUBSTRING_REGEX
SYMMETRIC
SYSTEM
TERMINATE
THAN
TRANSLATE_REGEX
TREAT
UESCAPE
UNDER
UNNEST
VAR_POP
VAR_SAMP
VARIABLE
WIDTH_BUCKET
WITHOUT
WINDOW
WITHIN
XMLAGG
XMLATTRIBUTES
XMLBINARY
XMLCAST
XMLCOMMENT
XMLCONCAT
XMLDOCUMENT
XMLELEMENT
XMLEXISTS
XMLFOREST
XMLITERATE
XMLNAMESPACES
XMLPARSE
XMLPI
XMLQUERY
XMLSERIALIZE
XMLTABLE
XMLTEXT
XMLVALIDATE

EOF
;
