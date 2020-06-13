composer require --dev symfony/phpunit-bridge phpstan/extension-installer jangregor/phpstan-prophecy mglaman/phpstan-drupal
composer update phpunit/phpunit symfony/phpunit-bridge phpspec/prophecy symfony/yaml --with-dependencies
composer require --dev phpstan/phpstan-phpunit
# Core patches
# Logger backtrace incorrect. pushed to 9.x
wget https://www.drupal.org/files/issues/2020-05-27/2867788-92.patch
# core Condition not able to be overridden in views...needs work. 
wget https://www.drupal.org/files/issues/2020-05-04/3130655-10.patch
git apply 2867788-92.patch
git apply 3130655-10.patch
# Testing-only patches
# view sort order bug
wget https://www.drupal.org/files/issues/2020-06-05/3146016-3.patch
git apply 3146016-3.patch
# Fix format of deprecation notices for phpcs 
wget https://www.drupal.org/files/issues/2020-02-25/3108540-11.patch
# Add a sqlsrv-specific datatype to test
wget https://www.drupal.org/files/issues/2020-02-05/drupal-3111134-database_specific_types-3.patch
git apply drupal-3111134-database_specific_types-3.patch
git apply 3108540-11.patch
