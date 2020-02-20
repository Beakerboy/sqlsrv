<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\SchemaTest;

/**
 * Passing tests from SchemaTest.php.
 *
 * There are failing tests within the SchemaTest Kernel test.
 * This class separates the passing tests from the failing tests
 * to allow the dev team to work on the issues.
 */
class PassingSchemaTest extends SchemaTest {

  /**
   * {@inheritdoc}
   */
  public function testFindPrimaryKeyColumns() {
    parent::testFindPrimaryKeyColumns();
  }

  /**
   * {@inheritdoc}
   *
   * @dataProvider providerTestSchemaCreateTablePrimaryKey
   */
  public function testSchemaChangePrimaryKey(array $initial_primary_key, array $renamed_primary_key) {
    parent::testSchemaChangePrimaryKey($initial_primary_key, $renamed_primary_key);
  }

  /**
   * Change default value with numeric values.
   */
  public function testSchemaChangeFieldDefaultInitialNumeric() {
    $field_specs = [
      ['type' => 'int', 'size' => 'normal', 'not null' => FALSE],
      [
        'type' => 'int',
        'size' => 'normal',
        'not null' => TRUE,
        'initial' => 1,
        'default' => 17,
      ],
      ['type' => 'float', 'size' => 'normal', 'not null' => FALSE],
      [
        'type' => 'float',
        'size' => 'normal',
        'not null' => TRUE,
        'initial' => 1,
        'default' => 7.3,
      ],
      [
        'type' => 'numeric',
        'scale' => 2,
        'precision' => 10,
        'not null' => FALSE,
      ],
      [
        'type' => 'numeric',
        'scale' => 2,
        'precision' => 10,
        'not null' => TRUE,
        'initial' => 1,
        'default' => 7,
      ],
    ];
    foreach ($field_specs as $i => $old_spec) {
      foreach ($field_specs as $j => $new_spec) {
        if ($i === $j) {
          // Do not change a field into itself.
          continue;
        }
        $this->assertFieldChange($old_spec, $new_spec);
      }
    }
  }

  /**
   * {@inheritdoc}
   *
   * @dataProvider dataProviderForDefaultInitial
   */
  public function testSchemaChangeFieldDefaultInitial($old_spec = [], $new_spec = []) {
    // Note if the serialized data contained an object this would fail on
    // Postgres.
    // @see https://www.drupal.org/node/1031122
    $this->assertFieldChange($old_spec, $new_spec, serialize(['string' => "This \n has \\\\ some backslash \"*string action.\\n"]));
  }

  /**
   * Data Provider.
   */
  public function dataProviderForDefaultInitial() {
    $varchar_ascii = ['type' => 'varchar_ascii', 'length' => '255'];
    $varchar = ['type' => 'varchar', 'length' => '255'];
    $text = ['type' => 'text'];
    $blob = ['type' => 'blob', 'size' => 'big'];
    return [
      'varchar_ascii-varchar' => [$varchar_ascii, $varchar],
      'varchar_ascii-text' => [$varchar_ascii, $text],
      'varchar_ascii-blob' => [$varchar_ascii, $blob],
      'varchar-varchar_ascii' => [$varchar, $varchar_ascii],
      'varchar-text' => [$varchar, $text],
      'text-varchar_ascii' => [$text, $varchar_ascii],
      'text-varchar' => [$text, $varchar],
      'blob-varchar_ascii' => [$blob, $varchar_ascii],
    ];
  }

}
