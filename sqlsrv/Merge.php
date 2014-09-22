<?php

/**
 * @file
 * Definition of Drupal\Core\Database\Driver\sqlsrv\Merge
 */

namespace Drupal\Core\Database\Driver\sqlsrv;

use Drupal\Core\Database\Query\Merge as QueryMerge;

class Merge extends QueryMerge { 
  public function execute() {
    // We don't need INSERT or UPDATE queries to trigger additional transactions.
    $this->queryOptions['sqlsrv_skip_transactions'] = TRUE;

    return parent::execute();
  }
}
