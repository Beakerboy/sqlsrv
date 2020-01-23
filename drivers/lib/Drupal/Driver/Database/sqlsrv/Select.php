<?php

namespace Drupal\Driver\Database\sqlsrv;

use Drupal\Core\Database\Connection as DatabaseConnection;
use Drupal\Core\Database\Query\PlaceholderInterface as DatabasePlaceholderInterface;
use Drupal\Core\Database\Query\SelectInterface as DatabaseSelectInterface;
use Drupal\Core\Database\Query\Select as QuerySelect;
use Drupal\Core\Database\Query\Condition as DatabaseCondition;

/**
 * @addtogroup database
 * @{
 */
class Select extends QuerySelect {

  /**
   * {@inheritdoc}
   */
  public function __construct($table, $alias, Connection $connection, $options = []) {
    parent::__construct($table, $alias, $connection, $options);
    $conjunction = isset($options['conjunction']) ? $options['conjunction'] : 'AND';
    $this->condition = new Condition($conjunction);
    $this->having = new Condition($conjunction);
  }

  /**
   * {@inheritdoc}
   *
   * Overriden with an aditional exclude parameter that tells not to include
   * this expression (by default) in the select list.
   *
   * @param string $exclude
   *   If set to TRUE, this expression will not be added to the select list.
   *   Useful when you want to reuse expressions in the WHERE part.
   * @param string $expand
   *   If this expression will be expanded as a CROSS_JOIN so it can be consumed
   *   from other parts of the query. TRUE by default. It attempts to detect
   *   expressions that cannot be cross joined (aggregates).
   */
  public function addExpression($expression, $alias = NULL, $arguments = [], $exclude = FALSE, $expand = TRUE) {
    $alias = parent::addExpression($expression, $alias, $arguments);
    $this->expressions[$alias]['exclude'] = $exclude;
    $this->expressions[$alias]['expand'] = $expand;
    return $alias;
  }

