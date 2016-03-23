INSTALLATION

  Drop the driver Folder into the root of your Drupal installation.

  Drop the PhpMssql library in \drivers\lib\Drupal\Driver\Database\sqlsrv inside a folder named mssql, so that
  you end up with files such as:

  \drivers\lib\Drupal\Driver\Database\sqlsrv\mssql\src\Connection.php
  \drivers\lib\Drupal\Driver\Database\sqlsrv\mssql\src\Scheme.php
  \drivers\lib\Drupal\Driver\Database\sqlsrv\mssql\src\Utils.php
  ...

  You can get a copy from here: http://www.drupalonwindows.com/en/content/phpmssql

UDPATING THE DRIVER

  To update the driver you will need to manually copy the \drivers\lib\Drupal\Driver\Database\sqlsrv folder
  and to manually update the PhpMssql library when updates are available.

  Using Drupal's update module will only update the sqlsrv module, but not the driver.