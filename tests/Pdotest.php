<?php

  $prefix = 'test7472525';
  $prefixed_table = $prefix . 'path_alias';
  $create_sql = "CREATE TABLE $prefixed_table (id int NOT NULL PRIMARY KEY, langcode varchar(12), revision_id int, [path] nvarchar(255), [alias] nvarchar(255))";
  $dbh = new \PDO("sqlsrv:Server=localhost;Database=mydrupalsite", "sa", "Password12");
  $dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  $sql = "SELECT TOP 1 base_table.revision_id AS revision_id, base_table.id AS id FROM $prefixed_table base_table INNER JOIN $prefixed_table path_alias ON path_alias.id = base_table.id WHERE ( (path_alias.[alias] LIKE :db_condition_placeholder_0 ESCAPE '€') AND (path_alias.langcode = :db_condition_placeholder_1) AND (path_alias.[path] NOT LIKE :db_condition_placeholder_2 ESCAPE '€') )";
  $args = [
    ':db_condition_placeholder_0' => '/kaspuchujawuphabropestistutrudewruphogudraguphespofrethafrubrumelibrathocrunelistemehiswucepherabradup',
    ':db_condition_placeholder_1' => 'zxx',
    ':db_condition_placeholder_2' => '/<front>',
  ];
  $dbh->exec($create_sql);
  $sth = $dbh->prepare($sql);
  try {
    $sth->execute($args);
  }
  catch(\Exception $e) {
    fwrite(STDOUT, $sql);
    fwrite(STDOUT, print_r($args, TRUE));
    throw $e;
  }
