<?php

/**
 * @file
 * Definition of Drupal\sqlsrv\Tests\SchemaTest.
 */

namespace Drupal\sqlsrv\Tests;

use Drupal\Core\Database\DatabaseException;
use Drupal\KernelTests\KernelTestBase;
use mssql\Settings\ConstraintTypes;

/**
 * Schema tests for SQL Server database driver.
 *
 * @group SQLServer
 */
class SchemaTest extends KernelTestBase {

  public static function getInfo() {
    return [
      'name' => 'Schema tests',
      'description' => 'Generic tests for SQL Server Schema.',
      'group' => 'SQLServer'
    ];
  }

  /**
   * Test adding / removing / readding a primary key to a table.
   */
  public function testPrimaryKeyHandling() {
    $table_spec = array(
      'fields' => array(
        'id'  => array(
          'type' => 'int',
          'not null' => TRUE,
        ),
      ),
    );

    db_create_table('test_table', $table_spec);
    $this->assertTrue(db_table_exists('test_table'), t('Creating a table without a primary key works.'));

    db_add_primary_key('test_table', array('id'));
    $this->pass(t('Adding a primary key should work when the table has no data.'));

    // Try adding a row.
    db_insert('test_table')->fields(array('id' => 1))->execute();
    // The second row with the same value should conflict.
    try {
      db_insert('test_table')->fields(array('id' => 1))->execute();
      $this->fail(t('Duplicate values in the table should not be allowed when the primary key is there.'));
    }
    catch (DatabaseException $e) {}

    // Drop the primary key and retry.
    db_drop_primary_key('test_table');
    $this->pass(t('Removing a primary key should work.'));

    db_insert('test_table')->fields(array('id' => 1))->execute();
    $this->pass(t('Adding a duplicate row should work without the primary key.'));

    try {
      db_add_primary_key('test_table', array('id'));
      $this->fail(t('Trying to add a primary key should fail with duplicate rows in the table.'));
    }
    catch (DatabaseException $e) {}
  }

  /**
   * Test altering a primary key.
   */
  public function testPrimaryKeyAlter() {
    $table_spec = array(
      'fields' => array(
        'id'  => array(
          'type' => 'int',
          'not null' => TRUE,
        ),
      ),
      'primary key' => array('id'),
    );

    db_create_table('test_table', $table_spec);

    // Add a default value.
    db_change_field('test_table', 'id', 'id', array(
      'type' => 'int',
      'not null' => TRUE,
      'default' => 1,
    ));
  }

  /**
   * Test adding / modifying an unsigned column.
   */
  public function testUnsignedField() {
    $table_spec = array(
      'fields' => array(
        'id'  => array(
          'type' => 'int',
          'not null' => TRUE,
          'unsigned' => TRUE,
        ),
      ),
    );

    db_create_table('test_table', $table_spec);

    try {
      db_insert('test_table')->fields(array('id' => -1))->execute();
      $failed = FALSE;
    }
    catch (DatabaseException $e) {
      $failed = TRUE;
    }
    $this->assertTrue($failed, t('Inserting a negative value in an unsigned field failed.'));

    $this->assertUnsignedField('test_table', 'id');

    try {
      db_insert('test_table')->fields(array('id' => 1))->execute();
      $failed = FALSE;
    }
    catch (DatabaseException $e) {
      $failed = TRUE;
    }
    $this->assertFalse($failed, t('Inserting a positive value in an unsigned field succeeded.'));

    // Change the field to signed.
    db_change_field('test_table', 'id', 'id', array(
      'type' => 'int',
      'not null' => TRUE,
    ));

    $this->assertSignedField('test_table', 'id');

    // Change the field back to unsigned.
    db_change_field('test_table', 'id', 'id', array(
      'type' => 'int',
      'not null' => TRUE,
      'unsigned' => TRUE,
    ));

    $this->assertUnsignedField('test_table', 'id');
  }

  /**
   * Summary of assertUnsignedField
   *
   * @param string $table
   * @param string $field_name
   */
  protected function assertUnsignedField($table, $field_name) {
    try {
      db_insert('test_table')->fields(array('id' => -1))->execute();
      $success = TRUE;
    }
    catch (DatabaseException $e) {
      $success = FALSE;
    }
    $this->assertFalse($success, t('Inserting a negative value in an unsigned field failed.'));

    try {
      db_insert('test_table')->fields(array('id' => 1))->execute();
      $success = TRUE;
    }
    catch (DatabaseException $e) {
      $success = FALSE;
    }
    $this->assertTrue($success, t('Inserting a positive value in an unsigned field succeeded.'));

    db_delete('test_table')->execute();
  }

