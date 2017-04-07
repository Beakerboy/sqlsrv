<?php

/**
 * @file
 * Definition of Drupal\Driver\Database\sqlsrv\Tasks
 */

namespace Drupal\Driver\Database\sqlsrv\Install;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Install\Tasks as InstallTasks;
use Drupal\Core\Database\DatabaseNotFoundException;
use Drupal\Driver\Database\sqlsrv\Connection;
use Drupal\Driver\Database\sqlsrv\Schema;
use mssql\Utils;

/**
 * Specifies installation tasks for PostgreSQL databases.
 */
class Tasks extends InstallTasks {

  /**
   * {@inheritdoc}
   */
  protected $pdoDriver = 'sqlsrv';

  /**
   * Constructs a \Drupal\Core\Database\Driver\pgsql\Install\Tasks object.
   */
  public function __construct() {
    $this->tasks[] = array(
      'function' => 'checkEncoding',
      'arguments' => [],
    );
    $this->tasks[] = array(
      'function' => 'checkRequirements',
      'arguments' => [],
    );
    $this->tasks[] = array(
      'function' => 'initializeDatabase',
      'arguments' => [],
    );
  }

  /**
   * {@inheritdoc}
   */
  public function name() {
    return t('SQLServer');
  }

  /**
   * {@inheritdoc}
   */
  public function minimumVersion() {
    return '8.3';
  }

