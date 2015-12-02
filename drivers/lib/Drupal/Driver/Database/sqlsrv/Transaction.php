<?php
/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Transaction
 */
namespace Drupal\Driver\Database\sqlsrv;
use Drupal\Core\Database\Transaction as DatabaseTransaction;
use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\TransactionCommitFailedException as DatabaseTransactionCommitFailedException;
use Drupal\Core\Database\TransactionExplicitCommitNotAllowedException as DatabaseTransactionExplicitCommitNotAllowedException;
use mssql\Settings\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use mssql\Settings\TransactionScopeOption as DatabaseTransactionScopeOption;
use Drupal\Driver\Database\sqlsrv\TransactionSettings as DatabaseTransactionSettings;
use PDO as PDO;
use Exception as Exception;
use PDOStatement as PDOStatement;
class Transaction extends DatabaseTransaction {
  /**
   * A boolean value to indicate whether this transaction has been commited.
   *
   * @var Boolean
   */
  protected $commited = FALSE;
  /**
   * A boolean to indicate if the transaction scope should behave sanely.
   *
   * @var DatabaseTransactionSettings
   */
  protected $settings = FALSE;
  /**
   * Overriden to add settings.
   *
   * @param Connection $connection
   *
   * @param string $name
   *
   * @param DatabaseTransactionSettings $settings
   */
  public function __construct(Connection $connection, $name = NULL, DatabaseTransactionSettings $settings = NULL) {
    // Store connection and settings.
    $this->settings = $settings;
    $this->connection = $connection;
    // If there is no transaction depth, then no transaction has started. Name
    // the transaction 'drupal_transaction'.
    if (!$depth = $connection->transactionDepth()) {
      $this->name = 'drupal_transaction';
    }
    // Within transactions, savepoints are used. Each savepoint requires a
    // name. So if no name is present we need to create one.
    elseif (empty($name)) {
      $this->name = 'savepoint_' . $depth;
    }
    else {
      $this->name = $name;
    }
    $this->connection->pushTransaction($this->name, $settings);
  }
  /**
   * Overriden __destruct to provide some mental health (sane support).
   */
  public function __destruct() {
    if (!$this->settings->Get_Sane()) {
      // If we rolled back then the transaction would have already been popped.
      if (!$this->rolledBack) {
        $this->connection->popTransaction($this->name);
      }
    }
    else {
      // If we did not commit and did not rollback explicitly, rollback.
      // Rollbacks are not usually called explicitly by the user
      // but that could happen.
      if (!$this->commited && !$this->rolledBack) {
        $this->rollback();
      }
    }
  }
  /**
   * Commits the transaction. Only available for SANE transactions.
   *
   * @throws DatabaseTransactionExplicitCommitNotAllowedException
   */
  public function commit() {
    // Insane transaction behaviour does not allow explicit commits.
    if (!$this->settings->Get_Sane()) {
      throw new DatabaseTransactionExplicitCommitNotAllowedException();
    }
    // Cannot commit a rolledback transaction...
    if ($this->rolledBack) {
      throw new DatabaseTransactionCannotCommitAfterRollbackException();
    }
    // Mark as commited, and commit!
    $this->commited = TRUE;
    // Finally pop it!
    $this->connection->popTransaction($this->name);
  }
  /**
   * {@inheritdoc}
   */
  public function rollback() {
    $this->rolledBack = TRUE;
    $this->connection->rollback($this->name);
  }
}