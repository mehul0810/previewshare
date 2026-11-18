<?php
/**
 * Dedicated database connection for an atomic review write.
 *
 * @package PreviewShare
 */

namespace PreviewShare\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.RestrictedClasses, WordPress.DB.RestrictedFunctions -- The review transaction must own its connection and cannot use wpdb's automatic statement replay.
// phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged -- Native warnings can contain credentials or review data. Failures are checked and reported without driver messages.

/**
 * Owns one native connection and never reconnects or replays a statement.
 *
 * @internal
 */
final class ReviewWriteConnection {
	/**
	 * Whether this connection has an open transaction.
	 *
	 * @var bool
	 */
	private bool $active = false;

	/**
	 * The last insert ID observed on this connection.
	 *
	 * @var int
	 */
	private int $insert_id = 0;

	/**
	 * Constructor.
	 *
	 * @param \mysqli|\PDO|null $handle Dedicated nonpersistent connection.
	 * @param array             $tables Current site's participating tables.
	 * @phpstan-param list<string> $tables
	 */
	private function __construct( private \mysqli|\PDO|null $handle, private array $tables ) {}

	/**
	 * Open a private connection to the current site's database.
	 *
	 * Null indicates unsupported capabilities before any mutation. Connection
	 * and SQL failures throw, so callers cannot treat an uncertain outcome
	 * as permission to retry with wpdb.
	 *
	 * @param \wpdb $database WordPress database object.
	 * @param array $tables   Current site's options, posts and postmeta tables.
	 * @phpstan-param list<string> $tables
	 * @throws \RuntimeException When the tables or connection cannot be verified.
	 * @return self|null
	 */
	public static function open( \wpdb $database, array $tables ): ?self {
		$expected = [ $database->options, $database->posts, $database->postmeta ];
		if ( $expected !== $tables ) {
			throw new \RuntimeException( 'Invalid review database tables.' );
		}

		foreach ( $tables as $table ) {
			if ( ! self::valid_identifier( $table ) ) {
				throw new \RuntimeException( 'Invalid review database identifier.' );
			}
		}

		if ( 'wpdb' === get_class( $database ) && function_exists( 'mysqli_stmt_get_result' ) ) {
			$connection = self::open_mysql( $database, $tables );
		} elseif ( 0 === strcasecmp( 'WP_SQLite_DB', get_class( $database ) ) && defined( 'SQLITE_DB_DROPIN_VERSION' ) && extension_loaded( 'pdo_sqlite' ) ) {
			$connection = self::open_sqlite( $database, $tables );
		} else {
			return null;
		}

		if ( null !== $connection && ! $connection->tables_are_transactional() ) {
			$connection->close();
			return null;
		}

		return $connection;
	}

