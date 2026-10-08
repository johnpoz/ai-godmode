<?php
/**
 * Database family: db-list-tables, db-describe-table, db-query-read,
 * db-query-write, db-export.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

use AIGodmode\Scrub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- This family exists to run the SQL an administrator asks for, through $wpdb. There is no higher-level API for arbitrary SQL, and caching a statement the operator is inspecting would return stale rows. Every statement is gated by the master switch, the ability's own switch, manage_options, the read-only or write parser, and the audit log; db-query-read scrubs secrets from the result; db-query-write restores the plugin's own rows if a statement touches them.

final class Db_List_Tables extends Base {
	public static function name(): string {
		return 'godmode/db-list-tables';
	}
	public static function definition(): array {
		return self::make(
			'database',
			'List Tables',
			'List every table in the WordPress database with engine, row estimate and size in bytes.',
			self::obj( array() ),
			self::obj( array( 'database' => self::str(), 'prefix' => self::str(), 'tables' => self::list_of( self::obj( array( 'name' => self::str(), 'engine' => self::str(), 'rows' => array( 'type' => 'integer' ), 'bytes' => array( 'type' => 'integer' ) ) ) ) ), array( 'database', 'prefix', 'tables' ) ),
			false
		);
	}
	public function execute( $input ) {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS rows_est, (DATA_LENGTH + INDEX_LENGTH) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s ORDER BY TABLE_NAME', DB_NAME ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array( 'name' => (string) $r['name'], 'engine' => (string) $r['engine'], 'rows' => (int) $r['rows_est'], 'bytes' => (int) $r['bytes'] );
		}
		return array( 'database' => DB_NAME, 'prefix' => $wpdb->prefix, 'tables' => $out );
	}
}

final class Db_Describe_Table extends Base {
	public static function name(): string {
		return 'godmode/db-describe-table';
	}
	public static function definition(): array {
		return self::make(
			'database',
			'Describe Table',
			'Columns, types, keys and indexes of one table.',
			self::obj( array( 'table' => self::str( 64, 1 ) ), array( 'table' ) ),
			self::obj( array( 'table' => self::str(), 'columns' => self::list_of( self::map() ), 'indexes' => self::list_of( self::map() ) ), array( 'table', 'columns', 'indexes' ) ),
			false
		);
	}
	public function execute( $input ) {
		global $wpdb;
		$table = (string) $input['table'];
		if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
			return self::err( 'godmode_db_bad_table', 'Table names may contain only letters, digits and underscores.' );
		}
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( $exists !== $table ) {
			return self::err( 'godmode_db_no_table', sprintf( 'Table "%s" does not exist.', $table ), 404 );
		}
		$columns = $wpdb->get_results( "SHOW FULL COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array( 'table' => $table, 'columns' => (array) $columns, 'indexes' => (array) $indexes );
	}
}

final class Db_Query_Read extends Base {
	public const MAX_ROWS = 1000;
	public static function name(): string {
		return 'godmode/db-query-read';
	}
	public static function definition(): array {
		return self::make(
			'database',
			'Query (read)',
			'Run one SELECT, SHOW, DESCRIBE or EXPLAIN statement. Rows are capped by SQL (default 100, max 1000): a missing LIMIT is added, a larger one is refused. Locking reads, SLEEP, BENCHMARK, LOAD_FILE and the system schemas are refused. Password hashes, denylisted option or meta values, secret keys inside serialized values and hash-shaped cells are scrubbed.',
			self::obj( array( 'sql' => self::str( 20000, 6 ), 'limit' => self::int( 1, 1000, 100 ) ), array( 'sql' ) ),
			self::obj( array( 'rows' => self::list_of( self::map() ), 'count' => array( 'type' => 'integer' ), 'capped' => self::bool(), 'columns' => self::list_of( self::str() ) ), array( 'rows', 'count', 'capped', 'columns' ) ),
			false
		);
	}

	/**
	 * The statement as the keyword checks should see it: string literals
	 * blanked, comments removed. A keyword inside a literal is harmless and a
	 * keyword inside a comment is invisible to the server, so neither should
	 * decide anything; a keyword hidden behind one must still be found.
	 * "--" is a comment only when followed by whitespace, as in MySQL, and
	 * executable comments are refused outright by the callers.
	 */
	public static function strip( string $sql ): string {
		$sql = (string) preg_replace( "/'(?:\\\\.|''|[^'\\\\])*'|\"(?:\\\\.|\"\"|[^\"\\\\])*\"/s", "''", $sql );
		$sql = (string) preg_replace( '#/\*.*?\*/#s', ' ', $sql );
		$sql = (string) preg_replace( '/(--([ \t][^\n]*)?|#[^\n]*)$/m', ' ', $sql );
		return trim( $sql );
	}

	public function execute( $input ) {
		global $wpdb;
		$sql = trim( (string) $input['sql'] );
		$sql = rtrim( $sql, "; \t\n\r" );
		if ( false !== strpos( $sql, '/*!' ) ) {
			return self::err( 'godmode_db_comment', 'Executable comments (/*! ... */) are refused.' );
		}
		$check = self::strip( $sql );
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $check ) ) {
			return self::err( 'godmode_db_not_read', 'db-query-read accepts only SELECT, SHOW, DESCRIBE or EXPLAIN. Use db-query-write for anything else.' );
		}
		if ( preg_match( '/;\s*\S/', $check ) ) {
			return self::err( 'godmode_db_multi', 'One statement per call.' );
		}
		if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $check ) ) {
			return self::err( 'godmode_db_outfile', 'INTO OUTFILE and DUMPFILE are refused.' );
		}
		if ( preg_match( '/\bEXPLAIN\s+ANALYZE\b|\bFOR\s+UPDATE\b|\bLOCK\s+IN\s+SHARE\s+MODE\b|\b(SLEEP|BENCHMARK|LOAD_FILE)\s*\(/i', $check ) ) {
			return self::err( 'godmode_db_refused', 'Locking reads, EXPLAIN ANALYZE, SLEEP, BENCHMARK and LOAD_FILE are refused through db-query-read.' );
		}
		if ( preg_match( '/\b(information_schema|performance_schema)\b|\bmysql\s*\./i', $check ) ) {
			return self::err( 'godmode_db_refused', 'The information_schema, performance_schema and mysql schemas are refused. Use db-list-tables and db-describe-table.' );
		}
		$limit = (int) ( $input['limit'] ?? 100 );
		$limit = max( 1, min( self::MAX_ROWS, $limit ) );
		$probe = $limit + 1;
		// The cap is enforced by SQL, not by fetching everything and slicing.
		if ( preg_match( '/^SELECT\b/i', $check ) ) {
			if ( preg_match( '/\bLIMIT\s+(\d+)(?:\s*,\s*(\d+)|\s+OFFSET\s+\d+)?\s*$/i', $check, $m ) ) {
				$asked = isset( $m[2] ) ? (int) $m[2] : (int) $m[1];
				if ( $asked > $limit ) {
					return self::err( 'godmode_db_limit', sprintf( 'LIMIT %d exceeds the row cap of %d for this call. Lower it, or raise the limit input (max %d).', $asked, $limit, self::MAX_ROWS ) );
				}
			} else {
				// On a new line so a trailing "-- comment" cannot swallow it.
				$sql .= "\nLIMIT " . $probe;
			}
		}
		$wpdb->suppress_errors( true );
		$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$error = $wpdb->last_error;
		$wpdb->suppress_errors( false );
		if ( '' !== $error ) {
			return self::err( 'godmode_db_error', 'Database error: ' . $error );
		}
		$rows   = (array) $rows;
		$capped = count( $rows ) > $limit;
		$rows   = array_slice( $rows, 0, $limit );
		$rows   = array_map( array( Scrub::class, 'row' ), $rows );
		$cols   = $rows ? array_keys( $rows[0] ) : array();
		return array( 'rows' => $rows, 'count' => count( $rows ), 'capped' => $capped, 'columns' => array_map( 'strval', $cols ) );
	}
}

