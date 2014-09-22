<?php

/**
 * @file
 * Definition of Drupal\Core\Database\Driver\sqlsrv\Truncate
 */

namespace Drupal\Core\Database\Driver\sqlsrv;

use Drupal\Core\Database\Query\Truncate as QueryTruncate;

class Truncate extends QueryTruncate { 
  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    return $prefix . 'TRUNCATE TABLE {' . $this->connection->escapeTable($this->table) . '} ';
  }
}
