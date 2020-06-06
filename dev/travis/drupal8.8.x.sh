bash ../sqlsrv/dev/travis/drupal8.x.sh
# Override core Condition...pushed to 8.9.x
wget https://www.drupal.org/files/issues/2020-03-10/3113403-33.patch
git apply 3113403-33.patch
# docbloc return type not correct...for phpstan. Pushed to 8.9.x
wget https://www.drupal.org/files/issues/2020-04-07/3125391-9_0.patch
git apply 3125391-9_0.patch
# CONCAT_WS requires at least three arguments...needs work
wget https://www.drupal.org/files/issues/2020-04-27/3131379-2.patch
git apply 3131379-2.patch
# fix hard-codes LIMIT in test.
wget https://www.drupal.org/files/issues/2020-05-22/3139132-2.patch
git apply 3139132-2.patch
