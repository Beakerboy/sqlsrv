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
      fwrite(STDOUT, print_r($values, TRUE));
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
        $this->assertEquals($expected_field, $rendered_field, "Field name, " . $field_name . ", with key " . $key . " and index " . $i);
      }
    }
  }

  /**
   * Sets up the testing view with random field data.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The view to add field data to.
   */
  protected function prepareView(ViewExecutable $view) {
    $view->storage->invalidateCaches();
    $view->initDisplay();
    foreach ($this->fieldStorages as $field_storage) {
      $field_name = $field_storage->getName();
      $view->display_handler->options['fields'][$field_name]['id'] = $field_name;
      $view->display_handler->options['fields'][$field_name]['table'] = 'node__' . $field_name;
      $view->display_handler->options['fields'][$field_name]['field'] = $field_name;
    }
  }

  /**
   * Creates the testing fields.
   */
  protected function createFields() {
    $fields_data = [
      [
        'field_name' => 'field_name_0',
        'type' => 'string',
      ],
      [
        'field_name' => 'field_name_1',
        'type' => 'string',
      ],
      [
        'field_name' => 'field_name_2',
        'type' => 'string',
      ],
      // Field with cardinality > 1.
      [
        'field_name' => 'field_name_3',
        'type' => 'string',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      ],
      // Field that will have no value.
      [
        'field_name' => 'field_name_4',
        'type' => 'string',
        'cardinality' => FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED,
      ],
      // Text field.
      [
        'field_name' => 'field_name_5',
        'type' => 'text',
      ],
      // Text field with access control.
      // @see field_test_entity_field_access()
      [
        'field_name' => 'field_no_view_access',
        'type' => 'text',
      ],
    ];

    foreach ($fields_data as $field_data) {
      $field_data += ['entity_type' => 'node'];
      $field_storage = FieldStorageConfig::create($field_data);
      $field_storage->save();
      $this->fieldStorages[] = $field_storage;
      FieldConfig::create([
        'field_storage' => $field_storage,
        'bundle' => 'page',
      ])->save();
    }
  }

}