	/**
	 * Connect to the same MySQL server/schema as the active WordPress connection.
	 *
	 * @param \wpdb $database WordPress database object.
	 * @param array $tables   Current site's participating tables.
	 * @phpstan-param list<string> $tables
	 * @throws \RuntimeException When the tables or connection cannot be verified.
	 * @return self|null
	 */
	private static function open_mysql( \wpdb $database, array $tables ): ?self {
		$source = $database->__get( 'dbh' );
		if ( ! $source instanceof \mysqli ) {
			throw new \RuntimeException( 'WordPress MySQL connection is unavailable.' );
		}

		// This reads connection settings without issuing transaction commands or using wpdb's query filters.
		$identity_sql = 'SELECT DATABASE() AS database_name, @@hostname AS server_hostname, @@server_id AS server_id';
		$session_sql  = $identity_sql . ', @@SESSION.character_set_client AS charset, @@SESSION.character_set_results AS results_charset, @@SESSION.character_set_connection AS connection_charset, @@SESSION.collation_connection AS collation, @@SESSION.sql_mode AS sql_mode';

		try {
			$result = @$source->query( $session_sql );
			if ( ! $result instanceof \mysqli_result ) {
				throw new \RuntimeException( 'Could not read the WordPress MySQL session.' );
			}
			$session = @$result->fetch_assoc();
			$result->free();

			$charset   = $session['charset'] ?? null;
			$collation = $session['collation'] ?? null;
			$schema    = $session['database_name'] ?? null;
			$sql_mode  = $session['sql_mode'] ?? null;
			if ( ! is_string( $schema ) || '' === $schema || ! is_string( $sql_mode ) ) {
				throw new \RuntimeException( 'WordPress MySQL session is unavailable.' );
			}
			if ( ! is_string( $charset ) || ! is_string( $collation ) || ! self::valid_identifier( $charset ) || ! self::valid_identifier( $collation ) || $charset !== $session['results_charset'] || $charset !== $session['connection_charset'] ) {
				return null;
			}

			// Keep Core's host/socket/port and flags, but never obtain a pooled connection.
			$dbhost = (string) $database->__get( 'dbhost' );
			$dbhost = 0 === strpos( $dbhost, 'p:' ) ? substr( $dbhost, 2 ) : $dbhost;
			$host   = $database->parse_db_host( $dbhost );
			if ( false === $host ) {
				throw new \RuntimeException( 'Could not resolve the WordPress MySQL host.' );
			}
			[ $hostname, $port, $socket, $is_ipv6 ] = $host;
			if ( $is_ipv6 ) {
				$hostname = '[' . $hostname . ']';
			}

			$handle = mysqli_init();
			if ( false === $handle || ! @$handle->options( MYSQLI_OPT_CONNECT_TIMEOUT, 2 ) || ! @$handle->real_connect(
				$hostname,
				(string) $database->__get( 'dbuser' ),
				(string) $database->__get( 'dbpassword' ),
				$schema,
				null === $port ? null : (int) $port,
				$socket,
				defined( 'MYSQL_CLIENT_FLAGS' ) ? (int) MYSQL_CLIENT_FLAGS : 0
			) ) {
				throw new \RuntimeException( 'Could not open the dedicated MySQL connection.' );
			}

			$connection = new self( $handle, $tables );
			$identity   = $connection->get_row( $identity_sql );
			foreach ( [ 'database_name', 'server_hostname', 'server_id' ] as $key ) {
				if ( (string) ( $session[ $key ] ?? '' ) !== (string) ( $identity[ $key ] ?? '' ) ) {
					throw new \RuntimeException( 'The dedicated MySQL connection reached a different database.' );
				}
			}
			if ( ! @$handle->set_charset( $charset ) ) {
				throw new \RuntimeException( 'Could not initialize the dedicated MySQL connection character set.' );
			}
			$connection->execute( "SET NAMES $charset COLLATE $collation" );
			$connection->execute( 'SET SESSION sql_mode = ?, autocommit = 1, innodb_lock_wait_timeout = 2, lock_wait_timeout = 2', [ $sql_mode ] );

			return $connection;
		} catch ( \mysqli_sql_exception $exception ) {
			throw new \RuntimeException( 'Could not open the dedicated MySQL connection.' );
		}
	}

