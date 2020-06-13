<?php

namespace Drupal\sqlsrv\Driver\Database\sqlsrv;

use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\Query\PlaceholderInterface;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Database\Query\Select as QuerySelect;
use Drupal\Core\Database\Query\Condition as DatabaseCondition;

/**
 * @addtogroup database
 * @{
 */
class Select extends QuerySelect {

  /**
   * The connection object on which to run this query.
   *
   * @var \Drupal\sqlsrv\Driver\Database\sqlsrv\Connection
   */
  protected $connection;

  /**
   * Is this statement in a subquery?
   *
   * @var bool
   */
  protected $inSubQuery = FALSE;

  /**
   * Adds an expression to the list of "fields" to be SELECTed.
   *
   * An expression can be any arbitrary string that is valid SQL. That includes
   * various functions, which may in some cases be database-dependent. This
   * method makes no effort to correct for database-specific functions.
   *
   * Overriden with an aditional exclude parameter that tells not to include
   * this expression (by default) in the select list.
   *
   * Drupal expects the AVG() function to return a decimal number. SQL Server
   * will return the FLOOR instead. We multiply the expression by 1.0 to force
   * a cast inside the AVG function. `AVG(m.id)` becomes `AVG(m.id * 1.0)`.
   *
   * @param string $expression
   *   The expression string. May contain placeholders.
   * @param string $alias
   *   The alias for this expression. If not specified, one will be generated
   *   automatically in the form "expression_#". The alias will be checked for
   *   uniqueness, so the requested alias may not be the alias that is assigned
   *   in all cases.
   * @param mixed $arguments
   *   Any placeholder arguments needed for this expression.
   * @param bool $exclude
   *   If set to TRUE, this expression will not be added to the select list.
   *   Useful when you want to reuse expressions in the WHERE part.
   * @param bool $expand
   *   If this expression will be expanded as a CROSS_JOIN so it can be consumed
   *   from other parts of the query. TRUE by default. It attempts to detect
   *   expressions that cannot be cross joined (aggregates).
   *
   * @return string
   *   The unique alias that was assigned for this expression.
   */
  public function addExpression($expression, $alias = NULL, $arguments = [], $exclude = FALSE, $expand = TRUE) {
    $sub_expression = $expression;
    $replacement_expression = '';
    while (strlen($sub_expression) > 5 && (($pos1 = stripos($sub_expression, 'AVG(')) !== FALSE)) {
      $pos2 = $this->findParenMatch($sub_expression, $pos1 + 3);
      $inner = substr($sub_expression, $pos1 + 4, $pos2 - 4 - $pos1);
      $replacement_expression .= substr($sub_expression, 0, $pos1 + 4) . '(' . $inner . ') * 1.0)';

      if (strlen($sub_expression) > $pos2 + 1) {
        $sub_expression = substr($sub_expression, $pos2 + 1);
      }
      else {
        $sub_expression = '';
      }
    }
    $replacement_expression .= $sub_expression;
    $alias = parent::addExpression($replacement_expression, $alias, $arguments);
    $this->expressions[$alias]['exclude'] = $exclude;
    $this->expressions[$alias]['expand'] = $expand;
    return $alias;
  }

