<?php

namespace Drupal\sqlsrv\Indexes;

/**
 * Represents a database Index.
 */
class Index {

  /**
   * Table Name.
   *
   * @var string
   */
  private $table;

  /**
   * Index Name.
   *
   * @var string
   */
  private $name;

  /**
   * SQL Statement.
   *
   * @var string
   */
  private $code;

  /**
   * Create an instance of Index.
   *
   * @param mixed $uri
   *   A URI.
   *
   * @throws \Exception
   */
  public function __construct($uri) {

    $name = pathinfo($uri, PATHINFO_FILENAME);
    $parts = explode('@', basename($name));

    if (count($parts) != 2) {
      throw new \Exception('Incorrect SQL index file name format.');
    }

    $this->table = $parts[0];
    $this->name = $parts[1];

    $this->code = file_get_contents($uri);
  }

  /**
   * Table name.
   *
   * @return string Table name
   */
  public function getTable() {
    return $this->table;
  }

  /**
   * Index name.
   *
   * @return string Index name
   */
  public function getName() {
    return $this->name;
  }

  /**
   * Get the SQL statement to create this index.
   *
   * @return string Code
   */
  public function getCode() {
    return $this->code;
  }

}
