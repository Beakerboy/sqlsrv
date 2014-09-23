<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Truncate
 */

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Query\Truncate as QueryTruncate;

class Truncate extends QueryTruncate { 
  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $prefix = $this->connection->makeComment($this->comments);

    return $prefix . 'TRUNCATE TABLE {' . $this->connection->escapeTable($this->table) . '} ';
  }
}
