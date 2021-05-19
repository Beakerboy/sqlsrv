rm ../drupal-project/scripts/run-tests.sh
rm ../drupal-project/includes/bootstrap.inc
cp -R $TRAVIS_BUILD_DIR ../drupal-project/sites/all/modules/
cp -R $TRAVIS_BUILD_DIR/sqlsrv ../drupal-project/includes/database/
cp $TRAVIS_BUILD_DIR/dev/travis/bootstrap.inc ../drupal-project/includes/
cp $TRAVIS_BUILD_DIR/dev/travis/run-tests.sh ../drupal-project/scripts/
cp $TRAVIS_BUILD_DIR/dev/settings.php ../drupal-project/sites/default/
