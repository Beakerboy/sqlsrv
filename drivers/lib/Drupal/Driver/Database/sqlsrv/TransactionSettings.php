<?php

namespace Drupal\Driver\Database\sqlsrv;

use mssql\Settings\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use mssql\Settings\TransactionScopeOption as DatabaseTransactionScopeOption;

use Drupal\Core\Database\Database;

/**
 * Behaviour settings for a transaction.
 */
class TransactionSettings {

  /**
   * Summary of __construct
   * @param mixed $Sane
   * @param DatabaseTransactionScopeOption $ScopeOption
   * @param DatabaseTransactionIsolationLevel $IsolationLevel
   */
  public function __construct($Sane = FALSE,
      DatabaseTransactionScopeOption $ScopeOption = NULL,
      DatabaseTransactionIsolationLevel $IsolationLevel = NULL) {
    $this->_Sane = $Sane;
    if ($ScopeOption == NULL) {
      $ScopeOption = DatabaseTransactionScopeOption::RequiresNew();
    }
    if ($IsolationLevel == NULL) {
      $IsolationLevel = DatabaseTransactionIsolationLevel::Unspecified();
    }
    $this->_IsolationLevel = $IsolationLevel;
    $this->_ScopeOption = $ScopeOption;
  }

  // @var DatabaseTransactionIsolationLevel
  private $_IsolationLevel;

  // @var DatabaseTransactionScopeOption
  private $_ScopeOption;

  // @var Boolean
  private $_Sane;

  /**
   * Summary of Get_IsolationLevel
   * @return mixed
   */
  public function Get_IsolationLevel() {
    return $this->_IsolationLevel;
  }

  /**
   * Summary of Get_ScopeOption
   * @return mixed
   */
  public function Get_ScopeOption() {
    return $this->_ScopeOption;
  }

  /**
   * Summary of Get_Sane
   * @return mixed
   */
  public function Get_Sane() {
    return $this->_Sane;
  }

  /**
   * Returns a default setting system-wide to make it compatible
   * with Drupal's defaults. Cannot use snapshot isolation because
   * it is not compatible with DDL operations and Drupal has nod distinction.
   *
   * @return TransactionSettings
   */
  public static function GetDefaults() {
    return new TransactionSettings(FALSE,
                DatabaseTransactionScopeOption::Required(),
                DatabaseTransactionIsolationLevel::ReadCommitted());
  }

  /**
   * Proposed better defaults. Use Snapshot isolation when available and
   * implicit commits.
   *
   * @return TransactionSettings
   */
  public static function GetBetterDefaults() {
    // Use snapshot if available.
    $isolation = DatabaseTransactionIsolationLevel::Ignore();
    /** @var Connection */
    $connection = Database::getConnection();
    if ($info = $connection->Scheme()->getDatabaseInfo($connection->getDatabaseName())) {
      if ($info->snapshot_isolation_state == TRUE) {
        $isolation = DatabaseTransactionIsolationLevel::Snapshot();
      }
    }
    // Otherwise use Drupal's default behaviour (except for nesting!)
    return new TransactionSettings(TRUE,
                DatabaseTransactionScopeOption::Required(),
                $isolation);
  }

  /**
   * Snapshot isolation is not compatible with DDL operations, use read commited
   * with implicit commits.
   *
   * @return TransactionSettings
   */
  public static function GetDDLCompatibleDefaults() {
    return new TransactionSettings(TRUE,
                DatabaseTransactionScopeOption::Required(),
                DatabaseTransactionIsolationLevel::ReadCommitted());
  }
}