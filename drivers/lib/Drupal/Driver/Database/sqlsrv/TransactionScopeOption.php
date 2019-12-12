<?php

namespace Drupal\Driver\Database\sqlsrv;

/**
 * This needs a description as to why it needs to be a class.
 */
class TransactionScopeOption extends Enum {
  const RequiresNew = 'RequiresNew';
  const Supress = 'Supress';
  const Required = 'Required';

}
