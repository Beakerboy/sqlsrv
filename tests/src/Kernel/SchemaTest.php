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
   * Tests adding columns to an existing table with default and initial value.
   */
  public function testSchemaAddFieldDefaultInitialVarchar() {
    // Test varchar types.
    foreach ([1, 32, 128, 256, 512] as $length) {
      $base_field_spec = [
        'type' => 'varchar',
        'length' => $length,
      ];
      $variations = [
        ['not null' => FALSE],
        ['not null' => FALSE, 'default' => '7'],
        ['not null' => FALSE, 'default' => substr('"thing"', 0, $length)],
        ['not null' => FALSE, 'default' => substr("\"'hing", 0, $length)],
        ['not null' => TRUE, 'initial' => 'd'],
        ['not null' => FALSE, 'default' => NULL],
        ['not null' => TRUE, 'initial' => 'd', 'default' => '7'],
      ];

      foreach ($variations as $variation) {
        $field_spec = $variation + $base_field_spec;
        $this->assertFieldAdditionRemoval($field_spec);
      }
    }
  }

  /**
   * Tests adding columns to an existing table with default and initial value.
   *
   * @dataProvider intFloatVariations
   */
  public function testSchemaAddFieldDefaultInitialIntFloat($variation) {
    // Test int and float types.
    foreach (['int', 'float'] as $type) {
      foreach (['tiny', 'small', 'medium', 'normal', 'big'] as $size) {
        $base_field_spec = [
          'type' => $type,
          'size' => $size,
        ];
        
        $field_spec = $variation + $base_field_spec;
        $this->assertFieldAdditionRemoval($field_spec);
      }
    }
  }

  public function intFloatVariations() {
    return [
      [['not null' => FALSE]],
      [['not null' => FALSE, 'default' => 7]],
      [['not null' => TRUE, 'initial' => 1]],
      [['not null' => TRUE, 'initial' => 1, 'default' => 7]],
      [['not null' => TRUE, 'initial_from_field' => 'serial_column']],
      [[
        'not null' => TRUE,
        'initial_from_field' => 'test_nullable_field',
        'initial'  => 100,
      ]],
    ];
  }

  /**
   * Tests adding columns to an existing table with default and initial value.
   *
   * @dataProvider numericVariations
   */
  public function testSchemaAddFieldDefaultInitialNumeric($variation) {
    // Test numeric types.
    foreach ([1, 5, 10, 40, 65] as $precision) {
      foreach ([0, 2, 10, 30] as $scale) {
        // Skip combinations where precision is smaller than scale.
        if ($precision <= $scale) {
          continue;
        }

        $base_field_spec = [
          'type' => 'numeric',
          'scale' => $scale,
          'precision' => $precision,
        ];
        $field_spec = $variation + $base_field_spec;
        $this->assertFieldAdditionRemoval($field_spec);
      }
    }
  }

  public function numericVariation() {
    return [
          [['not null' => FALSE]],
          [['not null' => FALSE, 'default' => 7]],
          [['not null' => TRUE, 'initial' => 1]],
          [['not null' => TRUE, 'initial' => 1, 'default' => 7]],
          [['not null' => TRUE, 'initial_from_field' => 'serial_column']],
        ];
  }

  /**
   * Asserts that a given field can be added and removed from a table.
   *
   * The addition test covers both defining a field of a given specification
   * when initially creating at table and extending an existing table.
   *
   * @param $field_spec
   *   The schema specification of the field.
   */
  protected function assertFieldAdditionRemoval($field_spec) {
    // Try creating the field on a new table.
    $table_name = 'test_table_' . ($this->counter++);
    $table_spec = [
      'fields' => [
        'serial_column' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'test_nullable_field' => ['type' => 'int', 'not null' => FALSE],
        'test_field' => $field_spec,
      ],
      'primary key' => ['serial_column'],
    ];
    $this->schema->createTable($table_name, $table_spec);
    $this->pass(new FormattableMarkup('Table %table created.', ['%table' => $table_name]));

    // Check the characteristics of the field.
    $this->assertFieldCharacteristics($table_name, 'test_field', $field_spec);

    // Clean-up.
    $this->schema->dropTable($table_name);

    // Try adding a field to an existing table.
    $table_name = 'test_table_' . ($this->counter++);
    $table_spec = [
      'fields' => [
        'serial_column' => ['type' => 'serial', 'unsigned' => TRUE, 'not null' => TRUE],
        'test_nullable_field' => ['type' => 'int', 'not null' => FALSE],
      ],
      'primary key' => ['serial_column'],
    ];
    $this->schema->createTable($table_name, $table_spec);
    $this->pass(new FormattableMarkup('Table %table created.', ['%table' => $table_name]));

    // Insert some rows to the table to test the handling of initial values.
    for ($i = 0; $i < 3; $i++) {
      $this->connection
        ->insert($table_name)
        ->useDefaults(['serial_column'])
        ->fields(['test_nullable_field' => 100])
        ->execute();
    }

    // Add another row with no value for the 'test_nullable_field' column.
    $this->connection
      ->insert($table_name)
      ->useDefaults(['serial_column'])
      ->execute();

    $this->schema->addField($table_name, 'test_field', $field_spec);
    $this->pass(new FormattableMarkup('Column %column created.', ['%column' => 'test_field']));

    // Check the characteristics of the field.
    $this->assertFieldCharacteristics($table_name, 'test_field', $field_spec);

    // Clean-up.
    $this->schema->dropField($table_name, 'test_field');

    // Add back the field and then try to delete a field which is also a primary
    // key.
    $this->schema->addField($table_name, 'test_field', $field_spec);
    $this->schema->dropField($table_name, 'serial_column');
    $this->schema->dropTable($table_name);
  }

}
