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
    $this->table = [
      'description' => 'New Comment',
      'fields' => [
        'id' => [
          'type' => 'serial',
          'unsigned' => TRUE,
          'not null' => TRUE,
        ],
        'name' => [
          'description' => "A person's name",
          'type' => 'varchar_ascii',
          'length' => 255,
          'not null' => TRUE,
          'default' => '',
          'binary' => TRUE,
        ],
        'age' => [
          'description' => "The person's age",
          'type' => 'int',
          'unsigned' => TRUE,
          'not null' => TRUE,
          'default' => 0,
        ],
        'job' => [
          'description' => "The person's job",
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
          'default' => 'Undefined',
        ],
      ],
      'primary key' => ['id'],
      'unique keys' => [
        'name' => ['name'],
      ],
      'indexes' => [
        'ages' => ['age'],
      ],
    ];
  }

  /**
   * Verify that comments are dropped when the table is dropped.
   */
  public function testDropTableComment() {
    // Drop table and ensure comment does not exist.
    $this->schema->dropTable('test');
    $this->assertFalse($this->schema->getComment('test'));

    $this->schema->createTable('test', $this->table);

    // Verify comment is correct.
    $comment = $this->schema->getComment('test');
    $this->assertEquals('New Comment', $comment);
  }

  /**
   * Verify that comments are dropped when the field is dropped.
   */
  public function testDropFieldComment() {

    // Drop field and ensure comment does not exist.
    $this->schema->dropField('test', 'name');
    $this->assertFalse($this->schema->getComment('test', 'name'));

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
    $this->schema->fieldSetDefault('test', 'noname', 'Elvis');
  }

  /**
   * Exception thrown when field does not exist.
   */
  public function testAddNotDefaultException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->fieldSetNoDefault('test', 'noname');
  }

  /**
   * Exception thrown when table exists.
   */
  public function testCreateTableExists() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->createTable('test', $this->table);
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

  /**
   * Exception thrown when table does not exist
   */
  public function testRenameTableAlreadyExists() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->renameTable('tabledoesnotexist', 'test_new');
  }

  /**
   * Exception thrown when table already exists.
   */
  public function testRenameTableDoesNotExist() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->renameTable('test_people', 'test');
  }

  /**
   * Exception thrown when field already exists.
   */
  public function testNewFieldExists() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->addField('test', 'name', $this->table['fields']['name']);
  }

  /**
   * Exception thrown when table does not exist.
   */
  public function testPrimaryKeyTableDoesNotExist() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->addPrimaryKey('test_new', 'name');
  }

  /**
   * Exception thrown when primary key already exists.
   */
  public function testPrimaryKeyExists() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->addPrimaryKey('test', 'name');
  }

  /**
   * Exception thrown when table does not exist.
   *
   * Verify that the function parameters after 'name' are correct.
   */
  public function testUniqueKeyTableDoesNotExist() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->addUniqueKey('test_new', 'name', $this->table['fields']);
  }

  /**
   * Exception thrown when unique key already exists.
   *
   * Verify that the function parameters after 'name' are correct.
   */
  public function testUniqueKeyExists() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->addUniqueKey('test', 'name', $this->table['fields']);
  }

  /**
   * Exception thrown when table does not exist.
   *
   * Verify that the function parameters after 'name' are correct.
   */
  public function testIndexTableDoesNotExist() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->addIndex('test_new', 'name', $this->table['fields'], $this->table);
  }

  /**
   * Exception thrown when unique key already exists.
   *
   * Verify that the function parameters after 'age' are correct.
   */
  public function testUniqueKeyExists() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->addindex('test', 'age', $this->table['fields'], $this->table);
  }

  /**
   * Exception thrown when table does not exist.
   */
  public function testIntroscpectSchemaException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->introspectSchema('test_new');
  }

  /**
   * Exception thrown when field does not exist.
   */
  public function testFieldDoesNotExistException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->changeField('test', 'age1', 'age2', $this->table['fields']['age']);
  }

  /**
   * Exception thrown when field already exists.
   */
  public function testFieldExistsException() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->changeField('test', 'age', 'name', $this->table['fields']['age']);
  }

}
