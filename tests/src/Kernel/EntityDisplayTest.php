<?php

namespace Drupal\Tests\sqlsrv\Kernel;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\Display\EntityDisplayInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Entity\Entity\EntityViewDisplay;
use Drupal\Core\Entity\Entity\EntityViewMode;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\Role;

/**
 * Tests the entity display configuration entities.
 *
 * @group field_ui
 */
class EntityDisplayTest extends KernelTestBase {

  /**
   * Modules to install.
   *
   * @var string[]
   */
  protected static $modules = [
    'field_ui',
    'field',
    'entity_test',
    'user',
    'text',
    'field_test',
    'node',
    'system',
  ];

  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('entity_test');
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig(['field', 'node', 'user']);
  }

  /**
   * Tests components dependencies additions.
   */
  public function testComponentDependencies() {
    $this->enableModules(['dblog', 'color']);
    $this->installSchema('dblog', ['watchdog']);
    $this->installEntitySchema('user');
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = [];
    // Create two arbitrary user roles.
    for ($i = 0; $i < 2; $i++) {
      $roles[$i] = Role::create([
        'id' => mb_strtolower($this->randomMachineName()),
        'label' => $this->randomString(),
      ]);
      $roles[$i]->save();
    }
    // Create a field of type 'test_field' attached to 'entity_test'.
    $field_name = mb_strtolower($this->randomMachineName());
    FieldStorageConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test',
      'type' => 'test_field',
    ])->save();
    FieldConfig::create([
      'field_name' => $field_name,
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
    // Create a new form display without components.
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
    $form_display = EntityFormDisplay::create([
      'targetEntityType' => 'entity_test',
      'bundle' => 'entity_test',
      'mode' => 'default',
    ]);
    $form_display->save();
    $dependencies = ['user.role.' . $roles[0]->id(), 'user.role.' . $roles[1]->id()];
    // The config object should not depend on none of the two $roles.
    $this->assertNoDependency('config', $dependencies[0], $form_display);
    $this->assertNoDependency('config', $dependencies[1], $form_display);
    // Add a widget of type 'test_field_widget'.
    $component = [
      'type' => 'test_field_widget',
      'settings' => [
        'test_widget_setting' => $this->randomString(),
        'role' => $roles[0]->id(),
        'role2' => $roles[1]->id(),
      ],
      'third_party_settings' => [
        'color' => ['foo' => 'bar'],
      ],
    ];
    $form_display->setComponent($field_name, $component);
    $form_display->save();
    // Now, the form display should depend on both user roles $roles.
    $this->assertDependency('config', $dependencies[0], $form_display);
    $this->assertDependency('config', $dependencies[1], $form_display);
    // The form display should depend on 'color' module.
    $this->assertDependency('module', 'color', $form_display);
    // Delete the first user role entity.
    $roles[0]->delete();
    // Reload the form display.
    $form_display = EntityFormDisplay::load($form_display->id());
    // The display exists.
    $this->assertFalse(empty($form_display));
    // The form display should not depend on $role[0] anymore.
    $this->assertNoDependency('config', $dependencies[0], $form_display);
    // The form display should depend on 'anonymous' user role.
    $this->assertDependency('config', 'user.role.anonymous', $form_display);
    // The form display should depend on 'color' module.
    $this->assertDependency('module', 'color', $form_display);
    // Manually trigger the removal of configuration belonging to the module
    // because KernelTestBase::disableModules() is not aware of this.
    $this->container->get('config.manager')->uninstall('module', 'color');
    // Uninstall 'color' module.
    $this->disableModules(['color']);
    // Reload the form display.
    $form_display = EntityFormDisplay::load($form_display->id());
    // The display exists.
    $this->assertFalse(empty($form_display));
    // The component is still enabled.
    $this->assertNotNull($form_display->getComponent($field_name));
    // The form display should not depend on 'color' module anymore.
    $this->assertNoDependency('module', 'color', $form_display);
    // Delete the 2nd user role entity.
    $roles[1]->delete();
    Database::startLog('testing');
    // Reload the form display.
    $form_display = EntityFormDisplay::load($form_display->id());
    // The display exists.
    $this->assertFalse(empty($form_display));
    // The component has been disabled.
    $this->assertNull($form_display->getComponent($field_name));
    $this->assertTrue($form_display->get('hidden')[$field_name]);
    // The correct warning message has been logged.
    $arguments = ['@display' => (string) t('Entity form display'), '@id' => $form_display->id(), '@name' => $field_name];
    $logged = (bool) Database::getConnection()->select('watchdog', 'w')
      ->fields('w', ['wid'])
      ->condition('type', 'system')
      ->condition('message', "@display '@id': Component '@name' was disabled because its settings depend on removed dependencies.")
      ->condition('variables', serialize($arguments))
      ->execute()
      ->fetchAll();
    $log = Database::getLog('testing');
    // fwrite(STDOUT, print_r($log, TRUE));
    $query = "SELECT * from {watchdog}";
    $results = Database::getConnection()->query($query)->fetchAll();
    while ($row = $results->fetchAssoc()) {
      fwrite(STDOUT, print_r($row, TRUE) . "\n");
    }
    $this->assertTrue($logged);
  }

  /**
   * Asserts that $key is a $type type dependency of $display config entity.
   *
   * @param string $type
   *   The dependency type: 'config', 'content', 'module' or 'theme'.
   * @param string $key
   *   The string to be checked.
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $display
   *   The entity display object to get dependencies from.
   *
   * @return bool
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertDependency($type, $key, EntityDisplayInterface $display) {
    return $this->assertDependencyHelper(TRUE, $type, $key, $display);
  }

  /**
   * Asserts that $key is not a $type type dependency of $display config entity.
   *
   * @param string $type
   *   The dependency type: 'config', 'content', 'module' or 'theme'.
   * @param string $key
   *   The string to be checked.
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $display
   *   The entity display object to get dependencies from.
   *
   * @return bool
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertNoDependency($type, $key, EntityDisplayInterface $display) {
    return $this->assertDependencyHelper(FALSE, $type, $key, $display);
  }
  /**
   * Provides a helper for dependency assertions.
   *
   * @param bool $assertion
   *   Assertion: positive or negative.
   * @param string $type
   *   The dependency type: 'config', 'content', 'module' or 'theme'.
   * @param string $key
   *   The string to be checked.
   * @param \Drupal\Core\Entity\Display\EntityDisplayInterface $display
   *   The entity display object to get dependencies from.
   *
   * @return bool
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertDependencyHelper($assertion, $type, $key, EntityDisplayInterface $display) {
    $all_dependencies = $display->getDependencies();
    $dependencies = !empty($all_dependencies[$type]) ? $all_dependencies[$type] : [];
    $context = $display instanceof EntityViewDisplayInterface ? 'View' : 'Form';
    $value = $assertion ? in_array($key, $dependencies) : !in_array($key, $dependencies);
    $args = ['@context' => $context, '@id' => $display->id(), '@type' => $type, '@key' => $key];
    $message = $assertion ? new FormattableMarkup("@context display '@id' depends on @type '@key'.", $args) : new FormattableMarkup("@context display '@id' do not depend on @type '@key'.", $args);
    return $this->assert($value, $message);
  }

}
