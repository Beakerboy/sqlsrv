# Ensure mysql is in the services portion of .travis.yml
mysql -e "CREATE DATABASE mydrupalsite"
export DBURL="mysql://travis@localhost/mydrupalsite"
