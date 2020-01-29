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

  /**
   * Tests various schema changes' effect on the table's primary key.
   *
   * @param array $initial_primary_key
   *   The initial primary key of the test table.
   * @param array $renamed_primary_key
   *   The primary key of the test table after renaming the test field.
   *
   * @dataProvider providerTestSchemaCreateTablePrimaryKey
   *
   * @covers ::addField
   * @covers ::changeField
   * @covers ::dropField
   * @covers ::findPrimaryKeyColumns
   */
  public function testSchemaChangePrimaryKey(array $initial_primary_key, array $renamed_primary_key) {
    $find_primary_key_columns = new \ReflectionMethod(get_class($this->schema), 'findPrimaryKeyColumns');
    $find_primary_key_columns->setAccessible(TRUE);
    // Test making the field the primary key of the table upon creation.
    $table_name = 'test_table';
    $table_spec = [
      'fields' => [
        'test_field' => ['type' => 'int', 'not null' => TRUE],
        'other_test_field' => ['type' => 'int', 'not null' => TRUE],
      ],
      'primary key' => $initial_primary_key,
    ];
    $this->schema->createTable($table_name, $table_spec);
    $this->assertTrue($this->schema->fieldExists($table_name, 'test_field'));
    $this->assertEquals($initial_primary_key, $find_primary_key_columns->invoke($this->schema, $table_name));
    // Change the field type and make sure the primary key stays in place.
    $this->schema->changeField($table_name, 'test_field', 'test_field', ['type' => 'varchar', 'length' => 32, 'not null' => TRUE]);
    $this->assertTrue($this->schema->fieldExists($table_name, 'test_field'));
    $this->assertEquals($initial_primary_key, $find_primary_key_columns->invoke($this->schema, $table_name));
    // Add some data and change the field type back, to make sure that changing
    // the type leaves the primary key in place even with existing data.
    $this->connection
      ->insert($table_name)
      ->fields(['test_field' => 1, 'other_test_field' => 2])
      ->execute();
    $this->schema->changeField($table_name, 'test_field', 'test_field', ['type' => 'int', 'not null' => TRUE]);
    $this->assertTrue($this->schema->fieldExists($table_name, 'test_field'));
    $this->assertEquals($initial_primary_key, $find_primary_key_columns->invoke($this->schema, $table_name));
    // Make sure that adding the primary key can be done as part of changing
    // a field, as well.
    $this->schema->dropPrimaryKey($table_name);
    $this->assertEquals([], $find_primary_key_columns->invoke($this->schema, $table_name));
    $this->schema->changeField($table_name, 'test_field', 'test_field', ['type' => 'int', 'not null' => TRUE], ['primary key' => $initial_primary_key]);
    $this->assertTrue($this->schema->fieldExists($table_name, 'test_field'));
    $this->assertEquals($initial_primary_key, $find_primary_key_columns->invoke($this->schema, $table_name));
    // Rename the field and make sure the primary key was updated.
    $this->schema->changeField($table_name, 'test_field', 'test_field_renamed', ['type' => 'int', 'not null' => TRUE]);
    $this->assertTrue($this->schema->fieldExists($table_name, 'test_field_renamed'));
    $this->assertEquals($renamed_primary_key, $find_primary_key_columns->invoke($this->schema, $table_name));
    // Drop the field and make sure the primary key was dropped, as well.
    $this->schema->dropField($table_name, 'test_field_renamed');
    $this->assertFalse($this->schema->fieldExists($table_name, 'test_field_renamed'));
    $this->assertEquals([], $find_primary_key_columns->invoke($this->schema, $table_name));
    // Add the field again and make sure adding the primary key can be done at
    // the same time.
    $this->schema->addField($table_name, 'test_field', ['type' => 'int', 'default' => 0, 'not null' => TRUE], ['primary key' => $initial_primary_key]);
    $this->assertTrue($this->schema->fieldExists($table_name, 'test_field'));
    $this->assertEquals($initial_primary_key, $find_primary_key_columns->invoke($this->schema, $table_name));
    // Drop the field again and explicitly add a primary key.
    $this->schema->dropField($table_name, 'test_field');
    $this->schema->addPrimaryKey($table_name, ['other_test_field']);
    $this->assertFalse($this->schema->fieldExists($table_name, 'test_field'));
    $this->assertEquals(['other_test_field'], $find_primary_key_columns->invoke($this->schema, $table_name));
    // Test that adding a field with a primary key will work even with a
    // pre-existing primary key.
    $this->schema->addField($table_name, 'test_field', ['type' => 'int', 'default' => 0, 'not null' => TRUE], ['primary key' => $initial_primary_key]);
    $this->assertTrue($this->schema->fieldExists($table_name, 'test_field'));
    $this->assertEquals($initial_primary_key, $find_primary_key_columns->invoke($this->schema, $table_name));
  }
  /**
   * Provides test cases for SchemaTest::testSchemaCreateTablePrimaryKey().
   *
   * @return array
   *   An array of test cases for SchemaTest::testSchemaCreateTablePrimaryKey().
   */
  public function providerTestSchemaCreateTablePrimaryKey() {
    $tests = [];
    $tests['simple_primary_key'] = [
      'initial_primary_key' => ['test_field'],
      'renamed_primary_key' => ['test_field_renamed'],
    ];
    $tests['composite_primary_key'] = [
      'initial_primary_key' => ['test_field', 'other_test_field'],
      'renamed_primary_key' => ['test_field_renamed', 'other_test_field'],
    ];
    $tests['composite_primary_key_different_order'] = [
      'initial_primary_key' => ['other_test_field', 'test_field'],
      'renamed_primary_key' => ['other_test_field', 'test_field_renamed'],
    ];
    return $tests;
  }

}