	/**
	 * Open the file backing WordPress's active SQLite connection.
	 *
	 * @param \wpdb $database WordPress database object.
	 * @param array $tables   Current site's participating tables.
	 * @phpstan-param list<string> $tables
	 * @throws \RuntimeException When the tables or connection cannot be verified.
	 * @return self|null
	 */
	private static function open_sqlite( \wpdb $database, array $tables ): ?self {
		$driver = $database->__get( 'dbh' );
		if ( ! is_object( $driver ) ) {
			throw new \RuntimeException( 'WordPress SQLite connection is unavailable.' );
		}

		try {
			if ( is_callable( [ $driver, 'get_sqlite_pdo' ] ) ) {
				$source = $driver->get_sqlite_pdo();
			} elseif ( is_callable( [ $driver, 'get_connection' ] ) ) {
				// The older AST driver exposes PDO through its connection wrapper.
				$wrapper = $driver->get_connection();
				$source  = is_object( $wrapper ) && is_callable( [ $wrapper, 'get_pdo' ] ) ? $wrapper->get_pdo() : null;
			} else {
				$source = is_callable( [ $driver, 'get_pdo' ] ) ? $driver->get_pdo() : null;
			}
			if ( ! $source instanceof \PDO || 'sqlite' !== $source->getAttribute( \PDO::ATTR_DRIVER_NAME ) ) {
				return null;
			}

			$statement = $source->query( 'PRAGMA database_list' );
			if ( false === $statement ) {
				throw new \RuntimeException( 'Could not inspect the WordPress SQLite connection.' );
			}
			$databases = $statement->fetchAll( \PDO::FETCH_ASSOC );
			$statement->closeCursor();
			$main = $databases[0] ?? [];
			foreach ( $databases as $entry ) {
				if ( ! in_array( $entry['name'], [ 'main', 'temp' ], true ) ) {
					return null;
				}
			}
			if ( 'main' !== ( $main['name'] ?? null ) || ! is_string( $main['file'] ) || '' === $main['file'] ) {
				return null;
			}

			$configured = defined( 'DB_PATH' ) ? DB_PATH : ( defined( 'FQDB' ) ? FQDB : null );
			$path       = realpath( $main['file'] );
			if ( false === $path || ! is_file( $path ) || ! is_string( $configured ) || $path !== realpath( $configured ) ) {
				throw new \RuntimeException( 'The active WordPress SQLite file could not be verified.' );
			}

			// PHP 8.5 deprecates the SQLite-specific constants on the PDO base class.
			$open_flags = defined( 'Pdo\\Sqlite::ATTR_OPEN_FLAGS' ) ? (int) constant( 'Pdo\\Sqlite::ATTR_OPEN_FLAGS' ) : \PDO::SQLITE_ATTR_OPEN_FLAGS;
			$read_write = defined( 'Pdo\\Sqlite::OPEN_READWRITE' ) ? (int) constant( 'Pdo\\Sqlite::OPEN_READWRITE' ) : \PDO::SQLITE_OPEN_READWRITE;
			$sqlite     = new \PDO(
				'sqlite:' . $path,
				null,
				null,
				[
					\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
					$open_flags       => $read_write,
				]
			);

			$connection = new self( $sqlite, $tables );
			$connection->execute( 'PRAGMA busy_timeout = 2000' );
			$journal = (string) self::sqlite_setting( $source, 'journal_mode' );
			if ( in_array( $journal, [ 'off', 'memory' ], true ) || $journal !== ( $connection->get_row( 'PRAGMA main.journal_mode' )['journal_mode'] ?? null ) || 1 === self::sqlite_setting( $source, 'query_only' ) ) {
				$connection->close();
				return null;
			}

			foreach ( [ 'foreign_keys', 'recursive_triggers', 'synchronous' ] as $pragma ) {
				$value = self::sqlite_setting( $source, $pragma );
				$connection->execute( "PRAGMA $pragma = $value" );
			}
			return $connection;
		} catch ( \PDOException $exception ) {
			throw new \RuntimeException( 'Could not open the dedicated SQLite connection.' );
		}
	}

	/**
	 * Read a connection-local SQLite setting from the WordPress connection.
	 *
	 * @param \PDO   $source WordPress SQLite connection.
	 * @param string $pragma Internal pragma name.
	 * @throws \RuntimeException When a required SQLite setting cannot be read.
	 * @return int|string
	 */
	private static function sqlite_setting( \PDO $source, string $pragma ): int|string {
		$statement = $source->query( "PRAGMA $pragma" );
		if ( false === $statement ) {
			throw new \RuntimeException( 'Could not read the WordPress SQLite settings.' );
		}

		$value = $statement->fetchColumn();
		$statement->closeCursor();
		if ( 'journal_mode' === $pragma && is_string( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) && (int) $value >= 0 && (int) $value <= 3 ) {
			return (int) $value;
		}

		throw new \RuntimeException( 'Could not verify the WordPress SQLite settings.' );
	}

	/**
	 * Check that all participating tables support rollback.
	 *
	 * @throws \RuntimeException When a required site table is unavailable.
	 * @return bool
	 */
	private function tables_are_transactional(): bool {
		$tables = $this->tables;
		if ( $this->is_mysql() ) {
			$settings = $this->get_row( 'SELECT @@lower_case_table_names AS case_mode' );
			$column   = 'CAST(TABLE_NAME AS BINARY)';
			if ( 0 !== (int) ( $settings['case_mode'] ?? 0 ) ) {
				$tables = array_map( 'strtolower', $tables );
				$column = 'LOWER(TABLE_NAME)';
			}
			$counts = $this->get_row(
				"SELECT COUNT(*) AS found, SUM(ENGINE = 'InnoDB' AND TABLE_TYPE = 'BASE TABLE') AS transactional
				 FROM information_schema.TABLES
				 WHERE CAST(TABLE_SCHEMA AS BINARY) = CAST(DATABASE() AS BINARY) AND $column IN (?, ?, ?)",
				$tables
			);
		} else {
			$counts = $this->get_row(
				"SELECT COUNT(*) AS found, SUM(type = 'table' AND sql LIKE 'CREATE TABLE %') AS transactional
				 FROM main.sqlite_master WHERE name IN (?, ?, ?)",
				$tables
			);
		}

		if ( count( $tables ) !== (int) ( $counts['found'] ?? 0 ) ) {
			throw new \RuntimeException( 'Review database tables are unavailable.' );
		}

		return count( $tables ) === (int) ( $counts['transactional'] ?? 0 );
	}

