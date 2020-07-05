#Core Patches
# Core Condition not able to be overridden in views.
wget https://www.drupal.org/files/issues/2020-05-31/3130655-27.patch
git apply 3130655-27.patch

bash ../sqlsrv/dev/travis/drupal9.x.sh
