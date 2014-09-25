<?php

namespace Mismatch\DB;

use Mismatch\DB\Expression as Expr;
use Mismatch\DB\Collection;
use IteratorAggregate;
use Countable;
use Closure;
use DomainException;

/**
 * Handles building an executing SQL queries.
 *
 * In conjunction with the factories provided by Mismatch\DB\Expression,
 * this becomes a powerful tool for building the queries common to CRUD
 * operations in web applications.
 *
 * As a quick example, let's find ten active authors who recently signed
 * up for our service, and let's order them by name.
 *
 * <code>
 * use Mismatch\DB\Query;
 * use Mismatch\DB\Expression as e;

 * $authors = (new Query($conn, 'authors'))
 *   ->order('name', 'asc')
 *   ->where([
 *     'signup' => e\after('2014-04-01')
 *     'active' => true,
 *   ]);

 * foreach ($authors as $author) {
 *   // It's pretty easy
 * }
 * </code>
 *
 *
 * ### Building queries
 *
 * After creating a new instance of your query class, you can start
 * chaining methods on to it to refine and filter results before they're
 * returned.
 *
 * <code>
 * // Both where and whereAny support complex expressions (outlined in
 * // the section below. Also, where is AND while whereAny is OR.
 * $query->where(['email' => 'rl.stine@example.com']);
 * $query->whereAny(['email' => 'ka.applegate@example.com']);
 *
 * // Both having and havingAny work exactly as where and whereAny.
 * $query->having(['sum' => e\gt(5)]);
 * $query->havingAny(['max' => e\lt(10)]);
 *
 * // Select specific columns. Aliases are supported as array keys
 * $query->select(['column', 'column' => 'alias']);
 *
 * // INNER JOIN is added by default.
 * $query->join('authors a', ['a.id' => 'book.author_id']);
 *
 * // Although different types of joins can be specified.
 * $query->join('LEFT OUTER JOIN authors a', ['a.id' => 'book.author_id']);
 *
 * // The following work exactly as you'd expect.
 * $query->offset(10);
 * $query->limit(10);
 * $query->order(['name' => 'asc']);
 * $query->group(['max']);
 *
 * ### Executing queries
 *
 * Once you've built up your query, you can execute it. There are all kinds
 * of methods available for SELECT-based queries.
 *
 * <code>
 * // Return a single author, or throw an exception.
 * $query->find();
 *
 * // Return a single author without throwing an exception.
 * $query->first();
 *
 * // Return all records, without any sort of limit.
 * $query->all();
 *
 * // Return the number of records that would be returned by all().
 * $query->count();
 *
 * // Return a number of authors, not exceeding the passed limit of 10.
 * $query->take(10);
 * </code>
 *
 * There are also methods for modifying records.
 *
 * <code>
 * // Insert a new record, returning the number of affected rows.
 * $query->insert(['name' => 'H. Preen']);
 *
 * // Update a records, returning the number of affected rows.
 * $query->update(['name' => 'H. Preen']);
 *
 * // Delete records, returning number of affected rows.
 * $query->delete();
 * </code>
 *
 * As well as facilities for executing raw SQL.
 *
 * <code>
 * $query->raw('SELECT * FROM books WHERE email = ?', ['foo@example.com']);
 * </code>
 *
 *
 * ### Complex expressions
 *
 * Each of `find`, `first`, `all`, `delete`, `where`, `whereAny`,
 * `having`, and `havingAny` take a few different types of expressions.
 * The types of expressions they take can range from very terse (such as
 * when selecting by primary key) to full-blown SQL.
 *
 * Take a look.
 *
 * <code>
 *   // You can filter by ID, which is useful for find, first, and delete
 *   $query->find(1);
 *
 *   // You can filter by equality, which is useful for almost all methods
 *   $query->all(['active' => true]);
 *
 *   // It'll even figure out IN statements for ya
 *   $query->all(['email' => [
 *     'rl.stine@example.com',
 *     'ka.applegate@example.com',
 *     'jk.rowling@example.com',
 *   ]]);
 *
 *   // You can filter using raw SQL, which is often easiest
 *   // Hint: the second argument is used for escaped conditions
 *   $query->first('email = ? and active',
 *     ['richie.brautwurst@example.com']);
 *
 *   // You can even filter by more complex expressions...
 *   use Mismatch\DB\Expression as e;
 *
 *   // Like the every-useful LIKE filter
 *   $query->where(['email' => e\like('%@example.com');
 *
 *   // Or NOT, which comes up often
 *   $query->whereAny(['email' => e\not('el.james@example.com')]);
 *
 *   // Even IS NULL
 *   $query->delete(['email' => e\null()]);
 *
 */