final class Db_Query_Write extends Base {
	public static function name(): string {
		return 'godmode/db-query-write';
	}
	public static function definition(): array {
		return self::make(
			'database',
			'Query (write)',
			'Run one non-SELECT statement (INSERT, UPDATE, DELETE, ALTER, CREATE, DROP, TRUNCATE, OPTIMIZE, REPAIR). Returns affected rows and insert id. Audit logged. Refuses any statement naming godmode_ options, and restores them if a statement changes them by other means: the switches cannot be flipped through SQL.',
			self::obj( array( 'sql' => self::str( 50000, 6 ) ) + self::ack(), array( 'sql' ) ),
			self::obj( array( 'affected_rows' => array( 'type' => 'integer' ), 'insert_id' => array( 'type' => 'integer' ), 'statement' => self::str() ), array( 'affected_rows', 'insert_id', 'statement' ) ),
			true,
			false
		);
	}
	public function execute( $input ) {
		global $wpdb;
		$sql = trim( (string) $input['sql'] );
		$sql = rtrim( $sql, "; \t\n\r" );
		if ( false !== strpos( $sql, '/*!' ) ) {
			return self::err( 'godmode_db_comment', 'Executable comments (/*! ... */) are refused.' );
		}
		$check = Db_Query_Read::strip( $sql );
		if ( preg_match( '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN)\b/i', $check ) ) {
			return self::err( 'godmode_db_is_read', 'That is a read statement. Use db-query-read.' );
		}
		if ( preg_match( '/;\s*\S/', $check ) ) {
			return self::err( 'godmode_db_multi', 'One statement per call.' );
		}
		if ( preg_match( '/\bINTO\s+(OUTFILE|DUMPFILE)\b/i', $check ) || preg_match( '/\bLOAD\s+DATA\b/i', $check ) ) {
			return self::err( 'godmode_db_outfile', 'File-writing and file-loading statements are refused.' );
		}
		// Self-protection, in the spirit of refusing to deactivate itself: the
		// switches live in godmode_ options and must not be reachable by SQL.
		// The raw statement is checked, literals included, because the name
		// would be inside one.
		if ( false !== stripos( $sql, 'godmode_' ) ) {
			return self::err( 'godmode_self_protect', 'AI Godmode will not run a statement that names its own godmode_ options. Use the settings screen.', 403 );
		}
		// Rows read through db-query-read come back with secrets replaced by
		// markers. Writing one back would store the marker over the secret.
		if ( false !== strpos( $sql, \AIGodmode\Scrub::MARK ) || preg_match( '/s:10:\\\\?"\[redacted\]\\\\?"|"\[redacted\]"/', $sql ) ) {
			return self::err( 'godmode_write_redacted', 'The statement contains a redaction marker from a scrubbed read. Writing it would replace a real secret with the marker. Supply real values.' );
		}
		$before = self::own_rows();
		$wpdb->suppress_errors( true );
		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$error  = $wpdb->last_error;
		$wpdb->suppress_errors( false );
		if ( false === $result || '' !== $error ) {
			return self::err( 'godmode_db_error', 'Database error: ' . ( '' !== $error ? $error : 'statement failed' ) );
		}
		$affected = (int) $wpdb->rows_affected;
		$insert   = (int) $wpdb->insert_id;
		wp_cache_flush();
		// A statement can still reach those rows without naming them (by
		// option_id, by a whole-table UPDATE, by a hex literal). Put them back.
		if ( self::own_rows() !== $before ) {
			$restored = true;
			foreach ( $before as $row ) {
				$restored = $restored && false !== $wpdb->replace( $wpdb->options, $row );
			}
			$keep = array_map( 'intval', array_column( $before, 'option_id' ) );
			if ( empty( $keep ) ) {
				$keep = array( 0 );
			}
			$marks = implode( ',', array_fill( 0, count( $keep ), '%d' ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id NOT IN ({$marks})", array_merge( array( $wpdb->esc_like( 'godmode_' ) . '%' ), $keep ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $marks is a list of %d placeholders built from count(); the values travel through prepare().
			wp_cache_flush();
			return self::err( 'godmode_self_protect', sprintf( 'The statement changed AI Godmode\'s own godmode_ options; they were %s. Its other effects stand (%d rows affected).', $restored ? 'restored' : 'NOT all restored: check the settings screen', $affected ), 403 );
		}
		return array( 'affected_rows' => $affected, 'insert_id' => $insert, 'statement' => strtoupper( (string) preg_replace( '/^(\w+).*/s', '$1', $sql ) ) );
	}

	/** This plugin's own option rows, keyed by name, as a comparable snapshot. */
	private static function own_rows(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $wpdb->esc_like( 'godmode_' ) . '%' ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ $r['option_name'] ] = $r;
		}
		return $out;
	}
}

