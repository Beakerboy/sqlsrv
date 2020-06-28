#Core Patches
# Encapsulate fields.
wget http://beakerboy.com/~kevin/3136974-4.patch
git apply 3136974-4.patch
# Core Condition not able to be overridden in views...needs work.
wget https://www.drupal.org/files/issues/2020-05-04/3130655-13.patch
git apply 3130655-27.patch

# Testing Patches
# ConnectionUnitTest defaults to MySQL syntax
wget http://beakerboy.com/~kevin/connectionUnit.patch
git apply connectionUnit.patch
# Enable sqlsrv module in specific kernel tests
wget https://www.drupal.org/files/issues/2020-05-02/2966272-16.patch
git apply 2966272-16.patch
