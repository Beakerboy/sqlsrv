<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Site\Settings;

/**
 * Global settings for the driver.
 */
class DriverSettings {

  /**
   * Default Isolation Level.
   *
   * @var mixed
   */
  private $defaultIsolationLevel;

  /**
   * Direct Queries.
   *
   * @var bool
   */
  private $defaultDirectQueries;

  /**
   * Bypass Query Preprocess.
   *
   * @var bool
   */
  private $defaultBypassQueryPreprocess;

  /**
   * Statement Caching.
   *
   * @var bool
   */
  private $defaultStatementCaching;

  /**
   * Native Upsert.
   *
   * @var bool
   */
  private $useNativeUpsert;

  /**
   * Native Merge.
   *
   * @var bool
   */
  private $useNativeMerge;

  /**
   * Statement Caching Mode.
   *
   * @var string
   */
  private $statementCachingMode;

  /**
   * Stack Comments.
   *
   * @var bool
   */
  private $appendStackComments;

  /**
   * Enable Transactions.
   *
   * @var bool
   */
  private $enableTransactions;

  /**
   * Monitor Driver Status.
   *
   * @var bool
   */
  private $monitorDriverStatus;

  /**
   * Default settings for the dabase driver.
   *
   * @var array
   */
  private static $defaultDriverSettings = [
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
   * @param mixed $name
   *   Parameter name.
   * @param mixed $value
   *   Value to check.
   * @param array $allowed
   *   Array of allowed values.
   *
   * @return mixed
   *   Value if valid.
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
   *   DriverSettings object.
   */
  public static function instanceFromSettings() {
    $configuration = Settings::get('mssql_configuration', []);
    $configuration = array_merge(static::$defaultDriverSettings, $configuration);
    return new DriverSettings($configuration);
  }

  /**
   * Builds a DriverSettings instance from custom settings.
   *
   * Missing settings are merged from the application settings.
   *
   * @param mixed $configuration
   *   Configuration.
   *
   * @return DriverSettings
   *   DriverSettings object.
   */
  public static function instanceFromData($configuration) {
    $configuration = array_merge(static::$defaultDriverSettings, $configuration);
    return new DriverSettings($configuration);
  }

  /**
   * Construct an instance of DriverSettings.
   *
   * @param mixed $configuration
   *   Driver configuration.
   */
  private function __construct($configuration) {

    $this->defaultIsolationLevel = $this->CheckValid('default_isolation_level', $configuration['default_isolation_level'], [
      FALSE,
      \PDO::SQLSRV_TXN_READ_UNCOMMITTED,
      \PDO::SQLSRV_TXN_READ_COMMITTED,
      \PDO::SQLSRV_TXN_REPEATABLE_READ,
      \PDO::SQLSRV_TXN_SNAPSHOT,
      \PDO::SQLSRV_TXN_SERIALIZABLE,
    ]);

    $true_false = [TRUE, FALSE];
    $caching_modes = ['disabled', 'on-demand', 'always'];
    $this->defaultDirectQueries = $this->CheckValid('default_direct_queries', $configuration['default_direct_queries'], $true_false);
    $this->defaultStatementCaching = $this->CheckValid('default_statement_caching', $configuration['default_statement_caching'], $true_false);
    $this->defaultBypassQueryPreprocess = $this->CheckValid('default_bypass_query_preprocess', $configuration['default_bypass_query_preprocess'], $true_false);
    $this->useNativeUpsert = $this->CheckValid('use_native_upsert', $configuration['use_native_upsert'], $true_false);
    $this->useNativeMerge = $this->CheckValid('use_native_merge', $configuration['use_native_merge'], $true_false);
    $this->statementCachingMode = $this->CheckValid('statement_caching_mode', $configuration['statement_caching_mode'], $caching_modes);
    $this->appendStackComments = $this->CheckValid('append_stack_comments', $configuration['append_stack_comments'], $true_false);
    $this->enableTransactions = $this->CheckValid('enable_transactions', $configuration['enable_transactions'], $true_false);
    $this->monitorDriverStatus = $this->CheckValid('monitor_driver_status', $configuration['monitor_driver_status'], $true_false);
  }

  /**
   * Export current driver configuration.
   *
   * @return array
   *   Configuration.
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
   * Monitor Driver Status.
   *
   * @return mixed
   *   Monitor Driver Status.
   */
  public function getMonitorDriverStatus() {
    return $this->monitorDriverStatus;
  }

  /**
   * Enable Transactions.
   *
   * Completely disable transaction management
   * at the driver level. The MSSQL PDO has some issues
   * that show up during testing so we need to diable
   * transactions to be able to run some tests...
   *
   * @see https://github.com/Azure/msphpsql/issues/49
   *
   * @return mixed
   *   Enable Transactions.
   */
  public function getEnableTransactions() {
    return $this->enableTransactions;
  }

  /**
   * Isolation level used for implicit transactions.
   *
   * @return mixed
   *   Default transaction isolation level.
   */
  public function getDefaultIsolationLevel() {
    return $this->defaultIsolationLevel;
  }

  /**
   * Default transaction isolation level in statement.
   *
   * PDO Constant names do not match 1-to-1 the transaction names that
   * need to be used in SQL.
   *
   * @return mixed
   *   Default transaction isolation level.
   */
  public function getDefaultTransactionIsolationLevelInStatement() {
    return str_replace('_', ' ', $this->getDefaultIsolationLevel());
  }

  /**
   * Default statement caching.
   *
   * @return mixed
   *   Default statement caching.
   */
  public function getDeafultStatementCaching() {
    return $this->defaultStatementCaching;
  }

  /**
   * Default query preprocess.
   *
   * @return mixed
   *   Default query preprocess.
   */
  public function getDeafultBypassQueryPreprocess() {
    return $this->defaultBypassQueryPreprocess;
  }

  /**
   * Wether to run all statements in direct query mode by default.
   */
  public function getDefaultDirectQueries() {
    return $this->defaultDirectQueries;
  }

  /**
   * Wether to use or not the native upsert implementation.
   */
  public function getUseNativeUpsert() {
    return $this->useNativeUpsert;
  }

  /**
   * Wether to user or not the native merge implementaiton.
   */
  public function getUseNativeMerge() {
    return $this->useNativeMerge;
  }

  /**
   * Enable appending of PHP stack as query comments.
   */
  public function getAppendCallstackComment() {
    return $this->appendStackComments;
  }

  /**
   * Experimental statement caching for PDO prepared statement reuse.
   *
   * 'disabled' => Never use statement caching.
   * 'on-demand' => Only use statement caching when implicitly set in a Context.
   * 'always' => Always use statement caching.
   */
  public function getStatementCachingMode() {
    return $this->statementCachingMode;
  }

}