  /**
   * Summary of assertSignedField
   *
   * @param string $table
   * @param string $field_name
   */
  protected function assertSignedField($table, $field_name) {
    try {
      db_insert('test_table')->fields(array('id' => -1))->execute();
      $success = TRUE;
    }
    catch (DatabaseException $e) {
      $success = FALSE;
    }
    $this->assertTrue($success, t('Inserting a negative value in a signed field succeeded.'));

    try {
      db_insert('test_table')->fields(array('id' => 1))->execute();
      $success = TRUE;
    }
    catch (DatabaseException $e) {
      $success = FALSE;
    }
    $this->assertTrue($success, t('Inserting a positive value in a signed field succeeded.'));

    db_delete('test_table')->execute();
  }

  /**
   * Test db_add_field() and db_change_field() with indexes.
   */
  public function testAddChangeWithIndex() {
    $table_spec = array(
      'fields' => array(
        'id'  => array(
          'type' => 'int',
          'not null' => TRUE,
        ),
      ),
      'primary key' => array('id'),
    );

    db_create_table('test_table', $table_spec);

    // Add a default value.
    db_add_field('test_table', 'test', array(
      'type' => 'int',
      'not null' => TRUE,
      'default' => 1,
    ), array(
      'indexes' => array(
        'id_test' => array('id, test'),
      ),
    ));

    $this->assertTrue(db_index_exists('test_table', 'id_test'), t('The index has been created by db_add_field().'));

    // Change the definition, we have by contract to remove the indexes before.
    db_drop_index('test_table', 'id_test');
    $this->assertFalse(db_index_exists('test_table', 'id_test'), t('The index has been dropped.'));

    db_change_field('test_table', 'test', 'test', array(
      'type' => 'int',
      'not null' => TRUE,
      'default' => 1,
    ), array(
      'indexes' => array(
        'id_test' => array('id, test'),
      ),
    ));

    $this->assertTrue(db_index_exists('test_table', 'id_test'), t('The index has been recreated by db_change_field().'));
  }


  /**
   * Performs a count query over the predefined result set
   * and verifies that the number of results matches.
   *
   * @param mixed[] $results
   *
   * @param string $type
   *   Can either be:
   *     "CS_AS" -> Case sensitive / Accent sensitive
   *     "CI_AI" -> Case insensitive / Accent insesitive
   *     "CI_AS" -> Case insensitive / Accent sensitive
   */
  private function AddChangeWithBinarySearchHelper(array $results, string $type, string $fieldtype) {
    foreach ($results as $search => $result) {
      // By default, datase collation
      // should be case insensitive returning both rows.
      $count = db_query('SELECT COUNT(*) FROM {test_table_binary} WHERE name = :name', [':name' => $search])->fetchField();
      $this->assertEqual($count, $result[$type], "Returned the correct number of total rows for a {$type} search on fieldtype {$fieldtype}");
    }
  }

