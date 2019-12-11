<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Merge as QueryMerge;

/**
 *
 */
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
