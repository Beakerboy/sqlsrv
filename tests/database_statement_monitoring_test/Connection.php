<?php

namespace Drupal\database_statement_monitoring_test\sqlsrv;

use Drupal\Core\Database\Driver\mysql\Connection as BaseConnection;
use Drupal\database_statement_monitoring_test\LoggedStatementsTrait;

/**
 * SqlSrv Connection class that can log executed queries.
 */
class Connection extends BaseConnection {
  use LoggedStatementsTrait;
}
