[![Build Status](https://travis-ci.org/Beakerboy/sqlsrv.svg?branch=8.x-1.x)](https://travis-ci.org/Beakerboy/sqlsrv)
[![Build status](https://ci.appveyor.com/api/projects/status/xk6gh0rtta8d24hg/branch/8.x-1.x?svg=true)](https://ci.appveyor.com/project/Beakerboy/sqlsrv/branch/8.x-1.x)
[![Coverage Status](https://coveralls.io/repos/github/Beakerboy/sqlsrv/badge.svg?branch=8.x-1.x)](https://coveralls.io/github/Beakerboy/sqlsrv?branch=8.x-1.x)

SQL Server Driver for Drupal
=====================

### For Windows or Linux

This contrib module allows the Drupal CMS to connect to Microsoft SQL Server
databases.

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
 * Drupal 9.1.0
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

### Binary Large Objects

Varbinary data in SQL Server presents two issues. SQL Server is unable to
directly compare a string to a blob without casting the string to varbinary.
This means a query cannot add a ->condition('blob_field', $string) to any
Select query. Thankfully, this is not done in core code, but there is nothing
to stop core from doing so in the future. Contrib modules may currently use
this pattern.

### Non-ASCII strings

Most varchar data is actually stored as nvarchar, because varchar is ascii-only.
Drupal uses UTF-8 while nvarchar encodes data as UCS-2. There are some character
encoding issues that can arise in strange edge cases. Data is typically saved to
varbinary as a stream of UTF-8 characters. If, instead, an nvarchar is converted
into a varbinary, and the binary data extracted into Drupal, it will not be the
same as when it started.

### Collation

The 1.x branch creates the database with a case-insensitive collation for text
fields. However, all non-MySQL databases use case-sensitive default.

Outstanding Issues
-----
The issues mentioned above means that the sqlsrv driver does not pass every core
test. The project issues queue lists the failing core tests, and the progress in
remedying them.

The following are outstanding core issues that affect the sqlsrv driver.

All Versions (needs work, patch review or awaiting merge):
* [Override Condition in views](https://www.drupal.org/node/3130655)-[[patch](https://www.drupal.org/files/issues/2020-04-24/3130655-3.patch)]

Drupal 8.x (Already merged into Drupal Core 9+):
* [Logger backtrace incorrect](https://www.drupal.org/node/2867788)-[[patch](https://www.drupal.org/files/issues/2020-02-22/2867788-79.patch)]
