#Core Patches
# Encapsulate fields.
wget http://beakerboy.com/~kevin/3136974-4.patch
git apply 3136974-4.patch
# Core Condition not able to be overridden in views.
wget https://www.drupal.org/files/issues/2020-05-31/3130655-27.patch
git apply 3130655-27.patch

# Testing Patches
# ConnectionUnitTest defaults to MySQL syntax
wget http://beakerboy.com/~kevin/connectionUnit.patch
git apply connectionUnit.patch
# Enable sqlsrv module in specific kernel tests
wget https://www.drupal.org/files/issues/2020-05-02/2966272-16.patch
# Enable sqlsrv in specific Functional Tests splice16
wget http://beakerboy.com/~kevin/Function-timestamp.patch
git apply Function-timestamp.patch
git apply 2966272-16.patch
# Sort order must be specified
wget https://www.drupal.org/files/issues/2020-06-12/3146016-5.patch
git apply 3146016-5.patch
# Add a sqlsrv-specific datatype to test
wget https://www.drupal.org/files/issues/2020-02-05/drupal-3111134-database_specific_types-3.patch
git apply drupal-3111134-database_specific_types-3.patch