  /**
   * Test db_add_field() and db_change_field() with binary spec.
   */
  public function testAddChangeWithBinary() {
    $table_spec = array(
      'fields' => array(
        'id'  => array(
          'type' => 'serial',
          'not null' => TRUE,
        ),
        'name' => array(
          'type' => 'varchar',
          'length' => 255,
          'binary' => false
        ),
      ),
      'primary key' => array('id'),
    );

    db_create_table('test_table_binary', $table_spec);

    $samples = ["Sandra", "sandra", "sÁndra"];

    foreach ($samples as $sample) {
      db_insert('test_table_binary')->fields(['name' => $sample])->execute();
    }

    // Strings to be tested.
    $results = [
      "SaNDRa" => ["CS_AS" => 0, "CI_AI" => 3, "CI_AS" => 2],
      "SÁNdRA" => ["CS_AS" => 0, "CI_AI" => 3, "CI_AS" => 1],
      "SANDRA" => ["CS_AS" => 0, "CI_AI" => 3, "CI_AS" => 2],
      "sandra" => ["CS_AS" => 1, "CI_AI" => 3, "CI_AS" => 2],
      "Sandra" => ["CS_AS" => 1, "CI_AI" => 3, "CI_AS" => 2],
      "sÁndra" => ["CS_AS" => 1, "CI_AI" => 3, "CI_AS" => 1],
      "pedro" => ["CS_AS" => 0, "CI_AI" => 0, "CI_AS" => 0],
      ];

    // Test case insensitive.
    $this->AddChangeWithBinarySearchHelper($results, "CI_AI", "varchar");

    // Now let's change the field
    // to case sensistive / accent sensitive.
    db_change_field('test_table_binary', 'name', 'name', [
          'type' => 'varchar',
          'length' => 255,
          'binary' => true
        ]);

    // Test case sensitive.
    $this->AddChangeWithBinarySearchHelper($results, "CS_AS", "varchar:binary");

    // Let's make this even harder, convert to BLOB and back to text.
    // Blob is binary so works like CS/AS
    db_change_field('test_table_binary', 'name', 'name', [
      'type' => 'blob',
    ]);

    // Test case sensitive. Varbinary behaves as Case Insensitive / Accent Sensitive.
    // NEVER store text as blob, it behaves as CI_AI!!!
    $this->AddChangeWithBinarySearchHelper($results, "CI_AI", "blob");

    // Back to Case Insensitive / Accent Insensitive
    db_change_field('test_table_binary', 'name', 'name', [
          'type' => 'varchar',
          'length' => 255,
        ]);

    // Test case insensitive.
    $this->AddChangeWithBinarySearchHelper($results, "CI_AI", "varchar");


    // Test varchar_ascii support
    db_change_field('test_table_binary', 'name', 'name', [
      'type' => 'varchar_ascii'
    ]);


    // Test case insensitive.
    $this->AddChangeWithBinarySearchHelper($results, "CS_AS", "varchar_ascii");

  }

  /**
   * Test numeric field precision.
   */
  public function testNumericFieldPrecision() {
    $table_spec = array(
      'fields' => array(
        'id'  => array(
          'type' => 'serial',
          'not null' => TRUE,
        ),
        'name' => array(
          'type' => 'numeric',
          'precision' => 400,
          'scale' => 2
        ),
      ),
      'primary key' => array('id'),
    );

    $success = FALSE;
    try {
      db_create_table('test_table_binary', $table_spec);
      $success = TRUE;
    }
    catch (Exception $error) {
      $success = FALSE;
    }

    $this->assertTrue($success, t('Able to create a numeric field with an out of bounds precision.'));
  }

  /**
   * Tests that inserting non UTF8 strings
   * on a table that does not exists triggers
   * the proper error and not a string conversion
   * error.
   */
  public function testInsertBadCharsIntoNonExistingTable() {

    try {
      $query = db_insert('GHOST_TABLE');
      $query->fields(array('FIELD' => gzcompress('compresing this string into zip!')));
      $query->execute();
    }
    catch (\Exception $e) {
      if (!($e instanceof \Drupal\Core\Database\SchemaObjectDoesNotExistException)) {
        $this->fail('Inserting into a non existent table does not trigger the right type of Exception.');
      }
    }

    try {
      $query = db_update('GHOST_TABLE');
      $query->fields(array('FIELD' => gzcompress('compresing this string into zip!')));
      $query->execute();
    }
    catch (\Exception $e) {
      if (!($e instanceof \Drupal\Core\Database\SchemaObjectDoesNotExistException)) {
        $this->fail('Updating into a non existent table does not trigger the right type of Exception.');
      }
    }
  }

