<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests table creation and modification via the schema API.
 *
 * @group Database
 */
class SchemaTest extends DatabaseTestBase {

  /**
   * The table definition.
   *
   * @var array
   */
  protected $table = [];

  /**
   * The sqlsrv schema.
   *
   * @var \Drupal\sqlsrv\Driver\Database\sqlsrv\Schema
   */
  protected $schema;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    /** @var \Drupal\sqlsrv\Driver\Database\sqlsrv\Schema $schema */
    $schema = $this->connection->schema();
    $this->schema = $schema;
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
    $this->schema->addField('test', 'name', $spec);

    // Verify comment is correct.
    $comment = $this->schema->getComment('test', 'name');
    $this->assertEquals('New name comment', $comment);
  }

  /**
   * Verify that comments are changed when the field is altered.
   */
  public function testChangeFieldComment() {

    // Add field with different description.
    $spec = $this->table['fields']['name'];
    $spec['description'] = 'New name comment';
    $this->schema->changeField('test', 'name', 'name', $spec);

    // Verify comment is correct.
    $comment = $this->schema->getComment('test', 'name');
    $this->assertEquals('New name comment', $comment);
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
    $schema = new \ReflectionClass('\Drupal\sqlsrv\Driver\Database\sqlsrv\Schema');
    $property = $schema->getProperty("defaultSchema");
    $property->setAccessible(TRUE);
    $property->setValue($this->schema, NULL);

    $schema_name = $this->schema->getDefaultSchema();
    $this->assertEquals($schema_name, 'dbo');
  }

  /**
   * Exception thrown when table does not exist.
   */
  public function testRenameTableAlreadyExistsException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->renameTable('tabledoesnotexist', 'test_new');
  }

  /**
   * Exception thrown when table already exists.
   */
  public function testRenameTableDoesNotExistException() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->renameTable('test_people', 'test');
  }

  /**
   * Exception thrown when field already exists.
   */
  public function testNewFieldExistsException() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->addField('test', 'name', $this->table['fields']['name']);
  }

  /**
   * Exception thrown when table does not exist.
   */
  public function testPrimaryKeyTableDoesNotExistException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->addPrimaryKey('test_new', 'name');
  }

  /**
   * Exception thrown when primary key already exists.
   */
  public function testPrimaryKeyExistsException() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->addPrimaryKey('test', 'name');
  }

  /**
   * Exception thrown when table does not exist.
   *
   * Verify that the function parameters after 'name' are correct.
   */
  public function testUniqueKeyTableDoesNotExistException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->addUniqueKey('test_new', 'name', $this->table['fields']);
  }

  /**
   * Exception thrown when unique key already exists.
   *
   * Verify that the function parameters after 'name' are correct.
   */
  public function testUniqueKeyExistsException() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->addUniqueKey('test', 'name', $this->table['fields']);
  }

  /**
   * Exception thrown when table does not exist.
   *
   * Verify that the function parameters after 'name' are correct.
   */
  public function testIndexTableDoesNotExistException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->addIndex('test_new', 'name', $this->table['fields'], $this->table);
  }

  /**
   * Exception thrown when unique key already exists.
   *
   * Verify that the function parameters after 'age' are correct.
   */
  public function testIndexExistsException() {
    $this->expectException(SchemaObjectExistsException::class);
    $this->schema->addIndex('test', 'ages', $this->table['fields'], $this->table);
  }

  /**
   * Exception thrown when table does not exist.
   */
  public function testIntroscpectSchemaException() {
    $this->expectException(SchemaObjectDoesNotExistException::class);
    $this->schema->introspectIndexSchema('test_new');
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
