<?php

/**
 * Bootstrap File
 */

$loader = require __DIR__ . '/../vendor/autoload.php' ;
$loader->add('Drupal\\Tests', __DIR__ . '/../vendor/drupal/core/tests');
$loader->add('Drupal\\TestTools', __DIR__);