  /**
   * @ee https://github.com/Azure/msphpsql/issues/50
   *
   * Some transactions will get DOOMED if an exception is thrown
   * and the PDO driver will internally rollback and issue
   * a new transaction. That is a BIG bug.
   *
   * One of the most usual cases is when trying to query
   * with a string against an integer column.
   *
   */
  public function testTransactionDoomed() {

    $table_spec = array(
      'fields' => array(
        'id'  => array(
          'type' => 'serial',
          'not null' => TRUE,
        ),
        'name' => array(
          'type' => 'varchar',
          'length' => 255,
          'binary' => false
        ),
      ),
      'primary key' => array('id'),
    );

    db_create_table('test_table', $table_spec);

    // Let's do it!
    $query = db_insert('test_table');
    $query->fields(array('name' => 'JUAN'));
    $id = $query->execute();

    // Change the name
    $transaction = db_transaction();

    db_query('UPDATE {test_table} SET name = :p0 WHERE id = :p1', array(':p0' => 'DAVID', ':p1' => $id));

    $name = db_query('SELECT TOP(1) NAME from {test_table}')->fetchField();
    $this->assertEqual($name, 'DAVID');

    // Let's throw an exception that DOES NOT doom the transaction
    try {
      $name = db_query('SELECT COUNT(*) FROM THIS_TABLE_DOES_NOT_EXIST')->fetchField();
    }
    catch (\Exception $e) {

    }

    $name = db_query('SELECT TOP(1) NAME from {test_table}')->fetchField();
    $this->assertEqual($name, 'DAVID');

    // Lets doom this transaction.
    try {
      db_query('UPDATE {test_table} SET name = :p0 WHERE id = :p1', array(':p0' => 'DAVID', ':p1' => 'THIS IS NOT AND WILL NEVER BE A NUMBER'));
    }
    catch (\Exception $e) {

    }

    // What should happen here is that
    // any further attempt to do something inside the
    // scope of this transaction MUST throw an exception.
    $failed = FALSE;
    try {
      $name = db_query('SELECT TOP(1) NAME from {test_table}')->fetchField();
      $this->assertEqual($name, 'DAVID');
    }
    catch (\Exception $e) {
      if (!($e instanceof \mssql\DoomedTransactionException)) {
        $this->fail('Wrong exception when testing doomed transactions.');
      }
      $failed = TRUE;
    }

    $this->assertTrue($failed, 'Operating on the database after the transaction is doomed throws an exception.');

    // Trying to unset the transaction without an explicit rollback should trigger
    // an exception.
    $failed = FALSE;
    try {
      unset($transaction);
    }
    catch (\Exception $e) {
      if (!($e instanceof \mssql\DoomedTransactionException)) {
        $this->fail('Wrong exception when testing doomed transactions.');
      }
      $failed = TRUE;
    }

    $this->assertTrue($failed, 'Trying to commit a doomed transaction throws an Exception.');

    //$query = db_select('test_table', 't');
    //$query->addField('t', 'name');
    //$name = $query->execute()->fetchField();

    //$this->assertEqual($name, 'DAVID');

    //unset($transaction);


  }

  /**
   * At some point having default values with braces
   * was completely broken!
   */
  public function testDefaultValuesWithBraces() {

    $data = array('a' => 'b', 'c' => array());

    $table_spec = array(
      'fields' => array(
        'id'  => array(
          'type' => 'serial',
          'not null' => TRUE,
        ),
        'data' => array(
          'type' => 'blob',
          'default' => serialize($data)
        ),
      ),
      'primary key' => array('id'),
    );

    db_create_table('test_table', $table_spec);

    db_query('DELETE FROM {test_table}');
    db_query('INSERT INTO {test_table} DEFAULT VALUES');
    $sample = db_query('SELECT TOP(1) [data] FROM {test_table}')->fetchField();
    $sample_unserialized = unserialize($sample);
    $this->assertEqual($data, $sample_unserialized);

    // Change thhe default value using a ChangeField
    $data = array('kk' => array('p' => 'a'), 'cc' => array());
    db_change_field('test_table', 'data', 'data', array(
        'type' => 'blob',
        'default' => serialize($data),
        'not null' => TRUE,
      ));

    db_query('DELETE FROM {test_table}');
    db_query('INSERT INTO {test_table} DEFAULT VALUES');
    $sample = db_query('SELECT TOP(1) [data] FROM {test_table}')->fetchField();
    $sample_unserialized = unserialize($sample);
    $this->assertEqual($data, $sample_unserialized);

    // Change the default value an set it forcefully
    $data = array('j' => array(), 'c' => array());
    db_field_set_default('test_table', 'data', serialize($data));

    // Make sure that the name of the constraint is correct
    /** @var \Drupal\Driver\Database\sqlsrv\Connection */
    $connection = \Drupal\Core\Database\Database::getConnection();
    $real_table = $connection->prefixTable('test_table');
    $constraint_name = "{$real_table}_data_df";

    $exists = $connection->Scheme()->ConstraintExists($constraint_name, new ConstraintTypes(ConstraintTypes::CDEFAULT));
    $this->assertEqual($exists, TRUE, 'The default value constraint has the correct name.');

    db_query('DELETE FROM {test_table}');
    db_query('INSERT INTO {test_table} DEFAULT VALUES');
    $sample = db_query('SELECT TOP(1) [data] FROM {test_table}')->fetchField();
    $sample_unserialized = unserialize($sample);
    $this->assertEqual($data, $sample_unserialized);
  }
}