class Query implements IteratorAggregate, Countable
{
    /**
     * @var  Connection  The connection to make requests against.
     */
    private $conn;

    /**
     * @var  string  The alias to use for unadorned columns
     */
    private $alias;

    /**
     * @var  string  The primary key to use for id shortcuts.
     */
    private $pk;

    /**
     * @var  array  Private parts, heh.
     */
    private $parts = [];

    /**
     * Constructor.
     *
     * @param   Connection    $conn
     * @param   string|array  $table
     * @param   string        $pk
     */
    public function __construct($conn, $table, $pk = 'id')
    {
        $this->conn = $conn;
        $this->pk = $pk;

        // Set an alias that we can use for turning columns like "foo"
        // into something more specific like "alias.foo".
        $this->alias = is_array($table) ? current($table) : $table;

        // And set a default from based on the constructor.
        $this->from($table);
    }

    /**
     * Helpful aid for debugging.
     *
     * @return  string
     */
    public function __toString()
    {
        return $this->toSelect()[0];
    }

    /**
     * Affords cloning queries.
     *
     * @see  http://php.net/manual/en/language.oop5.cloning.php
     */
    public function __clone()
    {
        // Nothing to do, just take it all!
    }

    /**
     * Attempts to find a single record.
     *
     * If no record is returned, then an exception is thrown.
     *
     * @param   mixed  $query
     * @param   mixed  $conds
     * @throws  DomainException
     * @return  mixed
     */
    public function find($query = null, $conds = [])
    {
        $result = $this->first($query, $conds);

        if (!$result) {
            throw new DomainException(sprintf(
                'Could not find a single record using "%s".', $this));
        }

        return $result;
    }

    /**
     * Attempts to find a single record.
     *
     * If no record is returned, then null is returned.
     *
     * @param   mixed  $query
     * @param   mixed  $conds
     * @return  mixed
     */
    public function first($query = null, $conds = [])
    {
        $result = $this->limit(1)->all($query, $conds);

        if ($result->valid()) {
            return $result->current();
        }
    }

    /**
     * Finds and returns a list of records limited by the
     * amount passed.
     *
     * @param   mixed  $total
     * @return  Mismatch\DB\Collection
     */
    public function take($limit)
    {
        $this->limit($limit);

        return $this->all();
    }

    /**
     * Finds and returns all of the records.
     *
     * @param   mixed  $query
     * @param   mixed  $conds
     * @return  Mismatch\DB\Collection
     */
    public function all($query = null, $conds = [])
    {
        if ($query) {
            $this->where($query, $conds);
        }

        list($query, $params) = $this->toSelect();

        return $this->raw($query, $params);
    }

    /**
     * Returns the total number of records in the query.
     *
     * @return  int
     */
    public function count($mode = COUNT_NORMAL)
    {
        return $this->all()->count();
    }

    /**
     * Executes an insert, returning the last insert id.
     *
     * @param   array  $data
     * @return  int
     */
    public function insert($data)
    {
        list($query, $params) = $this->toInsert($data);

        $this->raw($query, $params);

        return count($this->raw($query, $params));
    }

    /**
     * Executes an update, returning the number of rows affected.
     *
     * @param   mixed  $query
     * @param   mixed  $conds
     * @return  int
     */
    public function update($query = null, $conds = [], $data = [])
    {
        // Updating records without any need for conds
        if (func_num_args() === 2) {
            $data = $conds;
            $conds = [];
        }

        // Updating records without any need for a modifier query
        if (func_num_args() === 1) {
            $data = $query;
            $query = null;
            $conds = [];
        }

        if ($query) {
            $this->where($query, $conds);
        }

        list($query, $params) = $this->toUpdate($data);

        return count($this->raw($query, $params));
    }

    /**
     * Executes a deletion.
     *
     * @param   mixed  $query
     * @param   mixed  $conds
     * @return  int
     */
    public function delete($query = null, $conds = [])
    {
        if ($query) {
            $this->where($query, $conds);
        }

        list($query, $params) = $this->toDelete();

        return count($this->raw($query, $params));
    }

