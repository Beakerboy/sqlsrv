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
use Drupal\Driver\Database\sqlsrv\Utils;

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
      'arguments' => array(),
    );
    $this->tasks[] = array(
      'function' => 'initializeDatabase',
      'arguments' => array(),
    );
    $this->tasks[] = array(
      'function' => 'enableModule',
      'arguments' => array(),
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
      $database = Database::getConnection();
      $schema = $database->schema();
      $collation = $schema->getCollation();
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
   * Make SQLServer Drupal friendly.
   */
  function initializeDatabase() {
    // We create some functions using global names instead of prefixing them
    // like we do with table names. This is so that we don't double up if more
    // than one instance of Drupal is running on a single database. We therefore
    // avoid trying to create them again in that case.
    try {

      /** @var Connection $database */
      $connection = Database::getConnection();

      Utils::DeployCustomFunctions($connection);

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
    // TODO: Looks like the module hanlder service is unavailable during
    // this installation phase?
    //$handler = new \Drupal\Core\Extension\ModuleHandler();
    //$handler->enable(array('sqlsrv'), FALSE);
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
    return $form;
  }
}