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

  public function testGetCommentWithPrefixes() {
    $pdo = new \PDO('sqlsrv:Server=localhost;Database=mydrupalsite', 'sa', 'Password12!');
    $table = 'comment_table_test';
    $prefixed_table = $this->connection->prefixTables('{'.$table.'}');
    // Create table
    $sql = "CREATE table $prefixed_table (comment_field_a int)";
    $pdo->query($sql);
    
    // Add Comment
    $sql = "EXEC sp_addextendedproperty @name=N'MS_Description', @value='Test Comment'";
    $sql .= ",@level0type = N'Schema', @level0name = 'dbo'";
    $sql .= ",@level1type = N'Table', @level1name = '{$prefixed_table}'";
    $pdo->query($sql);

    // Query Comment
    $sql = "SELECT value FROM fn_listextendedproperty ('MS_Description','Schema','dbo','Table','{$prefixed_table}',NULL,NULL)";
    $statement = $pdo->query($sql);
    $statement->setFetchMode(\PDO::FETCH_NUM);
    $comment = $statement->fetch();
    $this->assertEquals('Test Comment', $comment[0]);
  }

  public function testGetCommentWithQuery() {
    $pdo = new \PDO('sqlsrv:Server=localhost;Database=mydrupalsite', 'sa', 'Password12!');
    $table = 'comment_table_test';
    $prefixed_table = $this->connection->prefixTables('{'.$table.'}');
    // Create table
    $sql = "CREATE table $prefixed_table (comment_field_a int)";
    $pdo->query($sql);
    
    // Add Comment
    $sql = "EXEC sp_addextendedproperty @name=N'MS_Description', @value='Test Comment'";
    $sql .= ",@level0type = N'Schema', @level0name = 'dbo'";
    $sql .= ",@level1type = N'Table', @level1name = '{$prefixed_table}'";
    $pdo->query($sql);

    // Query Comment
    $sql = "SELECT value FROM fn_listextendedproperty ('MS_Description','Schema','dbo','Table','{$prefixed_table}',NULL,NULL)";
    $comment = $this->connection->query($sql)->fetchField();
    $this->assertEquals('Test Comment', $comment);
  }

   public function testGetCommentWithUnprefixedQuery() {
    $pdo = new \PDO('sqlsrv:Server=localhost;Database=mydrupalsite', 'sa', 'Password12!');
    $table = 'comment_table_test';
    $prefixed_table = $this->connection->prefixTables('{'.$table.'}');
    // Create table
    $sql = "CREATE table $prefixed_table (comment_field_a int)";
    $pdo->query($sql);
    
    // Add Comment
    $sql = "EXEC sp_addextendedproperty @name=N'MS_Description', @value='Test Comment'";
    $sql .= ",@level0type = N'Schema', @level0name = 'dbo'";
    $sql .= ",@level1type = N'Table', @level1name = '{$prefixed_table}'";
    $pdo->query($sql);

    // Query Comment
    $sql = "SELECT value FROM fn_listextendedproperty ('MS_Description','Schema','dbo','Table','{{$table}}',NULL,NULL)";
    $comment = $this->connection->query($sql)->fetchField();
    $this->assertEquals('Test Comment', $comment);
  }
}
