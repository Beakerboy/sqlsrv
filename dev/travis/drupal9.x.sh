#Core Patches

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
# Add a sqlsrv-specific datatype to test
wget https://www.drupal.org/files/issues/2020-02-05/drupal-3111134-database_specific_types-3.patch
git apply drupal-3111134-database_specific_types-3.patch

# Reorganize files and directories.
mv $TRAVIS_BUILD_DIR/dev/TestSuites $TRAVIS_BUILD_DIR/tests/src/
mv $TRAVIS_BUILD_DIR/dev/travis/CITestSuiteBase.php $TRAVIS_BUILD_DIR/tests/src/TestSuites
rm -rf $TRAVIS_BUILD_DIR/dev/appveyor
mv $TRAVIS_BUILD_DIR/tests/database_statement_monitoring_test ./core/modules/system/tests/modules/database_statement_monitoring_test/src/sqlsrv
cp -rf $TRAVIS_BUILD_DIR ./modules
PATH=$PATH:$TRAVIS_BUILD_DIR/../drupal-project/vendor/bin
mkdir $TRAVIS_BUILD_DIR/../drupal-project/sites/simpletest
mkdir $TRAVIS_BUILD_DIR/../drupal-project/sites/simpletest/browser_output
sed -e "s?WEB_DIR?$(pwd)?g" --in-place $TRAVIS_BUILD_DIR/dev/phpunit.xml.dist
