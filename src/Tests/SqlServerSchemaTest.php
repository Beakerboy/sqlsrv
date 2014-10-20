<?php

/**
 * @file
 * Definition of Drupal\sqlsrv\Tests\SqlServerSchemaTest.
 */

namespace Drupal\sqlsrv\Tests;

use Drupal\simpletest\WebTestBase;
use Drupal\Core\Database\Database;
use \Drupal\Core\Database\DatabaseException;

/**
 * Schema tests for SQL Server database driver.
 *
 * @group SQLServer
 */
class SqlServerSchemaTest extends WebTestBase {

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
    
    // Insert a value in name
    db_insert('test_table_binary')
      ->fields(array(
        'name' => 'Sandra',
      ))->execute();
    
    // Insert a value in name
    db_insert('test_table_binary')
      ->fields(array(
        'name' => 'sandra',
      ))->execute();
    
    // By default, datase collation
    // should be case insensitive, returning both rows.
    $result = db_query('SELECT COUNT(*) FROM test_table_binary WHERE name = :name', array(':name' => 'SANDRA'))->fetchField();
    $this->assertEqual($result, 2, 'Returned the correct number of total rows.');
    
    // Now let's change the field
    // to case sensistive
    db_change_field('test_table_binary', 'name', 'name', array(
          'type' => 'varchar',
          'length' => 255,
          'binary' => true
        ));
    
    // With case sensitivity, no results.
    $result = db_query('SELECT COUNT(*) FROM test_table_binary WHERE name = :name', array(':name' => 'SANDRA'))->fetchField();
    $this->assertEqual($result, 0, 'Returned the correct number of total rows.');
    
    // Now one result.
    $result = db_query('SELECT COUNT(*) FROM test_table_binary WHERE name = :name', array(':name' => 'sandra'))->fetchField();
    $this->assertEqual($result, 1, 'Returned the correct number of total rows.');
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
}