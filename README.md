SQL Server Driver for Drupal
=====================

### For Windows or Linux

This contrib module allows the Drupal CMS to connect to Microsoft SQL Server databases.

It is actively under development.

Setup
-----

Use [composer](http://getcomposer.org) to install the module:

```bash
$ php composer require drupal/sqlsrv
```

The `drivers/` directory needs to be copied to webroot of your drupal installation.

Drupal core allows module developers to use regular expressions within SQL statements. The base installation does not use this feature, so it is not required for Drupal to install. However, if any contrib modules use regular expressions, a CLR will need to be installed that is equivalent to  `CREATE FUNCTION {schema}.REGEXP(@pattern NVARCHAR(100), @matchString NVARCHAR(100)) RETURNS bit EXTERNAL NAME {name_of_function}`

### Minimum Requirements
 * Drupal 8.8.0
 * SQL Server 2012
 * pdo_sqlsrv 5.8.0

Usage
-----

This driver has a couple peculiarities worth knowing about.

### LIKE expressions

Drupal and the core databases use only two wildcards, `%` and `_`, both of which are escaped by backslashes. This driver currently uses the default SQL Server behavior behind-the-scenes, that of escaping the wildcard characters by enclosing them in brackets `[%]` and `[_]`. When using the `Select::condition()` function with a LIKE operator, you must use standard Drupal format with backslash escapes. If you need sqlsrv-specific behavior, you can use `Select::where()`. Note that there is a PDO bug that prevents multiple `field LIKE :placeholder_x ESCAPE '\'` expressions from appearing in one SQL statement. A different escape character can be chosen if you need a custom escape character multiple times. This bug just affects the backslash.
