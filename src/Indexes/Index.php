<?php

namespace Drupal\sqlsrv\Indexes;

/**
 * Represents a database Index.
 */
class Index {

  private $table;

  private $name;

  private $code;

  /**
   * Create an instance of Index.
   * 
   * @param mixed $uri 
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
   * @return string
   */
  public function GetTable() {
    return $this->table;
  }

  /**
   * Index name.
   * 
   * @return string
   */
  public function GetName() {
    return $this->name;
  }

  /**
   * Get the SQL statement to create this index.
   * 
   * @return string
   */
  public function GetCode() {
    return \mssql\Utils::removeUtf8Bom($this->code);
  }
}