  /**
   * {@inheritdoc}
   */
  protected function connect() {
    try {
      // This doesn't actually test the connection.
      db_set_active();
      // Now actually do a check.
      Database::getConnection();
      $this->pass('Drupal can CONNECT to the database ok.');
    }
    catch (\Exception $e) {
      // Attempt to create the database if it is not found.
      if ($e->getCode() == Connection::DATABASE_NOT_FOUND) {
        // Remove the database string from connection info.
        $connection_info = Database::getConnectionInfo();
        $database = $connection_info['default']['database'];
        unset($connection_info['default']['database']);

        // In order to change the Database::$databaseInfo array, need to remove
        // the active connection, then re-add it with the new info.
        Database::removeConnection('default');
        Database::addConnectionInfo('default', 'default', $connection_info['default']);

        try {
          // Now, attempt the connection again; if it's successful, attempt to
          // create the database.
          Database::getConnection()->createDatabase($database);
          Database::closeConnection();

          // Now, restore the database config.
          Database::removeConnection('default');
          $connection_info['default']['database'] = $database;
          Database::addConnectionInfo('default', 'default', $connection_info['default']);

          // Check the database connection.
          Database::getConnection();
          $this->pass('Drupal can CONNECT to the database ok.');
        }
        catch (DatabaseNotFoundException $e) {
          // Still no dice; probably a permission issue. Raise the error to the
          // installer.
          $this->fail(t('Database %database not found. The server reports the following message when attempting to create the database: %error.', array('%database' => $database, '%error' => $e->getMessage())));
          return FALSE;
        }
        catch (\PDOException $e) {
          // Still no dice; probably a permission issue. Raise the error to the
          // installer.
          $this->fail(t('Database %database not found. The server reports the following message when attempting to create the database: %error.', array('%database' => $database, '%error' => $e->getMessage())));
          return FALSE;
        }
      }
      else {
        // Database connection failed for some other reason than the database
        // not existing.
        $this->fail(t('Failed to connect to your database server. The server reports the following message: %error.<ul><li>Is the database server running?</li><li>Does the database exist, and have you entered the correct database name?</li><li>Have you entered the correct username and password?</li><li>Have you entered the correct database hostname?</li></ul>', array('%error' => $e->getMessage())));
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Check encoding is UTF8.
   */
  protected function checkEncoding() {
    try {
      /** @var \Drupal\Driver\Database\sqlsrv\Connection */
      $connection = Database::getConnection();
      $collation = $connection->Scheme()->getCollation($connection->getDatabaseName(), $connection->schema()->defaultSchema);
      if ($collation == Schema::DEFAULT_COLLATION_CI || stristr($collation, '_CI') !== FALSE) {
        $this->pass(t('Database is encoded in case insensitive collation: $collation'));
      }
      else {
        $this->fail(t('The %driver database must use case insensitive encoding (recomended %encoding) to work with Drupal. Recreate the database with %encoding encoding. See !link for more details.', array(
          '%encoding' => Schema::DEFAULT_COLLATION_CI,
          '%driver' => $this->name(),
          '!link' => '<a href="INSTALL.sqlsrv.txt">INSTALL.sqlsrv.txt</a>'
        )));
      }
    }
    catch (\Exception $e) {
      $this->fail(t('Drupal could not determine the encoding of the database was set to UTF-8'));
    }
  }

  /**
   * Check for general requirements
   */
  protected function checkRequirements() {
    try {
      $errors = static::InstallRequirements();

      // TODO: Find a better way to print this information...
      if (!empty($errors)) {
        foreach ($errors as $error) {
          if ($error['severity'] == REQUIREMENT_ERROR || $error['severity'] == REQUIREMENT_WARNING) {
            $this->fail($error['description']);
          }
        }
      }

    }
    catch (\Exception $e) {
      $this->fail(t('Could not check requirements:') . $e->getMessage());
    }
  }

  /**
   * Make SQLServer Drupal friendly.
   */
  function initializeDatabase() {
    // We create some functions using global names instead of prefixing them
    // like we do with table names. This is so that we don't double up if more
    // than one instance of Drupal is running on a single database. We therefore
    // avoid trying to create them again in that case.
    try {

      /** @var \Drupal\Driver\Database\sqlsrv\Connection $database */
      $connection = Database::getConnection();

      \mssql\Utils::DeployCustomFunctions($connection->getConnection(),  dirname(__FILE__) . '/../Programability');

      $this->pass(t('SQLServer has initialized itself.'));
    }
    catch (\Exception $e) {
      $this->fail(t('Drupal could not be correctly setup with the existing database. Revise any errors.'));
    }
  }

  /**
   * Enable the SQL Server module.
   */
  function enableModule() {
    /** @var \Drupal\Core\Extension\ModuleInstallerInterface  */
    $installer = \Drupal::service('module_installer');
    $installer->install(array('sqlsrv'));
  }

  /**
   * Return the install requirements for both the status
   * page and the install process.
   */
  public static function InstallRequirements() {

    // Array of requirement errors.
    $errors = [];

    #region Check for PhpMssql

    include_once (__DIR__ . '/../PhpMssqlAutoloader.php');
    if (!class_exists(\mssql\Connection::class)) {
      $error = [];
      $error['title'] = 'MSSQL Server PhpMssql';
      $error['severity'] = REQUIREMENT_ERROR;
      $error['description'] = t('This driver depends on the PhpMsql library. You can use the community *supported* <a href="https://www.drupal.org/project/sqlsrv">8.x-1.x</a> version of the driver or get PhpMSSQL from <a href="http://www.drupalonwindows.com/en/content/phpmssql">here</a>. See README.rm for deployment instructions.');
      $errors['sqlsrv_phpmssql'] = $error;
    }

    #endregion

    #region check for MS SQL PDO version and client buffer size

    $sqlsrv_extension_data = Utils::ExtensionData('pdo_sqlsrv');

    // Version.
    $version_ok = version_compare($sqlsrv_extension_data->Version() , '3.2') >= 0;
    $requirements['sqlsrv_pdo'] = array(
      'title' => t('MSSQL Server PDO extension'),
      'severity' => $version_ok ? REQUIREMENT_OK : REQUIREMENT_ERROR,
      'value' => t('@level', array('@level' => $sqlsrv_extension_data->Version())),
      'description' => t('Use at least the 3.2.0.0 version of the MSSQL PDO driver.')
    );

    // Client buffer size.
    $buffer_size = $sqlsrv_extension_data->IniEntries()['pdo_sqlsrv.client_buffer_max_kb_size'];
    $buffer_size_min = (12240 * 2);
    $buffer_size_ok = $buffer_size >= $buffer_size_min;
    $errors['sqlsrv_client_buffer_size'] = array(
      'title' => t('MSSQL Server client buffer size'),
      'severity' => $buffer_size_ok ? REQUIREMENT_OK : REQUIREMENT_WARNING,
      'value' => "{$buffer_size} Kb",
      'description' => "pdo_sqlsrv.client_buffer_max_kb_size setting must be of at least {$buffer_size_min}Kb. Currently {$buffer_size}Kb.",
    );

    return $errors;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormOptions(array $database) {

    $form = parent::getFormOptions($database);
    if (empty($form['advanced_options']['port']['#default_value'])) {
      $form['advanced_options']['port']['#default_value'] = '1433';
    }

    // Make username not required.
    $form['username']['#required'] = FALSE;

    // Add a description for about leaving username blank.
    $form['username']['#description'] = t('Leave username (and password) blank to use Windows authentication.');

    $form['#submit'] = [];

    return $form;
  }
}