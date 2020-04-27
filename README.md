[![Build Status](https://travis-ci.org/Beakerboy/sqlsrv.svg?branch=8.x-1.x)](https://travis-ci.org/Beakerboy/sqlsrv)
[![Build status](https://ci.appveyor.com/api/projects/status/xk6gh0rtta8d24hg/branch/8.x-1.x?svg=true)](https://ci.appveyor.com/project/Beakerboy/sqlsrv/branch/8.x-1.x)
[![Coverage Status](https://coveralls.io/repos/github/Beakerboy/sqlsrv/badge.svg?branch=8.x-1.x)](https://coveralls.io/github/Beakerboy/sqlsrv?branch=8.x-1.x)

SQL Server Driver for Drupal
=====================

### For Windows or Linux

This contrib module allows the Drupal CMS to connect to Microsoft SQL Server
databases.

The 8.x-1.x branch will continue to fulfill the needs of site operators who
are currently using this branch and will not upgrade to Drupal 9.

Setup
-----

Use [composer](http://getcomposer.org) to install the module:

```bash
$ php composer require drupal/sqlsrv
```

The `drivers/` directory needs to be copied to webroot of your drupal
installation.

Drupal core allows module developers to use regular expressions within SQL
statements. The base installation does not use this feature, so it is not
required for Drupal to install. However, if any contrib modules use regular
expressions, a CLR will need to be installed that is equivalent to 
`CREATE
   FUNCTION {schema}.REGEXP(@pattern NVARCHAR(100), @matchString NVARCHAR(100))
   RETURNS bit EXTERNAL NAME {name_of_function}`

### Minimum Requirements
 * Drupal 8.8.0
 * SQL Server 2016
 * pdo_sqlsrv 5.8.0

Usage
-----

This driver has a couple peculiarities worth knowing about.

### LIKE expressions

Drupal and the core databases use only two wildcards, `%` and `_`, both of which
are escaped by backslashes. This driver currently uses the default SQL Server
behavior behind-the-scenes, that of escaping the wildcard characters by
enclosing them in brackets `[%]` and `[_]`. When using the `Select::condition()`
function with a LIKE operator, you must use standard Drupal format with
backslash escapes. If you need sqlsrv-specific behavior, you can use
`Select::where()`.
```php
// These two statements are equivalent
$connection->select('test', 't')
  ->condition('t.route', '%[route]%', 'LIKE');
$connection->select('test', 't')
  ->where('t.route LIKE :pattern', [':pattern' => '%[[]route]%']);
```
Note that there is a PDO bug that prevents multiple
`field LIKE :placeholder_x ESCAPE '\'` expressions from appearing in one SQL
statement. A different escape character can be chosen if you need a custom
escape character multiple times. This bug just affects the backslash.

Outstanding Issues
-----
The 1.x branch is not able to pass all Drupal tests due to limitations in
SQL server before SQL Server 2019. These earlier vesions do not natively
support UTF8 character encoding. This means most string data is stored in the
database as an `nvarchar`. Converting nvarchar to varbinary and back leads to
data corruption.

The 1.x branch creates the database with a case-insensitive collation for text
fields. However, all non-MySQL databases use case-sensitive default. One
Kernel Test fails due to this default in this driver.

The following are outstanding core issues that affect the sqlsrv driver.

All Versions:
* https://www.drupal.org/files/issues/2020-04-18/3128761-2.patch
* https://www.drupal.org/files/issues/2020-04-24/3130655-3.patch
* https://www.drupal.org/files/issues/2020-04-27/3131379-2.patch

Drupal 8.x:
* https://www.drupal.org/files/issues/2020-02-22/2867788-79.patch

Drupal 8.8.x:
* https://www.drupal.org/files/issues/2020-03-10/3113403-33.patch
