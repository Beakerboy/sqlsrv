<?php

namespace Drupal\Driver\Database\sqlsrv;

/**
 * Available transaction isolation levels.
 */
class TransactionIsolationLevel extends Enum {
  const ReadUncommitted = 'READ UNCOMMITTED';
  const ReadCommitted = 'READ COMMITTED';
  const RepeatableRead = 'REPEATABLE READ';
  const Snapshot = 'SNAPSHOT';
  const Serializable = 'SERIALIZABLE';
  const Chaos = 'CHAOS';
  const Ignore = 'IGNORE';
}