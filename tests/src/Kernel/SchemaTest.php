<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\SchemaObjectExistsException;
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

    $this->name = 'test_comment_table';
    $this->table = [
      'description' => 'Original Comment',
      'fields' => [
        'id'  => [
          'description' => 'Original field comment',
          'type' => 'int',
          'default' => NULL,
        ],
        'name'  => [
          'description' => 'Original name comment',
          'type' => 'varchar',
          'length' => 50,
        ],
      ],
    ];
    // Create table with description.
    $this->schema->createTable($this->name, $this->table);
  }

  /**
   * Verify that comments are dropped when the table is dropped.
   */
  public function testDropTableComment() {
    // I should probably replace this with a schema installation.
    $name = $this->name;
    $table = $this->table;
    // Drop table and ensure comment does not exist.
    $this->schema->dropTable($name);
    $this->assertFalse($this->schema->getComment($name));

    // Create table with different description.
    $table['description'] = 'New Comment';
    $this->schema->createTable($name, $table);

    // Verify comment is correct.
    $comment = $this->schema->getComment($name);
    $this->assertEquals('New Comment', $comment);
  }

  /**
   * Verify that comments are dropped when the field is dropped.
   */
  public function testDropFieldComment() {

    // Drop field and ensure comment does not exist.
    $this->schema->dropField('test_comment_table', 'name');
    $this->assertFalse($this->schema->getComment('test_comment_table', 'name'));

    // Add field with different description.
    $spec = $this->table['fields']['name'];
    $spec['description'] = 'New name comment';
    $this->schema->addField('test_comment_table', 'name', $spec);

    // Verify comment is correct.
    $comment = $this->schema->getComment('test_comment_table', 'name');
    $this->assertEquals('New name comment', $comment);
  }

  /**
   * Verify that comments are changed when the field is altered.
   */
  public function testChangeFieldComment() {

    // Alter table and change field description.
    // Verify comment is correct.
  }

  /**
   * Exception thrown when field does not exist.
   */
  public function testAddDefaultException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->fieldSetDefault('test_comment_table', 'noname', 'Elvis');
  }

  /**
   * Exception thrown when field does not exist.
   */
  public function testAddNotDefaultException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->fieldSetNoDefault('test_comment_table', 'noname');
  }

  /**
   * Exception thrown when table exists.
   */
  public function testCreateTableExists() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->createTable('test_comment_table', $this->table);
  }

  /**
   * Test getDefaultSchema with no default.
   *
   * Should this be done in isolation to ensure the correct value
   * is returned if the test server is configured with a different
   * value for the schema?
   */
  public function testGetDefaultSchemaNoDefault() {
    $schema = new \ReflectionClass('\Drupal\Driver\Database\sqlsrv\Schema');
    $property = $schema->getProperty("defaultSchema");
    $property->setAccessible(TRUE);
    $property->setValue($this->schema, NULL);

    $schema_name = $this->schema->getDefaultSchema();
    $this->assertEquals($schema_name, 'dbo');
  }

}
