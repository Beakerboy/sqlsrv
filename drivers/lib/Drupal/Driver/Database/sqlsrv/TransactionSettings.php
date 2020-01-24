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
   * Isolation level.
   *
   * @var \Drupal\Driver\Database\sqlsrv\TransactionIsolationLevel
   */
  private $isolationLevel;

  /**
   * Scope option.
   *
   * @var \Drupal\Driver\Database\sqlsrv\TransactionScopeOption
   */
  private $scopeOption;

  /**
   * Sane.
   *
   * @var bool
   */
  private $sane;

  /**
   * Summary of Get_IsolationLevel.
   *
   * @return mixed
   *   Isolation level.
   */
  public function getIsolationLevel() {
    return $this->isolationLevel;
  }

  /**
   * Summary of Get_ScopeOption.
   *
   * @return mixed
   *   Scope option.
   */
  public function getScopeOption() {
    return $this->scopeOption;
  }

  //phpcs:disable
  public function Get_Sane() {
    return $this->getSane();
  }

  public function Get_IsolationLevel() {
    return $this->getIsolationLevel();
  }

  public function Get_ScopeOption() {
    return $this->getScopeOption();
  }
  //phpcs:enable

  /**
   * Summary of Get_Sane.
   *
   * @return mixed
   *   Sane.
   */
  public function getSane() {
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