	/**
	 * Begin the transaction and hold the participating table definitions stable.
	 *
	 * @throws \RuntimeException When the transaction cannot start or the tables are unsuitable.
	 * @return void
	 */
	public function begin(): void {
		if ( $this->active ) {
			throw new \RuntimeException( 'The review transaction is already active.' );
		}

		try {
			$this->execute( $this->is_mysql() ? 'START TRANSACTION' : 'BEGIN IMMEDIATE' );
			$this->active = true;

			if ( $this->is_mysql() ) {
				foreach ( $this->tables as $table ) {
					$quoted = $this->quote_identifier( $table );
					$this->get_row( "SELECT 1 FROM $quoted LIMIT 0" );
				}
			}
			// Recheck after locking so a table conversion between preflight and BEGIN cannot weaken rollback.
			if ( ! $this->tables_are_transactional() ) {
				throw new \RuntimeException( 'Review database tables changed during preflight.' );
			}
		} catch ( \RuntimeException $exception ) {
			$this->close();
			throw $exception;
		}
	}

	/**
	 * Execute a prepared read and return its first row.
	 *
	 * @param string                $sql        Query with positional placeholders.
	 * @param list<int|string|null> $parameters Placeholder values.
	 * @throws \RuntimeException When the statement or read fails.
	 * @return array<string, mixed>|null
	 */
	public function get_row( string $sql, array $parameters = [] ): ?array {
		$statement = $this->prepare_and_execute( $sql, $parameters );
		try {
			if ( $statement instanceof \mysqli_stmt ) {
				$result = @$statement->get_result();
				if ( false === $result ) {
					throw new \RuntimeException( 'Could not read the review database result.' );
				}
				$row = @$result->fetch_assoc();
				$result->free();
				if ( false === $row ) {
					throw new \RuntimeException( 'Could not read the review database row.' );
				}
				return $row;
			}

			$row = $statement->fetch( \PDO::FETCH_ASSOC );
			return false === $row ? null : $row;
		} catch ( \mysqli_sql_exception | \PDOException $exception ) {
			throw new \RuntimeException( 'Could not read the review database row.' );
		} finally {
			self::close_statement( $statement );
		}
	}

	/**
	 * Execute a prepared statement and return its affected row count.
	 *
	 * @param string                $sql        Query with positional placeholders.
	 * @param list<int|string|null> $parameters Placeholder values.
	 * @throws \RuntimeException When the statement or status read fails.
	 * @return int
	 */
	public function execute( string $sql, array $parameters = [] ): int {
		$statement = $this->prepare_and_execute( $sql, $parameters );
		try {
			if ( $statement instanceof \mysqli_stmt ) {
				$this->insert_id = (int) $statement->insert_id;
				return (int) $statement->affected_rows;
			}

			$this->insert_id = $this->handle instanceof \PDO ? (int) $this->handle->lastInsertId() : 0;
			return $statement->rowCount();
		} catch ( \PDOException $exception ) {
			throw new \RuntimeException( 'Could not read the review database write result.' );
		} finally {
			self::close_statement( $statement );
		}
	}

