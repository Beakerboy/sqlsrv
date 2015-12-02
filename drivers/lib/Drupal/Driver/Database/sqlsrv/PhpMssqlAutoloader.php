<?php

$loader = new \Composer\Autoload\ClassLoader();
$loader->addPsr4('mssql\\', __DIR__ . DIRECTORY_SEPARATOR . 'mssql' . DIRECTORY_SEPARATOR . 'src');
$loader->register();
