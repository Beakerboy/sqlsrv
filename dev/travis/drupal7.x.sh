cp -R $TRAVIS_BUILD_DIR ../drupal-project/sites/all/modules/
cp -R $TRAVIS_BUILD_DIR/sqlsrv ../drupal-project/includes/database/
cp $TRAVIS_BUILD_DIR/dev/settings.php ../drupal-project/sites/default/
