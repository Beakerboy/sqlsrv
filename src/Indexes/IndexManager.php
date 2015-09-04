<?php

namespace Drupal\sqlsrv\Indexes;

use Drupal\Driver\Database\sqlsrv\Connection;

/**
 * Default indexes to be deployed for CORE functionality.
 */
class IndexManager {

  /**
   * Summary of $connection
   * 
   * @var Connection
   */
  private $connection;

  /**
   * Creates an instance of DefaultIndexes with a all defined indexes.
   * 
   * @param Connection $connection 
   */
  public function __construct(Connection $connection) {
    $this->connection = $connection;
  }

  /**
   * Deploy all missing indexes.
   * 
   * @throws \Exception 
   * 
   * @return void
   */
  public function DeployNew() {

    // Scan the Implementations folder
    $dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . 'Implementations';
    $files = file_scan_directory($dir ,'/.*\.sql$/');

    foreach ($files as $file) {

      $index = new Index($file->uri);

      $schema = $this->connection->schema();

      if (!$schema->_ExistsIndex($index->GetTable(), $index->GetName())) {
        try {
          // TODO: Consider the need to prefix the tables...
          $this->connection->query($index->GetCode());
        }
        catch (\Exception $e) {
          \Drupal::logger('MSSQL')->notice("Could not deploy index {$index->GetName()} for table {$index->GetTable()}");
        }
      }
    }

  }

}
