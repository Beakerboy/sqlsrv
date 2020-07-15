<?php

namespace Drupal\Tests\sqlsrv\Kernel\Views;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\node\Traits\NodeCreationTrait;
use Drupal\views\Tests\ViewTestData;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * Tests the field rendering in views.
 *
 * @group field
 *
 * @todo Extend test coverage in #3046722.
 *
 * @see https://www.drupal.org/project/drupal/issues/3046722
 */
class HandlerFieldFieldTest extends KernelTestBase {

  use NodeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'field_test',
    'field_test_views',
    'filter',
    'node',
    'system',
    'text',
    'user',
    'views',
  ];

  /**
   * Views used by this test.
   *
   * @var array
   */
  public static $testViews = ['test_view_fieldapi'];

  /**
   * Test field storage.
   *
   * @var \Drupal\field\FieldStorageConfigInterface[]
   */
  protected $fieldStorages = [];

  /**
   * Test nodes.
   *
   * @var \Drupal\node\NodeInterface[]
   */
  protected $nodes = [];

  /**
   * Tests fields rendering in views.
   */
  public function testFieldRender() {
    $this->installConfig(['filter']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    NodeType::create(['type' => 'page'])->save();
    ViewTestData::createTestViews(static::class, ['field_test_views']);

    // Setup basic fields.
    $this->createFields();

    // Create some nodes.
    $this->nodes = [];
    for ($i = 0; $i < 3; $i++) {
      $values = ['type' => 'page'];

      foreach ([0, 1, 2, 5] as $key) {
        $field_storage = $this->fieldStorages[$key];
        $values[$field_storage->getName()][0]['value'] = $this->randomMachineName(8);
      }
      // Add a hidden value for the no-view field.
      $values[$this->fieldStorages[6]->getName()][0]['value'] = 'ssh secret squirrel';
      for ($j = 0; $j < 5; $j++) {
        $values[$this->fieldStorages[3]->getName()][$j]['value'] = $this->randomMachineName(8);
      }
      // Set this field to be empty.
      $values[$this->fieldStorages[4]->getName()] = [['value' => NULL]];

      $this->nodes[$i] = $this->createNode($values);
    }

    // Perform actual tests.
    $this->doTestSimpleFieldRender();
    //$this->doTestInaccessibleFieldRender();
    //$this->doTestFormatterSimpleFieldRender();
    //$this->doTestMultipleFieldRender();
  }

  /**
   * Tests simple field rendering.
   */
  public function doTestSimpleFieldRender() {
    $view = Views::getView('test_view_fieldapi');
    $this->prepareView($view);
    $view->preview();

    // Tests that the rendered fields match the actual value of the fields.
    for ($i = 0; $i < 3; $i++) {
      for ($key = 0; $key < 2; $key++) {
        $field_name = $this->fieldStorages[$key]->getName();
        $rendered_field = $view->style_plugin->getField($i, $field_name);
        $expected_field = $this->nodes[$i]->$field_name->value;
        $this->assertEquals($expected_field, $rendered_field);
      }
    }
  }

}