    /**
     * Executes a raw query.
     *
     * @param   string  $query
     * @param   array   $params
     * @return  Mismatch\DB\Collection
     */
    public function raw($query, array $params = [])
    {
        $stmt = $this->conn->executeQuery($query, $params);

        // Wrap the statement in our own result type, so we have more
        // control over the interface that it exposes.
        return $this->prepareStatement($stmt);
    }

    /**
     * Executes the passed callback inside of a transaction.
     *
     * @param   Closure  $fn
     */
    public function transactional(Closure $fn)
    {
        $this->conn->transactional($fn);
    }

    /**
     * Returns the last insert id.
     *
     * @return  int|string
     */
    public function lastInsertId()
    {
        $this->conn->lastInsertId();
    }

    /**
     * Chooses the columns to select in the result.
     *
     * <code>
     *     // Aliases are supported as array keys
     *     $query->select(['column', 'column' => 'alias']);
     * </code>
     *
     * @param  array  $columns
     */
    public function select(array $columns)
    {
        return $this->addPart('select', $columns);
    }

    /**
     * Sets the table or tables to select data from.
     *
     * By default, the first table that is passed here will be used
     * as the "main" table, which means that we'll assume unadorned
     * columns will be selected from this table.
     *
     * @param   mixed  $table
     * @return  $this
     */
    public function from($table)
    {
        $this->addPart('from', (array) $table);

        return $this;
    }

    /**
     * Adds a single JOIN statement to the query.
     *
     * If $join is an attribute that exists on the model, then
     * that attribute will be allowed to create the join.
     *
     * <code>
     * // INNER JOIN is added by default.
     * Book::all()->join('authors a', ['a.id' => 'book.author_id']);
     *
     * // Although different types of joins can be specified.
     * Book::all()->join('LEFT OUTER JOIN authors a', ['a.id' => 'book.author_id']);
     * </code>
     *
     * @param  string  $table
     * @param  mixed   $conds
     */
    public function join($table, $conds = [])
    {
        return $this->addPart('join', [
            $table => $conds,
        ]);
    }

    /**
     * Adds a set of AND filters to a query chain.
     *
     * @param  mixed  $conds
     * @param  array  $binds
     */
    public function where($conds, array $binds = [])
    {
        if (is_int($conds)) {
            $conds = [$this->pk => $conds];
        }

        $this->getComposite('where')->all($conds, $binds);

        return $this;
    }

    /**
     * Adds a set of OR filters to a query chain.
     *
     * @param  mixed  $conds
     * @param  array  $binds
     */
    public function whereAny($conds, array $binds = [])
    {
        if (is_int($conds)) {
            $conds = [$this->pk => $conds];
        }

        $this->getComposite('where')->any($conds, $binds);

        return $this;
    }

    /**
     * Adds a set of AND HAVING filters to a query chain.
     *
     * @param  mixed  $conds
     * @param  array  $binds
     */
    public function having($conds, array $binds = [])
    {
        $this->getComposite('having')->all($conds, $binds);

        return $this;
    }

    /**
     * Adds a set of OR HAVING filters to a query chain.
     *
     * @param  mixed  $conds
     * @param  array  $binds
     */
    public function havingAny($conds, array $binds = [])
    {
        $this->getComposite('having')->any($conds, $binds);

        return $this;
    }

    /**
     * Determines the offset of results.
     *
     * @param  int  $limit
     */
    public function offset($offset)
    {
        return $this->setPart('offset', $offset);
    }

    /**
     * Determines how many results to return.
     *
     * Passing one will give you a single model back.
     *
     * @param  int  $limit
     */
    public function limit($limit)
    {
        return $this->setPart('limit', $limit);
    }

    /**
     * Determines the columns to group by.
     *
     * @param  array  $columns
     */
    public function group(array $columns)
    {
        return $this->addPart('group', $columns);
    }

    /**
     * Determines the columns to order by.
     *
     * @param  array   $columns
     */
    public function order($columns, $dir = null)
    {
        if (!is_array($columns)) {
            $columns = [$columns => $dir];
        }

        return $this->addPart('order', $columns);
    }

    /**
     * Implementation of IteratorAggregate
     *
     * @return  Iterator
     */
    public function getIterator()
    {
        return $this->all();
    }

