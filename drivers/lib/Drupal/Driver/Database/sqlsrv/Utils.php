<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\Query\Update as QueryUpdate;
use Drupal\Core\Database\Query\Condition;

use Symfony\Component\Yaml\Parser;

use PDO as PDO;
use PDOStatement as PDOStatement;

class Utils {

  /**
   * Summary of BindArguments
   *
   * @param PDOStatement $stmt
   * @param array $values
   */
  public static function BindArguments(\PDOStatement $stmt, array &$values) {
    foreach ($values as $key => &$value) {
      $stmt->bindParam($key, $value, PDO::PARAM_STR);
    }
  }

  /**
   * Summary of BindExpressions
   *
   * @param PDOStatement $stmt
   * @param array $values
   * @param array $remove_from
   */
  public static function BindExpressions(\PDOStatement $stmt, array &$values, array &$remove_from) {
    foreach ($values as $key => $value) {
      unset($remove_from[$key]);
      if (empty($value['arguments'])) {
        continue;
      }
      if (is_array($value['arguments'])) {
        foreach ($value['arguments'] as $placeholder => $argument) {
          // We assume that an expression will never happen on a BLOB field,
          // which is a fairly safe assumption to make since in most cases
          // it would be an invalid query anyway.
          $stmt->bindParam($placeholder, $value['arguments'][$placeholder]);
        }
      }
      else {
        $stmt->bindParam($key, $value['arguments'], PDO::PARAM_STR);
      }
    }
  }

  /**
   * Binds a set of values to a PDO Statement,
   * taking care of properly managing binary data.
   *
   * @param PDOStatement $stmt
   * PDOStatement to bind the values to
   *
   * @param array $values
   * Values to bind. It's an array where the keys are column
   * names and the values what is going to be inserted.
   *
   * @param array $blobs
   * When sending binary data to the PDO driver, we need to keep
   * track of the original references to data
   *
   * @param array $ref_prefix
   * The $ref_holder might be shared between statements, use this
   * prefix to prevent key colision.
   *
   * @param mixed $placeholder_prefix
   * Prefix to use for generating the query placeholders.
   *
   * @param mixed $max_placeholder
   * Placeholder count, if NULL will start with 0.
   *
   */
  public static function BindValues(\PDOStatement $stmt, array &$values, array &$blobs, $placeholder_prefix, $columnInformation, &$max_placeholder = NULL, $blob_suffix = NULL) {
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
        $stmt->bindParam($placeholder, $blobs[$blob_key], PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
      }
      else {
        // Even though not a blob, make sure we retain a copy of these values.
        $blobs[$blob_key] = $field_value;
        $stmt->bindParam($placeholder, $blobs[$blob_key], PDO::PARAM_STR);
      }
    }
  }

  /**
   * Returns the spec for a MSSQL data type definition.
   * 
   * @param string $type
   * 
   * @return string
   */
  public static function GetMSSQLType($type) {
    $matches = array();
    if(preg_match('/^[a-zA-Z]*/' , $type, $matches)) {
      return reset($matches);
    }
    return $type;
  }

  /**
   * Get some info about extensions...
   *
   * @param \ReflectionExtension $re
   * @return array
   */
  public static function ExtensionData($name) {

    $re = new \ReflectionExtension($name);

    $_data = [];

    $_data['getName'] = $re->getName() ?: NULL;
    $_data['getVersion'] = $re->getVersion() ?: NULL;
    $_data['getClassName'] = PHP_EOL.implode(", ",$re->getClassNames()) ?: NULL;
    foreach ($re->getConstants() as $key => $value) $_data['getConstants'] .= "\n{$key}:={$value}";
    $_data['getDependencies'] = $re->getDependencies() ?: NULL;
    $_data['getFunctions'] = PHP_EOL.implode(", ",array_keys($re->getFunctions())) ?: NULL;
    $_data['getINIEntries'] = $re->getINIEntries() ?: NULL;
    $_data['isPersistent'] = $re->isPersistent() ?: NULL;
    $_data['isTemporary'] = $re->isTemporary() ?: NULL;

    return $_data;
  }

  /**
   * Wether or not this is a Windows operating system.
   */
  public static function WindowsOS() {
    return strncasecmp(PHP_OS, 'WIN', 3) == 0;
  }

  /**
   * Deploy custom functions for Drupal Compatiblity.
   * 
   * @param Connection $connection 
   *   Connection used for deployment.
   * 
   * @param boolean $redeploy
   *   Wether to redeploy existing functions, or only missing ones.
   */
  public static function DeployCustomFunctions(Connection $connection, $redeploy = FALSE) {
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
        $connection->query_direct("DROP FUNCTION [{$name}]");
      }
      $script = trim(static::remove_utf8_bom(file_get_contents($path)));
      $connection->query_direct($script);
    }
  }

  private static function remove_utf8_bom($text) {
    $bom = pack('H*','EFBBBF');
    $text = preg_replace("/^$bom/", '', $text);
    return $text;
  }
}