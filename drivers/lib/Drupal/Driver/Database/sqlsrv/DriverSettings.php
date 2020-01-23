<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Site\Settings;

use PDO as PDO;

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
  private static $default_driver_settings = [
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
  ];

  /**
   * Checks for a valid setting in the list of allowed values.
   *
   * @param mixed $value
   * @param mixed $value
   * @param array $allowed
   */
  private function checkValid($name, $value, array $allowed) {
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
    $configuration = Settings::get('mssql_configuration', []);
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

    $this->_defaultIsolationLevel = $this->CheckValid('default_isolation_level', $configuration['default_isolation_level'], [
      FALSE,
      PDO::SQLSRV_TXN_READ_UNCOMMITTED,
      PDO::SQLSRV_TXN_READ_COMMITTED,
      PDO::SQLSRV_TXN_REPEATABLE_READ,
      PDO::SQLSRV_TXN_SNAPSHOT,
      PDO::SQLSRV_TXN_SERIALIZABLE,
    ]);

    $this->_defaultDirectQueries = $this->CheckValid('default_direct_queries', $configuration['default_direct_queries'], [TRUE, FALSE]);
    $this->_defaultStatementCaching = $this->CheckValid('default_statement_caching', $configuration['default_statement_caching'], [TRUE, FALSE]);
    $this->_defaultBypassQueryPreprocess = $this->CheckValid('default_bypass_query_preprocess', $configuration['default_bypass_query_preprocess'], [TRUE, FALSE]);
    $this->_useNativeUpsert = $this->CheckValid('use_native_upsert', $configuration['use_native_upsert'], [TRUE, FALSE]);
    $this->_useNativeMerge = $this->CheckValid('use_native_merge', $configuration['use_native_merge'], [TRUE, FALSE]);
    $this->_statementCachingMode = $this->CheckValid('statement_caching_mode', $configuration['statement_caching_mode'], ['disabled', 'on-demand', 'always']);
    $this->_appendStackComments = $this->CheckValid('append_stack_comments', $configuration['append_stack_comments'], [TRUE, FALSE]);
    $this->_enableTransactions = $this->CheckValid('enable_transactions', $configuration['enable_transactions'], [TRUE, FALSE]);
    $this->_monitorDriverStatus = $this->CheckValid('monitor_driver_status', $configuration['monitor_driver_status'], [TRUE, FALSE]);
  }

  /**
   * Export current driver configuration.
   *
   * @return array
   */
  public function exportConfiguration() {
    return [
      'default_isolation_level' => $this->getDefaultIsolationLevel(),
      'default_direct_queries' => $this->getDefaultDirectQueries(),
      'use_native_upsert' => $this->getUseNativeUpsert(),
      'use_native_merge' => $this->getUseNativeMerge(),
      'statement_caching_mode' => $this->getStatementCachingMode(),
      'append_stack_comments' => $this->getAppendCallstackComment(),
      'default_bypass_query_preprocess' => $this->getDeafultBypassQueryPreprocess(),
      'default_statement_caching' => $this->getDeafultStatementCaching(),
      'monitorDriverStatus' => $this->getMonitorDriverStatus(),
    ];
  }

  /**
   *
   */
  public function getMonitorDriverStatus() {
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
  public function getEnableTransactions() {
    return $this->_enableTransactions;
  }

  /**
   * Isolation level used for implicit transactions.
   */
  public function getDefaultIsolationLevel() {
    return $this->_defaultIsolationLevel;
  }

  /**
   * PDO Constant names do not match 1-to-1 the transaction names that
   * need to be used in SQL.
   *
   * @return mixed
   */
  public function getDefaultTransactionIsolationLevelInStatement() {
    return str_replace('_', ' ', $this->getDefaultIsolationLevel());
  }

  /**
   * Default query preprocess.
   *
   * @return mixed
   */
  public function getDeafultStatementCaching() {
    return $this->_defaultStatementCaching;
  }

  /**
   * Default query preprocess.
   *
   * @return mixed
   */
  public function getDeafultBypassQueryPreprocess() {
    return $this->_defaultBypassQueryPreprocess;
  }

  /**
   * Wether to run all statements in direct query mode by default.
   */
  public function getDefaultDirectQueries() {
    return $this->_defaultDirectQueries;
  }

  /**
   * Wether to use or not the native upsert implementation.
   */
  public function getUseNativeUpsert() {
    return $this->_useNativeUpsert;
  }

  /**
   * Wether to user or not the native merge implementaiton.
   */
  public function getUseNativeMerge() {
    return $this->_useNativeMerge;
  }

  /**
   * Enable appending of PHP stack as query comments.
   */
  public function getAppendCallstackComment() {
    return $this->_appendStackComments;
  }

  /**
   * Experimental statement caching for PDO prepared statement
   * reuse.
   *
   * 'disabled' => Never use statement caching.
   * 'on-demand' => Only use statement caching when implicitly set in a Context.
   * 'always' => Always use statement caching.
   */
  public function getStatementCachingMode() {
    return $this->_statementCachingMode;
  }

}