    /**
     * Hook to allow preparation of a SQL result just before
     * it's returned to the caller.
     *
     * @param  Doctrine\DBAL\Driver\Statement  $stmt
     */
    protected function prepareStatement($stmt)
    {
        return new Collection($stmt);
    }

    /**
     * Compiles the query as a SELECT statement.
     *
     * @return  array
     */
    private function toSelect()
    {
        if (!$this->hasPart('select')) {
            $this->setPart('select', ['*']);
        }

        $query[] = 'SELECT ' . $this->compileList('select');
        $query[] = 'FROM ' . $this->compileList('from');
        $params = [];

        if ($join = $this->compileJoin()) {
            $query[] = $join[0];
            $params = array_merge($params, $join[1]);
        }

        if ($expr = $this->compileExpression('where')) {
            $query[] = sprintf('WHERE %s', $expr[0]);
            $params = array_merge($params, $expr[1]);
        }

        if ($group = $this->compileList('group')) {
            $query[] = 'GROUP BY ' . $group;
        }

        if ($expr = $this->compileExpression('having')) {
            $query[] = sprintf('HAVING %s', $expr[0]);
            $params = array_merge($params, $expr[1]);
        }

        if ($order = $this->compileList('order')) {
            $query[] = 'ORDER BY ' . $order;
        }

        $query = implode(array_filter($query), ' ');
        $query = $this->compileLimit($query);

        return [$query, $params];
    }

    /**
     * Compiles the query as an UPDATE statement.
     *
     * @param   array  $data
     * @return  array
     */
    private function toUpdate($data)
    {
        $update = $this->compileUpdate($data);
        $query[] = 'UPDATE ' . $this->compileList('from', false);
        $query[] = 'SET ' . $update[0];
        $params = $update[1];

        if ($expr = $this->compileExpression('where')) {
            $query[] = sprintf('WHERE %s', $expr[0]);
            $params = array_merge($params, $expr[1]);
        }

        $query = implode(array_filter($query), ' ');
        $query = $this->compileLimit($query);

        return [$query, $params];
    }

    /**
     * Compiles the query as an INSERT statement.
     *
     * @param   array  $data
     * @return  array
     */
    private function toInsert($data)
    {
        $insert = $this->compileInsert($data);
        $query[] = 'INSERT INTO ' . $this->compileList('from', false);
        $query[] = $insert[0];
        $params = $insert[1];

        $query = implode(array_filter($query), ' ');

        return [$query, $params];
    }

    /**
     * Compiles the query as a DELETE statement.
     *
     * @return  array
     */
    private function toDelete()
    {
        $query[] = 'DELETE FROM ' . $this->compileList('from', false);
        $params = [];

        if ($join = $this->compileJoin()) {
            $query[] = $join[0];
            $params = array_merge($params, $join[1]);
        }

        if ($expr = $this->compileExpression('where')) {
            $query[] = sprintf('WHERE %s', $expr[0]);
            $params = array_merge($params, $expr[1]);
        }

        $query = implode(array_filter($query), ' ');
        $query = $this->compileLimit($query);

        return [$query, $params];
    }

    /**
     * Compiles the JOIN clause of a SQL query.
     *
     * @return  array
     */
    private function compileJoin()
    {
        if (!$this->hasPart('join')) {
            return null;
        }

        $parts = [];
        $params = [];

        foreach ($this->getPart('join', []) as $table => $conds) {
            $sql = $table;

            // Allow an optional INNER JOIN specification, since it's so common
            if (false === strpos(strtoupper($sql), 'JOIN')) {
                $sql = 'INNER JOIN ' . $sql;
            }

            if ($on = $this->compileOn($conds)) {
                $sql .= sprintf(' ON (%s)', $on[0]);

                if ($on[1]) {
                    $params = array_merge($params, $on[1]);
                }
            }

            $parts[] = $sql;
        }

        return [implode($parts, ' '), $params];
    }

    /**
     * Compiles the ON clause of a JOIN.
     *
     * @param  array  $on
     */
    private function compileOn($on)
    {
        if (!$on) {
            return;
        }

        if ($on instanceof Expr\Composite) {
            return $on->compile();
        }

        $expr = new Expr\Composite();

        foreach ($on as $owner => $related) {
            $expr->all([ sprintf('%s = %s', $owner, $related) ]);
        }

        return $expr->compile();
    }

