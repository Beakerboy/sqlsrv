<?php

namespace Drupal\sqlsrv\Driver\Database\sqlsrv;

use Symfony\Component\Yaml\Parser;

/**
 * Utility function for the SQL Server driver.
 */
class Utils {

  /**
   * Bind the arguments to the statement.
   *
   * @param \PDOStatement $stmt
   *   Statement.
   * @param array $values
   *   Argument values.
   */
  public static function bindArguments(\PDOStatement $stmt, array &$values) {
    foreach ($values as $key => &$value) {
      $stmt->bindParam($key, $value, \PDO::PARAM_STR);
    }
  }

  /**
   * Binds a set of values to a PDO Statement.
   *
   * Takes care of properly managing binary data.
   *
   * @param \PDOStatement $stmt
   *   PDOStatement to bind the values to.
   * @param array $values
   *   Values to bind. It's an array where the keys are column
   *   names and the values what is going to be inserted.
   * @param array $blobs
   *   When sending binary data to the PDO driver, we need to keep
   *   track of the original references to data.
   * @param mixed $placeholder_prefix
   *   Prefix to use for generating the query placeholders.
   * @param array $columnInformation
   *   Column information.
   * @param mixed $max_placeholder
   *   Placeholder count, if NULL will start with 0.
   * @param mixed $blob_suffix
   *   Suffix for the blob key.
   */
  public static function bindValues(\PDOStatement $stmt, array &$values, array &$blobs, $placeholder_prefix, array $columnInformation, &$max_placeholder = NULL, $blob_suffix = NULL) {
    if (empty($max_placeholder)) {
      $max_placeholder = 0;
    }
    foreach ($values as $field_name => &$field_value) {
      $placeholder = $placeholder_prefix . $max_placeholder++;
      $blob_key = $placeholder . $blob_suffix;
      if (isset($columnInformation['blobs'][$field_name])) {
        $blobs[$blob_key] = fopen('php://memory', 'a');
        fwrite($blobs[$blob_key], $field_value);
        rewind($blobs[$blob_key]);
        $stmt->bindParam($placeholder, $blobs[$blob_key], \PDO::PARAM_LOB, 0, \PDO::SQLSRV_ENCODING_BINARY);
      }
      else {
        // Even though not a blob, make sure we retain a copy of these values.
        $blobs[$blob_key] = $field_value;
        $stmt->bindParam($placeholder, $blobs[$blob_key], \PDO::PARAM_STR);
      }
    }
  }

  /**
   * Returns the spec for a MSSQL data type definition.
   *
   * @param string $type
   *   Data type.
   *
   * @return string
   *   Data type spec.
   */
  public static function getMssqlType($type) {
    $matches = [];
    if (preg_match('/^[a-zA-Z]*/', $type, $matches)) {
      return reset($matches);
    }
    return $type;
  }

  /**
   * Whether or not this is a Windows operating system.
   *
   * Does there need to be a function to determine if the database is on a
   * Windows environment?
   *
   * @return bool
   *   Is this server Windows?
   */
  public static function windowsOs() {
    return strncasecmp(PHP_OS, 'WIN', 3) == 0;
  }

  /**
   * Deploy custom functions for Drupal Compatiblity.
   *
   * @param Connection $connection
   *   Connection used for deployment.
   * @param bool $redeploy
   *   Wether to redeploy existing functions, or only missing ones.
   */
  public static function deployCustomFunctions(Connection $connection, $redeploy = FALSE) {
    $yaml = new Parser();
    $base_path = dirname(__FILE__) . '/Programability';
    $configuration = $yaml->parse(file_get_contents("$base_path/configuration.yml"));

    /** @var Schema $schema */
    $schema = $connection->schema();

    foreach ($configuration['functions'] as $function) {
      $name = $function['name'];
      $path = "$base_path/{$function['file']}";
      $exists = $schema->functionExists($name);
      if ($exists && !$redeploy) {
        continue;
      }
      if ($exists) {
        $connection->queryDirect("DROP FUNCTION [{$name}]");
      }
      $script = trim(static::removeUtf8Bom(file_get_contents($path)));
      $connection->queryDirect($script);
    }
  }

  /**
   * Remove UTF8 BOM.
   *
   * @param string $text
   *   UTF8 text.
   *
   * @return string
   *   Text without UTF8 BOM.
   */
  private static function removeUtf8Bom($text) {
    $bom = pack('H*', 'EFBBBF');
    $text = preg_replace("/^$bom/", '', $text);
    return $text;
  }

}
