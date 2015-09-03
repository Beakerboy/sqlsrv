<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Merge
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Merge as QueryMerge;

use Drupal\Driver\Database\sqlsrv\Utils as DatabaseUtils;

use Drupal\Driver\Database\sqlsrv\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use Drupal\Driver\Database\sqlsrv\TransactionScopeOption as DatabaseTransactionScopeOption;
use Drupal\Driver\Database\sqlsrv\TransactionSettings as DatabaseTransactionSettings;

use Drupal\Core\Database\Query\InvalidMergeQueryException;

use PDO as PDO;
use Exception as Exception;
use PDOStatement as PDOStatement;

class Merge extends QueryMerge {

  /**
   * {@inheritdoc}
   */
  public function execute() {
    // We don't need INSERT or UPDATE queries to trigger additional transactions.
    $this->queryOptions['sqlsrv_skip_transactions'] = TRUE;
    return parent::execute();
  }

}