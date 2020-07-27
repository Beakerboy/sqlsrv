<?php

namespace Drupal\Tests\sqlsrv\TestSuites;

/**
 * Base class for Drupal test suites.
 */
abstract class AppveyorTestSuiteBase extends TestSuiteBase {

  /**
   * Regex patterns to split up core Kernel extensions.
   *
   * @var array
   */
  protected static $coreExtensionPatterns = [
    '[a-f]',
    '[g-q]',
    '[r-z]',
  ];

 }
