<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Driver\Database\sqlsrv\TransactionIsolationLevel as DatabaseTransactionIsolationLevel;
use Drupal\Driver\Database\sqlsrv\TransactionScopeOption as DatabaseTransactionScopeOption;

/**
 * Behaviour settings for a transaction.
 */
class TransactionSettings {

  /**
   * Summary of __construct.
   *
   * @param mixed $Sane
   * @param \Drupal\Driver\Database\sqlsrv\TransactionScopeOption $ScopeOption
   * @param \Drupal\Driver\Database\sqlsrv\TransactionIsolationLevel $IsolationLevel
   */
  public function __construct($Sane = FALSE,
      DatabaseTransactionScopeOption $ScopeOption = NULL,
      DatabaseTransactionIsolationLevel $IsolationLevel = NULL) {
    $this->sane = $Sane;
    if ($ScopeOption == NULL) {
      $ScopeOption = DatabaseTransactionScopeOption::RequiresNew();
    }
    if ($IsolationLevel == NULL) {
      $IsolationLevel = DatabaseTransactionIsolationLevel::Unspecified();
    }
    $this->isolationLevel = $IsolationLevel;
    $this->scopeOption = $ScopeOption;
  }

  /**
   * @var \Drupal\Driver\Database\sqlsrv\TransactionIsolationLevel
   */
  private $isolationLevel;

  /**
   * @var \Drupal\Driver\Database\sqlsrv\TransactionScopeOption
   */
  private $scopeOption;

  /**
   * @var bool
   */
  private $sane;

  /**
   * Summary of Get_IsolationLevel.
   *
   * @return mixed
   */
  public function Get_IsolationLevel() {
    return $this->isolationLevel;
  }

  /**
   * Summary of Get_ScopeOption.
   *
   * @return mixed
   */
  public function Get_ScopeOption() {
    return $this->scopeOption;
  }

  /**
   * Summary of Get_Sane.
   *
   * @return mixed
   */
  public function Get_Sane() {
    return $this->sane;
  }

  /**
   * Returns a default setting system-wide.
   *
   * @return TransactionSettings
   */
  public static function getDefaults() {
    // Use snapshot if available.
    $isolation = DatabaseTransactionIsolationLevel::Ignore();
    // Otherwise use Drupal's default behaviour (except for nesting!)
    return new TransactionSettings(FALSE,
                DatabaseTransactionScopeOption::Required(),
                $isolation);
  }

  /**
   * Proposed better defaults.
   *
   * @return TransactionSettings
   */
  public static function getBetterDefaults() {
    // Use snapshot if available.
    $isolation = DatabaseTransactionIsolationLevel::Ignore();
    // Otherwise use Drupal's default behaviour (except for nesting!)
    return new TransactionSettings(TRUE,
                DatabaseTransactionScopeOption::Required(),
                $isolation);
  }

  /**
   * Snapshot isolation is not compatible with DDL operations.
   *
   * @return TransactionSettings
   */
  public static function getDdlCompatibleDefaults() {
    return new TransactionSettings(TRUE,
                DatabaseTransactionScopeOption::Required(),
                DatabaseTransactionIsolationLevel::ReadCommitted());
  }

}