	/**
	 * Bind native positional parameters and execute exactly once.
	 *
	 * @param string                $sql        Query with positional placeholders.
	 * @param list<int|string|null> $parameters Placeholder values.
	 * @throws \RuntimeException When the connection or statement fails.
	 * @return \mysqli_stmt|\PDOStatement
	 */
	private function prepare_and_execute( string $sql, array $parameters ): \mysqli_stmt|\PDOStatement {
		$handle = $this->handle;
		if ( null === $handle ) {
			throw new \RuntimeException( 'The dedicated review database connection is closed.' );
		}

		$statement = null;
		try {
			$statement = @$handle->prepare( $sql );
			if ( false === $statement ) {
				throw new \RuntimeException( 'Could not prepare the review database statement.' );
			}

			if ( $statement instanceof \mysqli_stmt ) {
				$types = implode( '', array_map( static fn( $value ): string => is_int( $value ) ? 'i' : 's', $parameters ) );
				if ( $parameters && ! @$statement->bind_param( $types, ...$parameters ) ) {
					throw new \RuntimeException( 'Could not bind the review database statement.' );
				}
			} else {
				foreach ( $parameters as $index => $value ) {
					$type = is_int( $value ) ? \PDO::PARAM_INT : ( null === $value ? \PDO::PARAM_NULL : \PDO::PARAM_STR );
					if ( ! $statement->bindValue( $index + 1, $value, $type ) ) {
						throw new \RuntimeException( 'Could not bind the review database statement.' );
					}
				}
			}

			if ( ! @$statement->execute() ) {
				throw new \RuntimeException( 'Could not execute the review database statement.' );
			}
			if ( $handle instanceof \mysqli && $handle->warning_count > 0 && preg_match( '/^(INSERT|UPDATE)\b/i', $sql ) ) {
				// WordPress normally validates column encodings and lengths before
				// writing. Never commit a native write that silently changed a value.
				throw new \RuntimeException( 'The review database could not store the exact values.' );
			}

			return $statement;
		} catch ( \RuntimeException $exception ) {
			if ( $statement instanceof \mysqli_stmt || $statement instanceof \PDOStatement ) {
				self::close_statement( $statement );
			}
			// Driver messages can contain SQL values, user identities or connection information.
			throw new \RuntimeException( 'Could not execute the review database statement.' );
		}
	}

	/**
	 * Release statement resources without obscuring the operation's result.
	 *
	 * @param \mysqli_stmt|\PDOStatement $statement Native statement.
	 * @return void
	 */
	private static function close_statement( \mysqli_stmt|\PDOStatement $statement ): void {
		try {
			if ( $statement instanceof \mysqli_stmt ) {
				@$statement->close();
			} else {
				$statement->closeCursor();
			}
		} catch ( \mysqli_sql_exception | \PDOException $exception ) {
			// Releasing the write connection also releases any remaining statement.
			return;
		}
	}

	/**
	 * Return the most recent inserted ID. Call immediately after the insert.
	 *
	 * @return int
	 */
	public function insert_id(): int {
		return $this->insert_id;
	}

	/**
	 * Whether locking reads require a MySQL FOR UPDATE clause.
	 *
	 * @return bool
	 */
	public function is_mysql(): bool {
		return $this->handle instanceof \mysqli;
	}

	/**
	 * Quote a table from the preflight-validated site tables.
	 *
	 * @param string $identifier Site table name.
	 * @throws \RuntimeException When the table was not verified by preflight.
	 * @return string
	 */
	public function quote_identifier( string $identifier ): string {
		if ( ! in_array( $identifier, $this->tables, true ) ) {
			throw new \RuntimeException( 'Invalid review database table.' );
		}

		return $this->is_mysql() ? "`$identifier`" : "main.`$identifier`";
	}

	/**
	 * Whether a connection setting or table name is a plain SQL identifier.
	 *
	 * @param string $identifier Identifier to validate.
	 * @return bool
	 */
	private static function valid_identifier( string $identifier ): bool {
		return 1 === preg_match( '/\A[A-Za-z0-9_]+\z/D', $identifier );
	}

	/**
	 * Commit exactly once. A failure closes the connection without retrying.
	 *
	 * @throws \RuntimeException When the active transaction cannot be confirmed committed.
	 * @return void
	 */
	public function commit(): void {
		if ( ! $this->active ) {
			throw new \RuntimeException( 'No review transaction is active.' );
		}

		try {
			$this->execute( 'COMMIT' );
			$this->active = false;
		} catch ( \RuntimeException $exception ) {
			$this->close();
			throw $exception;
		}
	}

	/**
	 * Roll back only this connection's transaction.
	 *
	 * @return void
	 */
	public function rollback(): void {
		if ( ! $this->active ) {
			return;
		}

		try {
			$this->execute( 'ROLLBACK' );
			$this->active = false;
		} catch ( \RuntimeException $exception ) {
			$this->close();
		}
	}

	/**
	 * Dispose of the native connection, rolling back any uncommitted writes.
	 *
	 * @return void
	 */
	public function close(): void {
		$handle       = $this->handle;
		$this->handle = null;
		$this->active = false;

		if ( $handle instanceof \mysqli ) {
			try {
				@$handle->close();
			} catch ( \mysqli_sql_exception $exception ) {
				// The connection will also be released when the local reference is dropped.
				return;
			}
		}
	}
}
