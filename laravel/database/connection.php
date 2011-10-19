<?php namespace Laravel\Database; use PDO, PDOStatement;

class Connection {

	/**
	 * The connection configuration array.
	 *
	 * @var array
	 */
	protected $config;

	/**
	 * The query grammar instance for the connection.
	 *
	 * @var Grammars\Grammar
	 */
	protected $grammar;

	/**
	 * The raw PDO connection instance.
	 *
	 * @var PDO
	 */
	public $pdo;

	/**
	 * All of the queries that have been executed on the connection.
	 *
	 * @var array
	 */
	public $queries = array();

	/**
	 * Create a new database connection instance.
	 *
	 * @param  PDO    $pdo
	 * @param  array  $config
	 * @return void
	 */
	public function __construct(PDO $pdo, $config)
	{
		$this->pdo = $pdo;
		$this->config = $config;
	}

	/**
	 * Execute a SQL query against the connection and return a single column result.
	 *
	 * <code>
	 *		// Get the total number of rows on a table
	 *		$count = DB::connection()->only('select count(*) from users');
	 *
	 *		// Get the sum of payment amounts from a table
	 *		$sum = DB::connection()->only('select sum(amount) from payments')
	 * </code>
	 *
	 * @param  string  $sql
	 * @param  array   $bindings
	 * @return mixed
	 */
	public function only($sql, $bindings = array())
	{
		$result = (array) $this->first($sql, $bindings);

		return reset($result);
	}

	/**
	 * Execute a SQL query against the connection and return the first result.
	 *
	 * <code>
	 *		// Execute a query against the database connection
	 *		$user = DB::connection()->first('select * from users');
	 *
	 *		// Execute a query with bound parameters
	 *		$user = DB::connection()->first('select * from users where id = ?', array($id));
	 * </code>
	 *
	 * @param  string  $sql
	 * @param  array   $bindings
	 * @return object
	 */
	public function first($sql, $bindings = array())
	{
		if (count($results = $this->query($sql, $bindings)) > 0) return $results[0];
	}

	/**
	 * Execute a SQL query against the connection.
	 *
	 * The method returns the following based on query type:
	 *
	 *     SELECT -> Array of stdClasses
	 *     UPDATE -> Number of rows affected.
	 *     DELETE -> Number of Rows affected.
	 *     ELSE   -> Boolean true / false depending on success.
	 *
	 * <code>
	 *		// Execute a query against the database connection
	 *		$users = DB::connection()->query('select * from users');
	 *
	 *		// Execute a query with bound parameters
	 *		$user = DB::connection()->query('select * from users where id = ?', array($id));
	 * </code>
	 *
	 * @param  string  $sql
	 * @param  array   $bindings
	 * @return mixed
	 */
	public function query($sql, $bindings = array())
	{
		// First we need to remove all expressions from the bindings
		// since they will be placed into the query as raw strings.
		foreach ($bindings as $key => $value)
		{
			if ($value instanceof Expression) unset($bindings[$key]);
		}

		$sql = $this->transform($sql, $bindings);

		$this->queries[] = compact('sql', 'bindings');

		return $this->execute($this->pdo->prepare(trim($sql)), $bindings);
	}

	/**
	 * Transform an SQL query into an executable query.
	 *
	 * Laravel provides a convenient short-cut when writing raw queries for
	 * handling cumbersome "where in" statements. This method will transform
	 * those segments into their full SQL counterparts.
	 *
	 * @param  string  $sql
	 * @param  array   $bindings
	 * @return string
	 */
	protected function transform($sql, $bindings)
	{
		if (strpos($sql, '(...)') === false) return $sql;

		for ($i = 0; $i < count($bindings); $i++)
		{
			// If the binding is an array, we can assume it is being used to fill
			// a "where in" condition, so we will replace the next place-holder
			// in the query with the correct number of parameters based on the
			// number of elements in this binding.
			if (is_array($bindings[$i]))
			{
				$parameters = implode(', ', array_fill(0, count($bindings[$i]), '?'));

				$sql = preg_replace('~\(\.\.\.\)~', "({$parameters})", $sql, 1);
			}
		}

		return $sql;
	}

	/**
	 * Execute a prepared PDO statement and return the appropriate results.
	 *
	 * @param  PDOStatement  $statement
	 * @param  array         $results
	 * @return mixed
	 */
	protected function execute(PDOStatement $statement, $bindings)
	{
		$result = $statement->execute($bindings);

		if (strpos(strtoupper($statement->queryString), 'SELECT') === 0)
		{
			return $statement->fetchAll(PDO::FETCH_CLASS, 'stdClass');
		}
		elseif (strpos(strtoupper($statement->queryString), 'INSERT') === 0)
		{
			return $result;
		}

		return $statement->rowCount();
	}

	/**
	 * Begin a fluent query against a table.
	 *
	 * @param  string  $table
	 * @return Query
	 */
	public function table($table)
	{
		return new Query($this, $this->grammar(), $table);
	}

	/**
	 * Create a new query grammar for the connection.
	 *
	 * @return Grammars\Grammar
	 */
	protected function grammar()
	{
		if (isset($this->grammar)) return $this->grammar;

		switch (isset($this->config['grammar']) ? $this->config['grammar'] : $this->driver())
		{
			case 'mysql':
				return $this->grammar = new Grammars\MySQL;

			default:
				return $this->grammar = new Grammars\Grammar;
		}
	}

	/**
	 * Get the driver name for the database connection.
	 *
	 * @return string
	 */
	public function driver()
	{
		return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
	}

	/**
	 * Magic Method for dynamically beginning queries on database tables.
	 */
	public function __call($method, $parameters)
	{
		return $this->table($method);
	}

}