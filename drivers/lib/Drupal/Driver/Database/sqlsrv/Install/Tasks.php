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
      $database = Database::getConnection();
      $database->bypassQueryPreprocess = TRUE;
      $schema = $database->schema();

      // SUBSTRING() function.
      $substring_exists = $schema->functionExists('SUBSTRING') ? 'ALTER' : 'CREATE';
      $database->query(<<< EOF
{$substring_exists} FUNCTION [SUBSTRING](@op1 nvarchar(max), @op2 sql_variant, @op3 sql_variant) RETURNS nvarchar(max) AS
BEGIN
  RETURN CAST(SUBSTRING(CAST(@op1 AS nvarchar(max)), CAST(@op2 AS int), CAST(@op3 AS int)) AS nvarchar(max))
END
EOF
      );

      // SUBSTRING_INDEX() function.
      $substring_index_exists = $schema->functionExists('SUBSTRING_INDEX') ? 'ALTER' : 'CREATE';
      $database->query(<<< EOF
            {$substring_index_exists} FUNCTION [SUBSTRING_INDEX](@string varchar(8000), @delimiter char(1), @count int) RETURNS varchar(8000) AS
            BEGIN
              DECLARE @result varchar(8000)
              DECLARE @end int
              DECLARE @part int
              SET @end = 0
              SET @part = 0
              IF (@count = 0)
              BEGIN
                SET @result = ''
              END
              ELSE
              BEGIN
                IF (@count < 0)
                BEGIN
                  SET @string = REVERSE(@string)
                END
                WHILE (@part < ABS(@count))
                BEGIN
                  SET @end = CHARINDEX(@delimiter, @string, @end + 1)
                  IF (@end = 0)
                  BEGIN
                    SET @end = LEN(@string) + 1
                    BREAK
                  END
                  SET @part = @part + 1
                END
                SET @result = SUBSTRING(@string, 1, @end - 1)
                IF (@count < 0)
                BEGIN
                  SET @result = REVERSE(@result)
                END
              END
              RETURN @result
            END
EOF
      );

      // GREATEST() function.
      $greatest_exists = $schema->functionExists('GREATEST') ? 'ALTER' : 'CREATE';
      $database->query(<<< EOF
            {$greatest_exists} FUNCTION [GREATEST](@op1 sql_variant, @op2 sql_variant) RETURNS sql_variant AS
            BEGIN
              DECLARE @result sql_variant
              SET @result = CASE WHEN @op1 >= @op2 THEN @op1 ELSE @op2 END
              RETURN @result
            END
EOF
      );

      // CONCAT() function.
      $concat_exists = $schema->functionExists('CONCAT') ? 'ALTER' : 'CREATE';
      $database->query(<<< EOF
            {$concat_exists} FUNCTION [CONCAT](@op1 sql_variant, @op2 sql_variant) RETURNS nvarchar(4000) AS
            BEGIN
              DECLARE @result nvarchar(4000)
              SET @result = CAST(@op1 AS nvarchar(4000)) + CAST(@op2 AS nvarchar(4000))
              RETURN @result
            END
EOF
      );

      // IF(expr1, expr2, expr3) function.
      $if_exists = $schema->functionExists('IF') ? 'ALTER' : 'CREATE';
      $database->query(<<< EOF
            {$if_exists} FUNCTION [IF](@expr1 sql_variant, @expr2 sql_variant, @expr3 sql_variant) RETURNS sql_variant AS
            BEGIN
              DECLARE @result sql_variant
              SET @result = CASE WHEN CAST(@expr1 AS int) != 0 THEN @expr2 ELSE @expr3 END
              RETURN @result
            END
EOF
      );
      
      // MD5(@value) function.
      $if_exists = $schema->functionExists('MD5') ? 'ALTER' : 'CREATE';
      $database->query(<<< EOF
            {$if_exists} FUNCTION [dbo].[MD5](@value varchar(255)) RETURNS varchar(32) AS
            BEGIN
	            RETURN SUBSTRING(sys.fn_sqlvarbasetostr(HASHBYTES('MD5', @value)),3,32);
            END
EOF
      );
      
      // LPAD(@str, @len, @padstr) function.
      $if_exists = $schema->functionExists('LPAD') ? 'ALTER' : 'CREATE';
      $database->query(<<< EOF
            {$if_exists} FUNCTION [dbo].[LPAD](@str nvarchar(max), @len int, @padstr nvarchar(max)) RETURNS nvarchar(4000) AS
            BEGIN
	            RETURN left(@str + replicate(@padstr,@len),@len);
            END
EOF
      );
      
      // CONNECTION_ID() function.
      $if_exists = $schema->functionExists('CONNECTION_ID') ? 'ALTER' : 'CREATE';
      $database->query(<<< EOF
            {$if_exists} FUNCTION [dbo].[CONNECTION_ID]() RETURNS smallint AS
            BEGIN
              DECLARE @var smallint
              SELECT @var = @@SPID
              RETURN @Var
            END
EOF
      );

      $database->bypassQueryPreprocess = FALSE;

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
