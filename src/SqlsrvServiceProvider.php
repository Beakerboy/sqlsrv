<?php

namespace Drupal\sqlsrv;

use Drupal\Core\DependencyInjection\ServiceModifierInterface;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;

class SqlsrvServiceProvider implements ServiceProviderInterface {
  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    // Float columns are only broken in the PDO driver version prior to 4.0.0
    // the actual version number differs from Linux/Windows as they keep different
    // versioning...
    // TODO: Remove this in the future, these lock overrides are not needed
    // anymore after the fixes in the PDO driver.
    // @see https://github.com/Microsoft/msphpsql/releases/tag/4.1.0
    if (($version = phpversion("pdo_sqlsrv")) && version_compare($version, '4.0.0', '<')) {
      $definition = $container->getDefinition('lock');
      if ($definition->getClass() == \Drupal\Core\Lock\DatabaseLockBackend::class) {
        $definition->setClass(\Drupal\sqlsrv\Lock\DatabaseLockBackend::class);
      }
      $definition = $container->getDefinition('lock.persistent');
      if ($definition->getClass() == \Drupal\Core\Lock\PersistentDatabaseLockBackend::class) {
        $definition->setClass(\Drupal\sqlsrv\Lock\PersistentDatabaseLockBackend::class);
      }
    }
  }
}
