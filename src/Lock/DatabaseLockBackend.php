<?php

/**
 * @file
 * Contains \Drupal\sqlsrv\Lock\DatabaseLockBackend.
 */

namespace Drupal\sqlsrv\Lock;

use Drupal\Core\Database\Connection;
use Drupal\Core\Lock\DatabaseLockBackend as CoreDatabaseLockBackend;
use Drupal\Core\Database\IntegrityConstraintViolationException;

/**
 * Workaround for an issue
 * with floats:
 * @see https://github.com/Azure/msphpsql/issues/71
 *
 * @ingroup lock
 */
class DatabaseLockBackend extends CoreDatabaseLockBackend {
  /**
   * {@inheritdoc}
   */
  public function lockMayBeAvailable($name) {
    $lock = $this->database->query('SELECT CONVERT(varchar(50), expire, 128) as expire, value FROM {semaphore} WHERE name = :name', array(':name' => $name))->fetchAssoc();
    if (!$lock) {
      return TRUE;
    }
    $expire = (float) $lock['expire'];
    $now = microtime(TRUE);
    if ($now > $expire) {
      // We check two conditions to prevent a race condition where another
      // request acquired the lock and set a new expire time. We add a small
      // number to $expire to avoid errors with float to string conversion.
      return (bool) $this->database->delete('semaphore')
        ->condition('name', $name)
        ->condition('value', $lock['value'])
        ->condition('expire', 0.0001 + $expire, '<=')
        ->execute();
    }
    return FALSE;
  }
}