    /**
     * @param   string  $type
     * @return  Mismatch\Composite
     */
    private function getComposite($type)
    {
        if (!$this->hasPart($type)) {
            $this->setPart($type, (new Expr\Composite())->setAlias($this->alias));
        }

        return $this->getPart($type);
    }

    /**
     * Sets a part with a brand new value.
     *
     * @param   string  $name
     * @param   mixed   $value
     * @return  $this
     */
    private function setPart($name, $value)
    {
        $this->parts[$name] = $value;

        return $this;
    }

    /**
     * Adds to a part as if it were an array.
     *
     * @param   string  $name
     * @param   array   $value
     * @return  $this
     */
    private function addPart($name, array $value)
    {
        $this->parts[$name] = array_merge($this->getPart($name, []), $value);

        return $this;
    }

    /**
     * Returns a part, using the default if it doesn't exist.
     *
     * @param   string  $name
     * @param   mixed   $default
     * @return  mixed
     */
    private function getPart($name, $default = null)
    {
        if (!$this->hasPart($name)) {
            $this->setPart($name, $default);
        }

        return $this->parts[$name];
    }

    /**
     * Returns whether or not the query has a particular part.
     *
     * @param   string  $name
     * @return  bool
     */
    private function hasPart($name)
    {
        return isset($this->parts[$name]);
    }

    /**
     * Prepares a list-based clause of a SQL query.
     *
     * @param  array  $query
     * @param  bool   $alias
     */
    private function compileList($type, $aliasFrom = true)
    {
        if (!$this->hasPart($type)) {
            return;
        }

        $parts = [];

        foreach ($this->getPart($type, []) as $source => $alias) {
            // Allow no aliasing as well, as denoted by an it key
            if (is_int($source)) {
                $source = $alias;
                $alias = null;
            }

            switch ($type) {
                // Turn SELECTs into table.column AS alias
                case 'select':
                    $source = Expr\columnize($source, $this->alias);
                    $parts[] = $this->alias($source, $alias);
                    break;

                // Turn FROMs into table AS alias
                case 'from':
                    $parts[] = $aliasFrom ? $this->alias($source, $alias) : $source;
                    break;

                // Turn ORDER BYs into table.column ASC/DESC
                case 'order':
                    $parts[] = Expr\columnize($source, $this->alias) . ' ' . strtoupper($alias);
                    break;

                // Turn GROUP BYs into table.column
                case 'group':
                    $parts[] = Expr\columnize($source, $this->alias);
                    break;
            }
        }

        return implode($parts, ', ');
    }

    /**
     * @param   array  $data
     * @return  array
     */
    private function compileUpdate($data)
    {
        $parts = [];
        $binds = [];

        foreach ($data as $column => $value) {
            $parts[] = sprintf('%s = ?', $column);
            $binds[] = $value;
        }

        return [implode($parts, ', '), $binds];
    }

    /**
     * @param   array  $data
     * @return  array
     */
    private function compileInsert($data)
    {
        $columns = [];
        $values = [];
        $binds = [];

        foreach ($data as $column => $value) {
            $columns[] = $column;
            $values[] = '?';
            $binds[] = $value;
        }

        $columns = implode($columns, ',');
        $values = implode($values, ',');
        $query = sprintf('(%s) VALUES (%s)', $columns, $values);

        return [$query, $binds];
    }

    /**
     * Prepares an expression clause of a SQL query, including
     * WHERE and HAVING clauses.
     *
     * @param  string  $type
     * @param  array   $query
     * @param  array   $params
     */
    private function compileExpression($type)
    {
        if (!$this->hasPart($type)) {
            return;
        }

        $expr = $this->getPart($type);

        if ($expr) {
            return $expr->compile();
        }
    }

    /**
     * Adds the LIMIT and OFFSET parts to a query.
     *
     * @param  string  $query
     */
    private function compileLimit($query)
    {
        $limit = $this->getPart('limit');
        $offset = $this->getPart('offset');

        if ($limit || $offset) {
            return $this->conn
                ->getDatabasePlatform()
                ->modifyLimitQuery($query, $limit, $offset);
        }

        return $query;
    }

    /**
     * Creates an alias for a column or table if the alias is provided.
     *
     * @param   string  $source
     * @param   string  $alias
     * @return  string
     */
    private function alias($source, $alias)
    {
        if (is_string($alias) && $alias) {
            return sprintf("%s AS %s", $source, $alias);
        } else {
            return sprintf("%s", $source);
        }
    }
}