final class Db_Export extends Base {
	public static function name(): string {
		return 'godmode/db-export';
	}
	public static function definition(): array {
		return self::make(
			'database',
			'Export Database',
			'Write a SQL dump of all tables, or the listed tables, under an unguessable name in wp-content/uploads/godmode-exports (web access denied by .htaccess on Apache and LiteSpeed; other servers get a warning). Returns the relative path. Read it with fs-read or fetch it with fs-zip.',
			self::obj( array( 'tables' => self::list_of( self::str( 64, 1 ) ), 'include_data' => self::bool( true ) ) + self::ack() ),
			self::obj( array( 'path' => self::str(), 'bytes' => array( 'type' => 'integer' ), 'tables' => array( 'type' => 'integer' ), 'rows' => array( 'type' => 'integer' ), 'warning' => self::nullable( self::str() ) ), array( 'path', 'bytes', 'tables', 'rows' ) ),
			true,
			false
		);
	}
	public function execute( $input ) {
		global $wpdb;
		$all    = $wpdb->get_col( 'SHOW TABLES' );
		$tables = ! empty( $input['tables'] ) ? array_values( array_intersect( $all, (array) $input['tables'] ) ) : $all;
		if ( empty( $tables ) ) {
			return self::err( 'godmode_db_no_table', 'None of the requested tables exist.', 404 );
		}
		$dir = self::export_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$file = $dir . '/db-' . gmdate( 'Ymd-His' ) . '-' . \AIGodmode\Paths::unguessable() . '.sql';
		// The dump is appended in table-sized pieces so a large database never sits in memory whole.
		$put = static function ( string $text ) use ( $file ): bool {
			return false !== file_put_contents( $file, $text, FILE_APPEND | LOCK_EX );
		};
		if ( false === file_put_contents( $file, '' ) ) {
			return self::err( 'godmode_db_export_failed', 'Could not open the export file for writing.', 500 );
		}
		$data = ! isset( $input['include_data'] ) || ! empty( $input['include_data'] );
		$rows_total = 0;
		$put( "-- AI Godmode export " . gmdate( 'c' ) . " site " . wp_parse_url( home_url(), PHP_URL_HOST ) . "\nSET FOREIGN_KEY_CHECKS=0;\n" );
		foreach ( $tables as $t ) {
			$create = $wpdb->get_row( "SHOW CREATE TABLE `{$t}`", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$put( "\nDROP TABLE IF EXISTS `{$t}`;\n" . ( $create[1] ?? '' ) . ";\n" );
			if ( ! $data ) {
				continue;
			}
			$offset = 0;
			while ( true ) {
				$chunk = $wpdb->get_results( "SELECT * FROM `{$t}` LIMIT 500 OFFSET {$offset}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( empty( $chunk ) ) {
					break;
				}
				$values = array();
				foreach ( $chunk as $row ) {
					$vals = array();
					foreach ( $row as $v ) {
						$vals[] = null === $v ? 'NULL' : "'" . esc_sql( (string) $v ) . "'";
					}
					$values[] = '(' . implode( ',', $vals ) . ')';
				}
				$put( "INSERT INTO `{$t}` VALUES\n" . implode( ",\n", $values ) . ";\n" );
				$rows_total += count( $chunk );
				$offset     += 500;
			}
		}
		if ( ! $put( "SET FOREIGN_KEY_CHECKS=1;\n" ) ) {
			return self::err( 'godmode_db_export_failed', 'The export file could not be written to the end; it is incomplete.', 500 );
		}
		return array( 'path' => \AIGodmode\Paths::display( $file ), 'bytes' => (int) filesize( $file ), 'tables' => count( $tables ), 'rows' => $rows_total, 'warning' => \AIGodmode\Paths::htaccess_warning() );
	}

	/** Export directory with web access denied; same guard as file backups. */
	public static function export_dir() {
		return \AIGodmode\Paths::protected_dir( 'exports' );
	}
}
