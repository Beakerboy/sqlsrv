<?php

namespace Drupal\Driver\Database\sqlsrv;

/**
 * This needs a description as to why it needs to be a class.
 */
class TransactionScopeOption extends Enum {

  /**
   * Requires New.
   *
   * @var string
   */
  const RequiresNew = 'RequiresNew';

  /**
   * Suppress.
   *
   * @var string
   */
  const Supress = 'Supress';

  /**
   * Required.
   *
   * @var string
   */
  const Required = 'Required';

}
