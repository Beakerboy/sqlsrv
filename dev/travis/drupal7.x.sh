cp -R $TRAVIS_BUILD_DIR $TRAVIS_BUILD_DIR/../drupal-project/sites/all/modules/
cp -R $TRAVIS_BUILD_DIR/sqlsrv $TRAVIS_BUILD_DIR/../drupal-project/includes/database/
cp $TRAVIS_BUILD_DIR/dev/settings.php $TRAVIS_BUILD_DIR/../drupal-project/sites/default/
