<?php
/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Truncate
 */
namespace Drupal\Driver\Database\sqlsrv;
use Drupal\Core\Database\Query\Truncate as QueryTruncate;
class Truncate extends QueryTruncate {
  /**
   * {@inheritdoc}
   */
  public function execute() {
    return $this->connection->query((string) $this, [], $this->queryOptions);
  }
  /**
   * {@inheritdoc}
   */
  public function __toString() {
    // Create a sanitized comment string to prepend to the query.
    $comments = $this->connection->makeComment($this->comments);
    // In most cases, TRUNCATE is not a transaction safe statement as it is a
    // DDL statement which results in an implicit COMMIT. When we are in a
    // transaction, fallback to the slower, but transactional, DELETE.
    // PostgreSQL also locks the entire table for a TRUNCATE strongly reducing
    // the concurrency with other transactions.
    if ($this->connection->inTransaction()) {
      return $comments . "DELETE FROM {{$this->connection->escapeTable($this->table)}}";
    }
    else {
      return $comments . "TRUNCATE TABLE {{$this->connection->escapeTable($this->table)}}";
    }
  }
}