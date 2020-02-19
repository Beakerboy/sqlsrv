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
