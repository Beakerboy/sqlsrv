<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Database;
use Drupal\Driver\Database\sqlsrv\DriverSettings;

/**
 * Defines a behaviour scope for the database
 * driver that lasts until the object is destroyed.
 */
class Context {

  /**
   * Conection that this context is applied to.
   *
   * @var Connection
   */
  var $connection;

  /**
   * Settings before establishing this context.
   *
   * @var DriverSettings
   */
  var $settings = NULL;

  /**
   * Define the behaviour of the database driver during the scope of the
   * life of this instance.
   *
   * @param Connection $connection
   *
   *  Instance of the connection to be configured. Leave null to use the
   *  current default connection.
   *
   * @param mixed $bypass_queries
   *
   *  Do not preprocess the query before execution.
   *
   * @param mixed $direct_query
   *
   *  Prepare statements with SQLSRV_ATTR_DIRECT_QUERY = TRUE.
   *
   * @param mixed $statement_caching
   *
   *  Enable prepared statement caching. Cached statements are reused even
   *  after the context has expired.
   *
   */
  public function __construct(Connection $connection = NULL,
        $bypass_queries = NULL,
        $direct_query = NULL,
        $statement_caching = NULL) {

    // Retain a copy of the setting and connections.
    $this->connection = $connection ? $connection : Database::getConnection();
    $this->settings = $this->connection->driver_settings;

    // Override our custom settings.
    $configuration = $this->settings->exportConfiguration();

    if ($bypass_queries !== NULL) {
      $configuration['default_bypass_query_preprocess'] = $bypass_queries;
    }

    if ($direct_query !== NULL) {
      $configuration['default_direct_queries'] = $direct_query;
    }

    if ($statement_caching !== NULL) {
      $configuration['statement_caching_mode'] = $statement_caching;
    }

    $settings = DriverSettings::instanceFromData($configuration);
    $this->connection->driver_settings = $settings;
  }

  public function __destruct() {
    // Restore previous driver configuration.
    $this->connection->driver_settings = $this->settings;
  }
}