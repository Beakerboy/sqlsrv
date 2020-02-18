<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\SchemaTest;

class FailingSchemaTest extends SchemaTest {

  public function testSchemaChangeFieldDefaultInitialNumeric() {
    $field_specs = [
      ['type' => 'int', 'size' => 'normal', 'not null' => FALSE],
      ['type' => 'int', 'size' => 'normal', 'not null' => TRUE, 'initial' => 1, 'default' => 17],
      ['type' => 'float', 'size' => 'normal', 'not null' => FALSE],
      ['type' => 'float', 'size' => 'normal', 'not null' => TRUE, 'initial' => 1, 'default' => 7.3],
      ['type' => 'numeric', 'scale' => 2, 'precision' => 10, 'not null' => FALSE],
      ['type' => 'numeric', 'scale' => 2, 'precision' => 10, 'not null' => TRUE, 'initial' => 1, 'default' => 7],
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
   * {@inhertidoc}
   *
   * @dataprovider dataProviderForDefaultInitial
  public function testSchemaChangeFieldDefaultInitial($old_spec) {
    $field_specs = [
      ['type' => 'varchar_ascii', 'length' => '255'],
      ['type' => 'varchar', 'length' => '255'],
      ['type' => 'text'],
      ['type' => 'blob', 'size' => 'big'],
    ];
    foreach ($field_specs as $j => $new_spec) {
      if ($old_spec['type'] == $new_spec['type']) {
        // Do not change a field into itself.
        continue;
      }
      // Note if the serialized data contained an object this would fail on
      // Postgres.
      // @see https://www.drupal.org/node/1031122
      $this->assertFieldChange($old_spec, $new_spec, serialize(['string' => "This \n has \\\\ some backslash \"*string action.\\n"]));
    }
  }

  public function dataProviderForDefaultInital() {
    return [
      [['type' => 'varchar_ascii', 'length' => '255']],
      [['type' => 'varchar', 'length' => '255']],
      [['type' => 'text']],
      [['type' => 'blob', 'size' => 'big']],
    ];
  }

}
