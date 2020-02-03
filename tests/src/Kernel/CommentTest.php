<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;

class CommentTest extends KernelTestBase {

  /**
   * Connection to the database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Database schema instance.
   *
   * @var \Drupal\Core\Database\Schema
   */
  protected $schema;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->connection = Database::getConnection();
    $this->schema = $this->connection->schema();
  }

  public function testGetCommentWithDriver() {
    $table = 'comment_table_driver';
    $prefixed_table = $this->connection->prefixTables('{'.$table.'}');
    $pdo = new \PDO('sqlsrv:host=localhost;dbname=mydrupalsite', 'sa', 'Password123!');

    // Create table
    $sql = 'CREATE table $prefixed_table (comment_field_a int)';
    $pdo->query($sql);
    
    // Add Comment
    $sql = "EXEC sp_addextendedproperty @name=N'MS_Description', @value='Test Comment'";
    $sql .= ",@level0type = N'Schema', @level0name = 'dbo'";
    $sql .= ",@level1type = N'Table', @level1name = '{$prefixed_table}'";
    $pdo->query($sql);

    // Query Comment
    $comment = $this->schema->getComment($table);
    //$sql = "SELECT value FROM fn_listextendedproperty ('MS_Description','Schema','dbo','Table','comment_table_test',NULL,NULL)";
    //$statement = $pdo->query($sql);
    //$results = $statement-> setFetchMode(\PDO::FETCH_NUM);
    //$comment = $results->fetch();
    $this->assertEquals('Test Comment', $comment);
  }
}
