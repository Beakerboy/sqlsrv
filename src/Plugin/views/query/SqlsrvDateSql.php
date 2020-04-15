<?php

namespace Drupal\sqlsrv\Plugin\views\query;

use Drupal\Core\Database\Connection;
use Drupal\views\Plugin\views\query\DateSqlInterface;

/**
 * MSSQL-specific date handling.
 *
 * @internal
 *   This class should only be used by the Views SQL query plugin.
 * @see \Drupal\views\Plugin\views\query\Sql
 */
class SqlsrvDateSql implements DateSqlInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * An array of PHP-to-MSSQL replacement patterns.
   *
   * @var array
   */
  protected static $replace = [
    'Y' => 'yyyy',
    'y' => 'yy',
    'M' => 'MMM',
    'm' => 'MM',
    'n' => 'M',
    'F' => 'MMMM',
    'D' => 'ddd',
    'd' => 'dd',
    'l' => 'ddd',
    'j' => 'd',
    // No week number format.
    'H' => 'HH',
    'h' => 'hh',
    'i' => 'mm',
    's' => 'ss',
    'A' => 'tt',
  ];

  /**
   * Constructs the MSSQL-specific date sql class.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public function getDateField($field, $string_date) {
    if ($string_date) {
      return "CONVERT(datetime2, $field, 127)";
    }

    // Base date field storage is timestamp, so the date to be returned here is
    // epoch + stored value (seconds from epoch).
    return "DATEADD(second, $field, '19700101')";
  }

  /**
   * {@inheritdoc}
   */
  public function getDateFormat($field, $format) {
    $format = strtr($format, static::$replace);

    // MS SQL does not have a ISO week substitution string, so it needs special
    // handling.
    // @see http://wikipedia.org/wiki/ISO_week_date#Calculation
    // @see http://stackoverflow.com/a/15511864/1499564

    if ($format === 'W') {
      return "DATEPART(iso_week, $field)";
    }

    return "FORMAT($field, '$format')";
  }

  /**
   * {@inheritdoc}
   */
  public function setTimezoneOffset($offset) {
    // $this->database->query("SET @@session.time_zone = '$offset'");
  }

  /**
   * {@inheritdoc}
   */
  public function setFieldTimezoneOffset(&$field, $offset) {
    if (!empty($offset)) {
      $field = "DATEADD(second, $offset, $field)";
    }
  }

}
