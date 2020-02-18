<?php

namespace Drupal\Tests\sqlsrv\Kernel;

class FailingSchemaTest extends PassingSchemaTest {

  public function dataProviderForDefaultInitial() {
    $varchar_ascii = ['type' => 'varchar_ascii', 'length' => '255'];
    $varchar = ['type' => 'varchar', 'length' => '255'];
    $text = ['type' => 'text'];
    $blob = ['type' => 'blob', 'size' => 'big'];
    return [
      'varchar-blob' => [$varchar, $blob],
      'text-blob' => [$text, $blob],
      'blob-varchar' => [$blob, $varchar],
      'blob-text' => [$blob, $text],
    ];
  }

}
