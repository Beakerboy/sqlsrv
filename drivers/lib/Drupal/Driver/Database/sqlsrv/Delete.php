<?php
/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Delete
 */
namespace Drupal\Driver\Database\sqlsrv;
use Drupal\Core\Database\Query\Delete as QueryDelete;

class Delete extends QueryDelete {
  public function execute() {
    // Check that the table does exist.
    if (!$this->connection->schema()->tableExists($this->table)) {
      throw new \Drupal\Core\Database\SchemaObjectDoesNotExistException("Table {$this->table} does not exist.");
    }
    return parent::execute();
  }
}