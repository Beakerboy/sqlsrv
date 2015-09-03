<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Statement
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\StatementPrefetch;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\Database\Statement as DatabaseStatement;

use PDO as PDO;
use PDOException as PDOException;
use PDOStatement as PDOStatement;

class Statement extends DatabaseStatement implements StatementInterface {

  protected function __construct(Connection $dbh) {
    $this->allowRowCount = TRUE;
    parent::__construct($dbh);
  }

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
    $query_start = microtime(TRUE);

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
        case 'nvarchar':
        case 'varchar':
          $null[$i] = NULL;
          $this->bindColumn($i + 1, $null[$i], PDO::PARAM_STR, 0, PDO::SQLSRV_ENCODING_UTF8);
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
   * @return array|Statement
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
    // If we are asked for the default behaviour, rely
    // on the PDO as being faster. The result set needs to exactly bee 2 columns.
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