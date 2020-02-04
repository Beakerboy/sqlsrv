<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\Database;
use Drupal\KernelTests\KernelTestBase;
/**
 * Tests table creation and modification via the schema API.
 *
 * @group Database
 */
class SchemaTest extends KernelTestBase {

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

  public function testDropTableComment() {
    $name = 'test_comment_table';
    $table = [
      'description' => 'Original Comment',
      'fields' => [
        'id'  => [
          'type' => 'int',
          'default' => NULL,
        ],
      ],
    ];
    // create table with description
    $this->createTable($name, $table);

    // drop table
    $this->schema->dropTable($name);

    // create table with different description
    $table['description'] = 'New Comment';
    $this->schema->createTable($name, $table);

    // verify comment is correct
    $comment = $this->schema->getComment($name);
    $this->assertEquals('New Comment', $comment);
  }
}
