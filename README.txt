Drop the driver Folder into the root of your Drupal installation.

Drop the mssql library in \drivers\lib\Drupal\Driver\Database\sqlsrv.

You can get a copy from here: http://www.drupalonwindows.com/en/content/phpmssql

After setting up Drupal you will need to manually INSTALL the MSSQL server module. To do so go to admin/modules.

Installing the module is recommended because, among other things, it deployes drupal specific optimizations for the database.

UDPATING THE DRIVER

To update the driver you will need to manually copy the \drivers\lib\Drupal\Driver\Database\sqlsrv folder
and to manually update the PhpMssql library when updates are available.