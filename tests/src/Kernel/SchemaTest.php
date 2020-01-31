<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Database\Database;
use Drupal\Core\Database\SchemaException;
use Drupal\Core\Database\SchemaObjectDoesNotExistException;
use Drupal\Core\Database\SchemaObjectExistsException;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Component\Utility\Unicode;
use Drupal\Tests\Core\Database\SchemaIntrospectionTestTrait;

/**
 * Tests table creation and modification via the schema API.
 *
 * @coversDefaultClass \Drupal\Core\Database\Schema
 *
 * @group Database
 */
class SchemaTest extends KernelTestBase {
use SchemaIntrospectionTestTrait;

  /**
   * A global counter for table and field creation.
   *
   * @var int
   */
  protected $counter;

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


  /**
   * Tests changing columns between types with default and initial values.
   */
  public function testSchemaChangeFieldDefaultInitial() {
    $field_specs = [
      ['type' => 'varchar_ascii', 'length' => '255'],
      ['type' => 'varchar', 'length' => '255'],
      ['type' => 'text'],
      ['type' => 'blob', 'size' => 'big'],
    ];

    foreach ($field_specs as $i => $old_spec) {
      foreach ($field_specs as $j => $new_spec) {
        if ($i === $j) {
          // Do not change a field into itself.
          continue;
        }
        // Note if the serialized data contained an object this would fail on
        // Postgres.
        // @see https://www.drupal.org/node/1031122
        $this->assertFieldChange($old_spec, $new_spec, serialize(['string' => "This \n has \\\\ some backslash \"*string action.\\n"]));
      }
    }

  }

  /**
   * Asserts that a field can be changed from one spec to another.
   *
   * @param $old_spec
   *   The beginning field specification.
   * @param $new_spec
   *   The ending field specification.
   */
  protected function assertFieldChange($old_spec, $new_spec, $test_data = NULL) {
    $table_name = 'test_table_' . ($this->counter++);
    $table_spec = [
      'fields' => [
        'serial_column' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'test_field' => $old_spec,
      ],
      'primary key' => ['serial_column'],
    ];
    $this->schema->createTable($table_name, $table_spec);
    $this->pass(new FormattableMarkup('Table %table created.', ['%table' => $table_name]));

    // Check the characteristics of the field.
    $this->assertFieldCharacteristics($table_name, 'test_field', $old_spec);

    // Remove inserted rows.
    $this->connection->truncate($table_name)->execute();

    if ($test_data) {
      $id = $this->connection
        ->insert($table_name)
        ->fields(['test_field'], [$test_data])
        ->execute();
    }

    // Change the field.
    $this->schema->changeField($table_name, 'test_field', 'test_field', $new_spec);

    if ($test_data) {
      $field_value = $this->connection
        ->select($table_name)
        ->fields($table_name, ['test_field'])
        ->condition('serial_column', $id)
        ->execute()
        ->fetchField();
      $this->assertIdentical($field_value, $test_data);
    }

    // Check the field was changed.
    $this->assertFieldCharacteristics($table_name, 'test_field', $new_spec);

    // Clean-up.
    $this->schema->dropTable($table_name);
  }
  
  /**
   * Asserts that a newly added field has the correct characteristics.
   */
  protected function assertFieldCharacteristics($table_name, $field_name, $field_spec) {
    // Check that the initial value has been registered.
    if (isset($field_spec['initial'])) {
      // There should be no row with a value different then $field_spec['initial'].
      $count = $this->connection
        ->select($table_name)
        ->fields($table_name, ['serial_column'])
        ->condition($field_name, $field_spec['initial'], '<>')
        ->countQuery()
        ->execute()
        ->fetchField();
      $this->assertEqual($count, 0, 'Initial values filled out.');
    }

    // Check that the initial value from another field has been registered.
    if (isset($field_spec['initial_from_field']) && !isset($field_spec['initial'])) {
      // There should be no row with a value different than
      // $field_spec['initial_from_field'].
      $count = $this->connection
        ->select($table_name)
        ->fields($table_name, ['serial_column'])
        ->where($table_name . '.' . $field_spec['initial_from_field'] . ' <> ' . $table_name . '.' . $field_name)
        ->countQuery()
        ->execute()
        ->fetchField();
      $this->assertEqual($count, 0, 'Initial values from another field filled out.');
    }
    elseif (isset($field_spec['initial_from_field']) && isset($field_spec['initial'])) {
      // There should be no row with a value different than '100'.
      $count = $this->connection
        ->select($table_name)
        ->fields($table_name, ['serial_column'])
        ->condition($field_name, 100, '<>')
        ->countQuery()
        ->execute()
        ->fetchField();
      $this->assertEqual($count, 0, 'Initial values from another field or a default value filled out.');
    }

    // Check that the default value has been registered.
    if (isset($field_spec['default'])) {
      // Try inserting a row, and check the resulting value of the new column.
      $id = $this->connection
        ->insert($table_name)
        ->useDefaults(['serial_column'])
        ->execute();
      $field_value = $this->connection
        ->select($table_name)
        ->fields($table_name, [$field_name])
        ->condition('serial_column', $id)
        ->execute()
        ->fetchField();
      $this->assertEqual($field_value, $field_spec['default'], 'Default value registered.');
    }
  }
}

