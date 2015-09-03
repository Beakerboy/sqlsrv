<?php

namespace Drupal\Driver\Database\sqlsrv;

class TransactionScopeOption extends Enum {
  const RequiresNew = 'RequiresNew';
  const Supress = 'Supress';
  const Required = 'Required';
}