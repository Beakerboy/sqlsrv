<?php

namespace Drupal\Driver\Database\sqlsrv;

/**
 * This needs a description as to why it needs to be a class.
 */
class TransactionScopeOption extends Enum {

  /** Requires New **/
  const RequiresNew = 'RequiresNew';

  /** Suppress **/
  const Supress = 'Supress';

  /** Required **/
  const Required = 'Required';

}
