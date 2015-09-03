<?php

/**
 * @file
 * Contains \Drupal\Driver\Database\sqlsrv\DatabaseTransactionCannotCommitAfterRollbackException.
 */

namespace Drupal\Driver\Database\sqlsrv;

use \Drupal\Core\Database\TransactionException;
use \Drupal\Core\Database\DatabaseException;

/**
 * Exception to deny attempts to explicitly manage transactions.
 *
 * This exception will be thrown when the PDO connection commit() is called.
 * Code should never call this method directly.
 */
class DatabaseTransactionCannotCommitAfterRollbackException extends TransactionException implements DatabaseException { }