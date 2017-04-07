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
use Drupal\Driver\Database\sqlsrv\Utils;
use PDO as PDO;
use PDOException as PDOException;
use PDOStatement as PDOStatement;
class Statement extends StatementBase implements StatementInterface  {
  protected function __construct(Connection $dbh) {
    $this->allowRowCount = TRUE;
    parent::__construct($dbh);
  }
  /**
   * {@inheritdoc}
   */
  public function execute($args = [], $options = []) {
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
    $return = parent::execute($args);
    // Bind column types properly.
    $this->fixColumnBindings();
    if (!empty($logger)) {
      $query_end = microtime(TRUE);
      $logger->log($this, $args, $query_end - $query_start);
    }
    return $return;
  }
  /**
   * {@inhertidoc}
   */
  public function fetchAllKeyed($key_index = 0, $value_index = 1) {
    // Push this up to the good implementation.
    return \mssql\Statement::fetchAllKeyed($key_index, $value_index);
  }
}