  /**
   * Override for SelectQuery::preExecute().
   *
   * Ensure that all the fields in ORDER BY and GROUP BY are part of the
   * main query.
   */
  public function preExecute(DatabaseSelectInterface $query = NULL) {
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
          // If the expression has arguments, we now
          // have duplicate placeholders. Run as insecure.
          if (is_array($expression['arguments'])) {
            $this->queryOptions['insecure'] = TRUE;
          }
        }
      }
    }

    return $this->prepared;
  }

  /**
   * Override for SelectQuery::compile().
   *
   * Detect when this query is prepared for use in a sub-query.
   */
  public function compile(DatabaseConnection $connection, DatabasePlaceholderInterface $queryPlaceholder) {
    $this->inSubQuery = $queryPlaceholder != $this;
    return parent::compile($connection, $queryPlaceholder);
  }

  /**
   * Strpos that takes an array of values to match against a string.
   *
   * Note the stupid argument order (to match strpos).
   *
   * @param mixed $haystack
   *   The value to search within.
   * @param mixed $needle
   *   value(s) to look for.
   *
   * @return int
   *   The position of the first $needle[] in the $haystack.
   */
  private function striposArr($haystack, $needle) {
    if (!is_array($needle)) {
      $needle = [$needle];
    }
    foreach ($needle as $what) {
      if (($pos = stripos($haystack, $what)) !== FALSE) {
        return $pos;
      }
    }
    return FALSE;
  }

  const RESERVED_REGEXP_BASE = '/\G
    # Everything that follows a boundary that is not ":" or "_" or ".".
    \b(?<![:\[_\[.])(?:
      # Any reserved words, followed by a boundary that is not an opening parenthesis.
      ({0})
      (?!\()
      |
      # Or a normal word.
      ([a-z]+)
    )\b
    |
    \b(
      [^a-z\'"\\\\]+
    )\b
    |
    (?=[\'"])
    (
      "  [^\\\\"] * (?: \\\\. [^\\\\"] *) * "
      |
      \' [^\\\\\']* (?: \\\\. [^\\\\\']*) * \'
    )
  /Six';

  /**
   * Aliases for cross apply.
   *
   * Is it an array, string, or something that implements ArrayAccess
   *
   * @var mixed
   */
  private $crossApplyAliases;

  /**
   * Replace Reserve Alises.
   *
   * @param mixed $matches
   *   The matches. Is this an array or string?
   *
   * @return mixed
   *   What does it return?
   */
  protected function replaceReservedAliases($matches) {
    if ($matches[1] !== '') {
      // Replace reserved words.
      return $this->crossApplyAliases[$matches[1]];
    }
    // Let other value passthru.
    // by the logic of the regex above, this will always be the last match.
    return end($matches);
  }

  /**
   * {@inheritdoc}
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

    // FIELDS and EXPRESSIONS.
    $fields = [];
    foreach ($this->tables as $alias => $table) {
      // Table might be a subquery, so nothing to do really.
      if (is_string($table['table']) && !empty($table['all_fields'])) {
        // Temporary tables are not supported here.
        if ($table['table'][0] == '#') {
          $fields[] = $this->connection->escapeTable($alias) . '.*';
        }
        else {
          $info = $this->connection->schema()->queryColumnInformation($table['table']);
          // Some fields need to be "transparent" to Drupal, including technical
          // primary keys or custom computed columns.
          foreach ($info['columns_clean'] as $column) {
            $fields[] = $this->connection->escapeTable($alias) . '.' . $column['name'];
          }
        }
      }
    }
    foreach ($this->fields as $alias => $field) {
      // Always use the AS keyword for field aliases, as some
      // databases require it (e.g., PostgreSQL).
      $fields[] = (isset($field['table']) ? $this->connection->escapeTable($field['table']) . '.' : '') . $this->connection->escapeField($field['field']) . ' AS ' . $this->connection->escapeField($field['alias']);
    }
    // In MySQL you can reuse expressions present in SELECT
    // from WHERE.
    // The way to emulate that behaviour in SQL Server is to
    // fit all that in a CROSS_APPLY with an alias and then consume
    // it from WHERE or AGGREGATE.
    $cross_apply = [];
    $this->crossApplyAliases = [];
    foreach ($this->expressions as $alias => $expression) {
      // Only use CROSS_APPLY for non-aggregate expresions. This trick will not
      // work, and does not make sense, for aggregates. If the alias is
      // 'expression' this is Drupal's default meaning that more than probably
      // this expression is never reused in a WHERE.
      $function_list = [
        'AVG(',
        'GROUP_CONCAT(',
        'COUNT(',
        'MAX(',
        'GROUPING(',
        'GROUPING_ID(',
        'COUNT_BIG(',
        'CHECKSUM_AGG(',
        'MIN(',
        'SUM(',
        'VAR(',
        'VARP(',
        'STDEV(',
        'STDEVP(',
      ];
      if ($expression['expand'] !== FALSE && $expression['alias'] != 'expression' && $this->striposArr($expression['expression'], $function_list) === FALSE) {
        // What we are doing here is using a CROSS APPLY to
        // generate an expression that can be used in the select and where
        // but we need to give this expression a new name.
        $cross_apply[] = "\nCROSS APPLY (SELECT " . $expression['expression'] . ' cross_sqlsrv) cross_' . $expression['alias'];
        $new_alias = 'cross_' . $expression['alias'] . '.cross_sqlsrv';
        // We might not want an expression to appear in the select list.
        if ($expression['exclude'] !== TRUE) {
          $fields[] = $new_alias . ' AS ' . $expression['alias'];
        }
        // Store old expression and new representation.
        $this->crossApplyAliases[$expression['alias']] = 'cross_' . $expression['alias'] . '.cross_sqlsrv';
      }
      else {
        // We might not want an expression to appear in the select list.
        if ($expression['exclude'] !== TRUE) {
          $fields[] = $expression['expression'] . ' AS [' . $expression['alias'] . ']';
        }
      }
    }
    $query .= implode(', ', $fields);

    // FROM - We presume all queries have a FROM, as any query that doesn't
    // won't need the query builder anyway.
    $query .= "\nFROM ";
    foreach ($this->tables as $alias => $table) {
      $query .= "\n";
      if (isset($table['join type'])) {
        $query .= $table['join type'] . ' JOIN ';
      }

      // If the table is a subquery, compile it and integrate it into this
      // query.
      if ($table['table'] instanceof DatabaseSelectInterface) {
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

    // CROSS APPLY.
    $query .= implode($cross_apply);

    // WHERE.
    if (count($this->condition)) {
      // There is an implicit string cast on $this->condition.
      $where = (string) $this->condition;
      // References to expressions in cross-apply need to be updated.
      // Now we need to update all references to the expression aliases
      // and point them to the CROSS APPLY alias.
      if (!empty($this->crossApplyAliases)) {
        $regex = str_replace('{0}', implode('|', array_keys($this->crossApplyAliases)), self::RESERVED_REGEXP_BASE);
        // Add and then remove the SELECT
        // keyword. Do this to use the exact same
        // regex that we have in DatabaseConnection_sqlrv.
        $where = 'SELECT ' . $where;
        $where = preg_replace_callback($regex, [$this, 'replaceReservedAliases'], $where);
        $where = substr($where, 7, strlen($where) - 7);
      }
      $query .= "\nWHERE ( " . $where . " )";
    }

    // GROUP BY.
    if ($this->group) {
      $group = $this->group;
      // You named it, if the newly expanded expression
      // is added to the select list, then it must
      // also be present in the aggregate expression.
      $group = array_merge($group, $this->crossApplyAliases);
      $query .= "\nGROUP BY " . implode(', ', $group);
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

    // ORDER BY
    // The ORDER BY clause is invalid in views, inline functions, derived
    // tables, subqueries, and common table expressions, unless TOP or FOR XML
    // is also specified.
    if ($this->order && (empty($this->inSubQuery) || !empty($this->range))) {
      $query .= "\nORDER BY ";
      $fields = [];
      foreach ($this->order as $field => $direction) {
        $fields[] = $field . ' ' . $direction;
      }
      $query .= implode(', ', $fields);
    }

    // RANGE.
    if (!empty($this->range)) {
      $query = $this->connection->addRangeToQuery($query, $this->range['start'], $this->range['length']);
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
   * Create a count query.
   *
   * This is like the default countQuery, but does not optimize field (or
   * expressions) that are being used in conditions. (Why not?)
   *
   * @return mixed
   *   A query.
   */
  public function countQuery() {
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

    $query = $this->connection->select($count);
    $query->addExpression('COUNT(*)');

    return $query;
  }

}

/**
 * @} End of "addtogroup database".
 */
