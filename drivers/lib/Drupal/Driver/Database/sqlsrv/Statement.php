<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Statement
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\StatementPrefetch;
use Drupal\Core\Database\StatementInterface;

class Statement extends StatementPrefetch implements \Iterator, StatementInterface {

  // Flag to tell if statement should be run insecure.
  private $insecure = FALSE;

  // Tells the statement to set insecure parameters
  // such as SQLSRV_ATTR_DIRECT_QUERY and ATTR_EMULATE_PREPARES.
  // TODO: Should log a warning so the calling code can be looked into
  // and secured.
  public function RequireInsecure() {
    $this->insecure = TRUE;
  }

  protected function getStatement($query, &$args = array()) {
    // Time for the truth, if somebody asks for insecure,
    // let's give it them!
    $pdo_options = array();
    if ($this->insecure) {
      // We have to log this, prepared statements are a security RISK
      // \Drupal::logger('sqlsrv')->notice('Running insecure Statement: {$query}');
      $options = $this->connection->getConnectionOptions();
      // This are defined in class Connection.
      $pdo_options = $options['pdo'];
    }
    return $this->connection->PDOPrepare($query, $pdo_options);
  }

  public function execute($args = array(), $options = array()) {
    if (isset($options['fetch'])) {
      if (is_string($options['fetch'])) {
        // Default to an object. Note: db fields will be added to the object
        // before the constructor is run. If you need to assign fields after
        // the constructor is run, see http://drupal.org/node/315092.
        $this->setFetchMode(\PDO::FETCH_CLASS, $options['fetch']);
      }
      else {
        $this->setFetchMode($options['fetch']);
      }
    }

    $logger = $this->connection->getLogger();
    if (!empty($logger)) {
      $query_start = microtime(TRUE);
    }

    // Prepare the query.
    $statement = $this->getStatement($this->queryString, $args);
    if (!$statement) {
      $this->throwPDOException();
    }

    $return = $statement->execute($args);
    if (!$return) {
      $this->throwPDOException();
    }

    // Fetch all the data from the reply, in order to release any lock
    // as soon as possible.
    if ($options['return'] == Database::RETURN_AFFECTED) {
      // TODO: THIS ALLOWROWCOUNT TO TRUE SHOULD NOT BE HERE!!
      $statement->allowRowCount = TRUE;
      $this->rowCount = $statement->rowCount();
    }

    // Bind the binary columns properly.
    $null = array();
    for ($i = 0; $i < $statement->columnCount(); $i++) {
      $meta = $statement->getColumnMeta($i);
      if ($meta['sqlsrv:decl_type'] == 'varbinary') {
        $null[$i] = NULL;
        $statement->bindColumn($i + 1, $null[$i], \PDO::PARAM_LOB, 0, \PDO::SQLSRV_ENCODING_BINARY);
      }
    }

    try {
      $this->data = $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
    catch (\PDOException $e) {
      $this->data = array();
    }

    $this->resultRowCount = count($this->data);

    if ($this->resultRowCount) {
      $this->columnNames = array_keys($this->data[0]);
    }
    else {
      $this->columnNames = array();
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

    if ($dropped_columns) {
      // Renumber columns.
      $this->columnNames = array_values($this->columnNames);

      foreach ($this->data as $k => $row) {
        foreach ($dropped_columns as $column) {
          unset($this->data[$k][$column]);
        }
      }
    }

    // Destroy the statement as soon as possible.
    unset($statement);

    // Initialize the first row in $this->currentRow.
    $this->next();

    return $return;
  }

}
