<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\KernelTests\Core\Database\DatabaseTestBase;

/**
 * Tests parameter behavior.
 *
 * @group Database
 */
class ParameterTest extends DatabaseTestBase {

  /**
   * Test for weird key names in array arguments.
   *
   * Remove any custom code related to this issue, but keep the test.
   */
  public function testBadKeysInArrayArguments() {
    $params[':nids'] = [
      'uid1' => -9,
      'What a bad placeholder name, why should we care?' => -6,
    ];
    $result = NULL;
    try {
      // The regular expandArguments implementation will fail to
      // properly expand the associative array with weird keys, OH, and actually
      // you can perform some SQL Injection through the array keys.
      $result = db_query('SELECT COUNT(*) FROM users WHERE users.uid IN (:nids)', $params)->execute()->fetchField();
    }
    catch (\Exception $err) {
      // Regular drupal will fail with
      // SQLSTATE[IMSSP]: An error occurred substituting the named parameters.
      // https://www.drupal.org/node/2146839
    }

    // User ID's are negative, so this should return 0 matches.
    $this->assertEqual($result, 0, 'Returned the correct number of total rows.');
  }

}
