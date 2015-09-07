<?php

namespace Drupal\Driver\Database\sqlsrv;

use \Drupal\Core\Site\Settings;

use \PDO as PDO;

/**
 * Global settings for the driver.
 */
class DriverSettings {

  private $_defaultIsolationLevel;

  private $_defaultDirectQueries;

  private $_defaultBypassQueryPreprocess;

  private $_defaultStatementCaching;

  private $_useNativeUpsert;

  private $_useNativeMerge;

  private $_statementCachingMode;

  private $_appendStackComments;

  private $_enableTransactions;

  private $_monitorDriverStatus;

  /**
   * Default settings for the dabase driver.
   *
   * @var array
   */
  private static $default_driver_settings = array(
        'default_isolation_level' => FALSE,
        'default_direct_queries' => FALSE,
        'default_statement_caching' => FALSE,
        'use_native_upsert' => FALSE,
        'use_native_merge' => FALSE,
        'statement_caching_mode' => 'disabled',
        'append_stack_comments' => FALSE,
        'default_bypass_query_preprocess' => FALSE,
        'enable_transactions' => TRUE,
        'monitor_driver_status' => TRUE,
      );

  /**
   * Checks for a valid setting in the list of allowed values.
   *
   * @param mixed $value
   * @param mixed $value
   * @param array $allowed
   */
  private function CheckValid($name, $value, array $allowed) {
    if (!in_array($value, $allowed)) {
      throw new \Exception("Invalid driver setting for $name");
    }
    return $value;
  }

  /**
   * Builds a DriverSettings instance from the application settings.php file.
   *
   * @return DriverSettings
   */
  public static function instanceFromSettings() {
    $configuration = Settings::get('mssql_configuration', array());
    $configuration = array_merge(static::$default_driver_settings, $configuration);
    return new DriverSettings($configuration);
  }

  /**
   * Builds a DriverSettings instance from custom settings. Missing settings are merged
   * from the application settings.
   *
   * @param mixed $configuration
   */
  public static function instanceFromData($configuration) {
    $configuration = array_merge(static::$default_driver_settings, $configuration);
    return new DriverSettings($configuration);
  }

  /**
   * Construct an instance of DriverSettings.
   */
  private function __construct($configuration) {

    $this->_defaultIsolationLevel = $this->CheckValid('default_isolation_level', $configuration['default_isolation_level'], array(
        FALSE,
        PDO::SQLSRV_TXN_READ_UNCOMMITTED,
        PDO::SQLSRV_TXN_READ_COMMITTED,
        PDO::SQLSRV_TXN_REPEATABLE_READ,
        PDO::SQLSRV_TXN_SNAPSHOT,
        PDO::SQLSRV_TXN_SERIALIZABLE,
      ));

    $this->_defaultDirectQueries = $this->CheckValid('default_direct_queries', $configuration['default_direct_queries'], array(TRUE, FALSE));
    $this->_defaultStatementCaching = $this->CheckValid('default_statement_caching', $configuration['default_statement_caching'], array(TRUE, FALSE));
    $this->_defaultBypassQueryPreprocess = $this->CheckValid('default_bypass_query_preprocess', $configuration['default_bypass_query_preprocess'], array(TRUE, FALSE));
    $this->_useNativeUpsert = $this->CheckValid('use_native_upsert', $configuration['use_native_upsert'], array(TRUE, FALSE));
    $this->_useNativeMerge =$this->CheckValid('use_native_merge', $configuration['use_native_merge'], array(TRUE, FALSE));
    $this->_statementCachingMode =$this->CheckValid('statement_caching_mode', $configuration['statement_caching_mode'], array('disabled', 'on-demand', 'always'));
    $this->_appendStackComments =$this->CheckValid('append_stack_comments', $configuration['append_stack_comments'], array(TRUE, FALSE));
    $this->_enableTransactions =$this->CheckValid('enable_transactions', $configuration['enable_transactions'], array(TRUE, FALSE));
    $this->_monitorDriverStatus =$this->CheckValid('monitor_driver_status', $configuration['monitor_driver_status'], array(TRUE, FALSE));
  }

  /**
   * Export current driver configuration.
   *
   * @return array
   */
  public function exportConfiguration() {
    return array(
        'default_isolation_level' => $this->GetDefaultIsolationLevel(),
        'default_direct_queries' => $this->GetDefaultDirectQueries(),
        'use_native_upsert' => $this->GetUseNativeUpsert(),
        'use_native_merge' => $this->GetUseNativeMerge(),
        'statement_caching_mode' => $this->GetStatementCachingMode(),
        'append_stack_comments' => $this->GetAppendCallstackComment(),
        'default_bypass_query_preprocess' => $this->GetDeafultBypassQueryPreprocess(),
        'default_statement_caching' => $this->GetDeafultStatementCaching(),
        'monitorDriverStatus' => $this->GetMonitorDriverStatus(),
      );
  }

  public function GetMonitorDriverStatus() {
    return $this->_monitorDriverStatus;
  }

  /**
   * Completely disable transaction management
   * at the driver leve. The MSSQL PDO has some issues
   * that show up during testing so we need to diable
   * transactions to be able to run some tests...
   *
   * @see https://github.com/Azure/msphpsql/issues/49
   */
  public function GetEnableTransactions() {
    return $this->_enableTransactions;
  }

  /**
   * Isolation level used for implicit transactions.
   */
  public function GetDefaultIsolationLevel() {
    return $this->_defaultIsolationLevel;
  }

  /**
   * PDO Constant names do not match 1-to-1 the transaction names that
   * need to be used in SQL.
   *
   * @return mixed
   */
  public function GetDefaultTransactionIsolationLevelInStatement() {
    return str_replace('_', ' ', $this->GetDefaultIsolationLevel());
  }

  /**
   * Default query preprocess.
   *
   * @return mixed
   */
  public function GetDeafultStatementCaching() {
    return $this->_defaultStatementCaching;
  }

  /**
   * Default query preprocess.
   *
   * @return mixed
   */
  public function GetDeafultBypassQueryPreprocess() {
    return $this->_defaultBypassQueryPreprocess;
  }

  /**
   * Wether to run all statements in direct query mode by default.
   */
  public function GetDefaultDirectQueries() {
    return $this->_defaultDirectQueries;
  }

  /**
   * Wether to use or not the native upsert implementation.
   */
  public function GetUseNativeUpsert() {
    return $this->_useNativeUpsert;
  }

  /**
   * Wether to user or not the native merge implementaiton.
   */
  public function GetUseNativeMerge()  {
    return $this->_useNativeMerge;
  }

  /**
   * Enable appending of PHP stack as query comments.
   */
  public function GetAppendCallstackComment() {
    return $this->_appendStackComments;
  }

  /**
   * Experimental statement caching for PDO prepared statement
   * reuse.
   *
   * 'disabled' => Never use statement caching.
   * 'on-demand' => Only use statement caching when implicitly set in a Context.
   * 'always' => Always use statement caching.
   *
   */
  public function GetStatementCachingMode() {
    return $this->_statementCachingMode;
  }
}