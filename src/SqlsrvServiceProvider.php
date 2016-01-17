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
