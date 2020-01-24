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
   * @param mixed $sane
   *   Sane.
   * @param \Drupal\Driver\Database\sqlsrv\TransactionScopeOption $scopeOption
   *   Scope options.
   * @param \Drupal\Driver\Database\sqlsrv\TransactionIsolationLevel $isolationLevel
   *   Isolation level.
   */
  public function __construct($sane = FALSE,
      DatabaseTransactionScopeOption $scopeOption = NULL,
      DatabaseTransactionIsolationLevel $isolationLevel = NULL) {
    $this->sane = $sane;
    if ($scopeOption == NULL) {
      $scopeOption = DatabaseTransactionScopeOption::RequiresNew();
    }
    if ($isolationLevel == NULL) {
      $isolationLevel = DatabaseTransactionIsolationLevel::Unspecified();
    }
    $this->isolationLevel = $isolationLevel;
    $this->scopeOption = $scopeOption;
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
   *   Isolation level.
   */
  public function Get_IsolationLevel() {
    return $this->isolationLevel;
  }

  /**
   * Summary of Get_ScopeOption.
   *
   * @return mixed
   *   Scope option.
   */
  public function Get_ScopeOption() {
    return $this->scopeOption;
  }

  /**
   * Summary of Get_Sane.
   *
   * @return mixed
   *   Sane.
   */
  public function Get_Sane() {
    return $this->sane;
  }

  /**
   * Returns a default setting system-wide.
   *
   * @return TransactionSettings
   *   Default settings.
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
   *   Better defaults.
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
   *   Compatible defaults.
   */
  public static function getDdlCompatibleDefaults() {
    return new TransactionSettings(TRUE,
                DatabaseTransactionScopeOption::Required(),
                DatabaseTransactionIsolationLevel::ReadCommitted());
  }

}