  /**
   * Given a string find the matching parenthesis after the given point.
   *
   * @param string $string
   *   The input string.
   * @param int $start_paren
   *   The 0 indexed position of the open-paren, for which we would like
   *   to find the matching closing-paren.
   *
   * @return int|false
   *   The 0 indexed position of the close paren.
   */
  private function findParenMatch($string, $start_paren) {
    if ($string[$start_paren] !== '(') {
      return FALSE;
    }
    $str_array = str_split(substr($string, $start_paren + 1));
    $paren_num = 1;
    foreach ($str_array as $i => $char) {
      if ($char == '(') {
        $paren_num++;
      }
      elseif ($char == ')') {
        $paren_num--;
      }
      if ($paren_num == 0) {
        return $i + $start_paren + 1;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function preExecute(SelectInterface $query = NULL) {
    // If no query object is passed in, use $this.
    if (!isset($query)) {
      $query = $this;
    }

    // Only execute this once.
    if ($this->isPrepared()) {
      return TRUE;
    }

    // Execute standard pre-execution first.
    parent::preExecute($query);

    if ($this->distinct || $this->group) {
      // When the query is DISTINCT or contains GROUP BY fields, all the fields
      // in the GROUP BY and ORDER BY clauses must appear in the returned
      // columns.
      $columns = $this->order + array_flip($this->group);
      $counter = 0;
      foreach ($columns as $field => $dummy) {
        $found = FALSE;
        foreach ($this->fields as $f) {
          if (!isset($f['table']) || !isset($f['field'])) {
            continue;
          }
          $alias = "{$f['table']}.{$f['field']}";
          if ($alias == $field) {
            $found = TRUE;
            break;
          }
        }
        if (!isset($this->fields[$field]) && !isset($this->expressions[$field]) && !$found) {
          $alias = '_field_' . ($counter++);
          $this->addExpression($field, $alias, [], FALSE, FALSE);
          $this->queryOptions['sqlsrv_drop_columns'][] = $alias;
        }
      }

      // The other way round is also true, if using aggregates, all the fields
      // in the SELECT must be present in the GROUP BY.
      if (!empty($this->group)) {
        foreach ($this->fields as $field) {
          $spec = $field['table'] . '.' . $field['field'];
          $alias = $field['alias'];
          if (!isset($this->group[$spec]) && !isset($this->group[$alias])) {
            $this->group[$spec] = $spec;
          }
        }
      }

      // More over, GROUP BY columns cannot use aliases, so expand them to
      // their full expressions.
      foreach ($this->group as $key => &$group_field) {
        // Expand an alias on a field.
        if (isset($this->fields[$group_field])) {
          $field = $this->fields[$group_field];
          $group_field = (isset($field['table']) ? $this->connection->escapeTable($field['table']) . '.' : '') . $this->connection->escapeField($field['field']);
        }
        // Expand an alias on an expression.
        elseif (isset($this->expressions[$group_field])) {
          $expression = $this->expressions[$group_field];
          $group_field = $expression['expression'];
        }
      }
    }
    $this->queryOptions['emulate_prepares'] = TRUE;
    return $this->prepared;
  }

  /**
   * {@inheritdoc}
   *
   * Why this is needed?
   */
  public function compile(DatabaseConnection $connection, PlaceholderInterface $queryPlaceholder) {
    $this->inSubQuery = $queryPlaceholder != $this;
    return parent::compile($connection, $queryPlaceholder);
  }

  /**
   * {@inheritdoc}
   *
   * Overridden to support SQL Server Range Query syntax and CROSS APPLY.
   */
  public function __toString() {
    // For convenience, we compile the query ourselves if the caller forgot
    // to do it. This allows constructs like "(string) $query" to work. When
    // the query will be executed, it will be recompiled using the proper
    // placeholder generator anyway.
    if (!$this->compiled()) {
      $this->compile($this->connection, $this);
    }

    // Create a sanitized comment string to prepend to the query.
    $comments = $this->connection->makeComment($this->comments);

    // SELECT.
    $query = $comments . 'SELECT ';
    if ($this->distinct) {
      $query .= 'DISTINCT ';
    }
    $used_range = FALSE;
    if (!empty($this->range) && $this->range['start'] == 0 && !$this->union && isset($this->range['length'])) {
      $query .= 'TOP (' . $this->range['length'] . ') ';
      $used_range = TRUE;
    }

    // FIELDS and EXPRESSIONS.
    $fields = [];
    foreach ($this->tables as $alias => $table) {
      if (!empty($table['all_fields'])) {
        $fields[] = $this->connection->escapeTable($alias) . '.*';
      }
    }
    foreach ($this->fields as $field) {
      // Always use the AS keyword for field aliases, as some
      // databases require it (e.g., PostgreSQL).
      $fields[] = (isset($field['table']) ? $this->connection->escapeTable($field['table']) . '.' : '') . $this->connection->escapeField($field['field']) . ' AS ' . $this->connection->escapeAlias($field['alias']);
    }
    foreach ($this->expressions as $expression) {
      $fields[] = $expression['expression'] . ' AS ' . $this->connection->escapeAlias($expression['alias']);
    }
    $query .= implode(', ', $fields);

    // FROM - We presume all queries have a FROM, as any query that doesn't
    // won't need the query builder anyway.
    $query .= "\nFROM";
    foreach ($this->tables as $alias => $table) {
      $query .= "\n";
      if (isset($table['join type'])) {
        $query .= $table['join type'] . ' JOIN ';
      }

      // If the table is a subquery, compile it and integrate it into this
      // query.
      if ($table['table'] instanceof SelectInterface) {
        // Run preparation steps on this sub-query before converting to string.
        $subquery = $table['table'];
        $subquery->preExecute();
        $table_string = '(' . (string) $subquery . ')';
      }
      else {
        $table_string = $this->connection->escapeTable($table['table']);
        // Do not attempt prefixing cross database / schema queries.
        if (strpos($table_string, '.') === FALSE) {
          $table_string = '{' . $table_string . '}';
        }
      }

      // Don't use the AS keyword for table aliases, as some
      // databases don't support it (e.g., Oracle).
      $query .= $table_string . ' ' . $this->connection->escapeTable($table['alias']);

      if (!empty($table['condition'])) {
        $query .= ' ON ' . $table['condition'];
      }
    }

    // WHERE.
    if (count($this->condition)) {
      // There is an implicit string cast on $this->condition.
      $query .= "\nWHERE " . $this->condition;
    }

    // GROUP BY.
    if ($this->group) {
      $query .= "\nGROUP BY " . implode(', ', $this->group);
    }

    // HAVING.
    if (count($this->having)) {
      // There is an implicit string cast on $this->having.
      $query .= "\nHAVING " . $this->having;
    }

    // UNION is a little odd, as the select queries to combine are passed into
    // this query, but syntactically they all end up on the same level.
    if ($this->union) {
      foreach ($this->union as $union) {
        $query .= ' ' . $union['type'] . ' ' . (string) $union['query'];
      }
    }

    // ORDER BY.
    // The ORDER BY clause is invalid in views, inline functions, derived
    // tables, subqueries, and common table expressions, unless TOP or FOR XML
    // is also specified.
    $add_order_by = $this->order && (empty($this->inSubQuery) || !empty($this->range));
    if ($add_order_by) {
      $query .= "\nORDER BY ";
      $fields = [];
      foreach ($this->order as $field => $direction) {
        $fields[] = $this->connection->escapeField($field) . ' ' . $direction;
      }
      $query .= implode(', ', $fields);
    }

    // RANGE.
    if (!empty($this->range) && !$used_range) {
      if (!$add_order_by) {
        $query .= " ORDER BY (SELECT NULL)";
      }
      $query .= " OFFSET {$this->range['start']} ROWS FETCH NEXT {$this->range['length']} ROWS ONLY";
    }

    return $query;
  }

  /**
   * Override of SelectQuery::orderRandom() for SQL Server.
   *
   * It seems that sorting by RAND() doesn't actually work, this is a less then
   * elegant workaround.
   *
   * @status tested
   */
  public function orderRandom() {
    $alias = $this->addExpression('NEWID()', 'random_field');
    $this->orderBy($alias);
    return $this;
  }

  /**
   * Mark Alises.
   *
   * Does not return anything, so should not be called 'getUsedAliases'
   */
  private function getUsedAliases(DatabaseCondition $condition, array &$aliases = []) {
    foreach ($condition->conditions() as $key => $c) {
      if (is_string($key) && substr($key, 0, 1) == '#') {
        continue;
      }
      if (is_a($c['field'], DatabaseCondition::class)) {
        $this->GetUsedAliases($c['field'], $aliases);
      }
      else {
        $aliases[$c['field']] = TRUE;
      }
    }
  }

  /**
   * Prepare a count query.
   *
   * This is like the default prepareCountQuery, but does not optimize field (or
   * expressions) that are being used in conditions. (Why not?)
   *
   * @return mixed
   *   A Select object.
   */
  protected function prepareCountQuery() {
    // Create our new query object that we will mutate into a count query.
    $count = clone($this);

    $group_by = $count->getGroupBy();
    $having = $count->havingConditions();

    if (!$count->distinct && !isset($having[0])) {

      $used_aliases = [];
      $this->getUsedAliases($count->condition, $used_aliases);

      // When not executing a distinct query, we can zero-out existing fields
      // and expressions that are not used by a GROUP BY or HAVING. Fields
      // listed in a GROUP BY or HAVING clause need to be present in the
      // query.
      $fields =& $count->getFields();
      foreach ($fields as $field => $value) {
        if (empty($group_by[$field]) && !isset($used_aliases[$value['alias']])) {
          unset($fields[$field]);
        }
      }

      $expressions =& $count->getExpressions();
      foreach ($expressions as $field => $value) {
        if (empty($group_by[$field]) && !isset($used_aliases[$value['alias']])) {
          unset($expressions[$field]);
        }
      }

      // Also remove 'all_fields' statements, which are expanded into
      // tablename.* when the query is executed.
      foreach ($count->tables as $alias => &$table) {
        unset($table['all_fields']);
      }
    }

    // If we've just removed all fields from the query, make sure there is at
    // least one so that the query still runs.
    $count->addExpression('1');

    // Ordering a count query is a waste of cycles, and breaks on some
    // databases anyway.
    $orders = &$count->getOrderBy();
    $orders = [];

    if ($count->distinct && !empty($group_by)) {
      // If the query is distinct and contains a GROUP BY, we need to remove the
      // distinct because SQL99 does not support counting on distinct multiple
      // fields.
      $count->distinct = FALSE;
    }

    return $count;
  }

}

/**
 * @} End of "addtogroup database".
 */
