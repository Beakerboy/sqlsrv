<?php

/**
 * Bootstrap File
 */
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
  echo "First Autoloader Found :-)";
  require_once __DIR__ . '/../vendor/autoload.php' ;
} elseif (file_exists(__DIR__ . '/../../../autoload.php')) {
    echo "Second Autoloader Found :-|";
    require_once __DIR__ . '/../../../autoload.php';
} else {
    echo "No Autoloader Found :-(";
}
