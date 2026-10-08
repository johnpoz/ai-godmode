<?php
/**
 * Diagnostics family: site-health, php-info, constants, hooks-inspect,
 * error-log-tail. get-environment lives in its own file.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

use AIGodmode\Scrub;
use AIGodmode\Paths;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Site_Health extends Base {
	public static function name(): string {
		return 'godmode/site-health';
	}
	public static function definition(): array {
		return self::make( 'diagnostics', 'Site Health', 'Run WordPress Site Health direct tests (php version, https, loopback, updates, and so on) and return each result with its label and status.', self::obj( array() ), self::obj( array( 'tests' => self::list_of( self::map() ), 'count' => array( 'type' => 'integer' ) ), array( 'tests', 'count' ) ), false );
	}
	public function execute( $input ) {
		if ( ! class_exists( '\\WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}
		$health = \WP_Site_Health::get_instance();
		$tests  = \WP_Site_Health::get_tests();
		$out    = array();
		foreach ( (array) ( $tests['direct'] ?? array() ) as $key => $test ) {
			$cb = $test['test'] ?? null;
			if ( is_string( $cb ) && method_exists( $health, 'get_test_' . $cb ) ) {
				$cb = array( $health, 'get_test_' . $cb );
			}
			if ( ! is_callable( $cb ) ) {
				continue;
			}
			try {
				$r = call_user_func( $cb );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( is_array( $r ) ) {
				$out[] = array( 'test' => (string) $key, 'label' => wp_strip_all_tags( (string) ( $r['label'] ?? $key ) ), 'status' => (string) ( $r['status'] ?? '' ), 'badge' => (string) ( $r['badge']['label'] ?? '' ) );
			}
		}
		return array( 'tests' => $out, 'count' => count( $out ) );
	}
}

final class Php_Info extends Base {
	public static function name(): string {
		return 'godmode/php-info';
	}
	public static function definition(): array {
		return self::make( 'diagnostics', 'PHP Info', 'PHP version, SAPI, loaded extensions, and key ini values (memory_limit, max_execution_time, upload sizes, and so on).', self::obj( array() ), self::obj( array( 'version' => self::str(), 'sapi' => self::str(), 'extensions' => self::list_of( self::str() ), 'ini' => self::map() ), array( 'version', 'sapi', 'extensions', 'ini' ) ), false );
	}
	public function execute( $input ) {
		$keys = array( 'memory_limit', 'max_execution_time', 'max_input_vars', 'post_max_size', 'upload_max_filesize', 'max_input_time', 'display_errors', 'error_reporting', 'date.timezone', 'default_socket_timeout' );
		$ini  = array();
		foreach ( $keys as $k ) {
			$ini[ $k ] = ini_get( $k );
		}
		$ext = get_loaded_extensions();
		sort( $ext );
		return array( 'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'extensions' => $ext, 'ini' => $ini );
	}
}

final class Constants extends Base {
	public static function name(): string {
		return 'godmode/constants';
	}
	public static function definition(): array {
		return self::make( 'diagnostics', 'WordPress Constants', 'Values of common WordPress constants (ABSPATH shown relative, WP_DEBUG, memory limits, multisite, and so on). Database and secret constants are redacted.', self::obj( array() ), self::obj( array( 'constants' => self::map() ), array( 'constants' ) ), false );
	}
	public function execute( $input ) {
		$names = array( 'WP_DEBUG', 'WP_DEBUG_LOG', 'WP_DEBUG_DISPLAY', 'SCRIPT_DEBUG', 'WP_MEMORY_LIMIT', 'WP_MAX_MEMORY_LIMIT', 'WP_ENVIRONMENT_TYPE', 'WP_CACHE', 'DISABLE_WP_CRON', 'AUTOMATIC_UPDATER_DISABLED', 'WP_AUTO_UPDATE_CORE', 'MULTISITE', 'FS_METHOD', 'DISALLOW_FILE_EDIT', 'DISALLOW_FILE_MODS', 'FORCE_SSL_ADMIN', 'WP_POST_REVISIONS', 'DB_NAME', 'DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_CHARSET', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
		$redact = array( 'DB_PASSWORD', 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT' );
		$out    = array();
		foreach ( $names as $n ) {
			if ( ! defined( $n ) ) {
				continue;
			}
			$out[ $n ] = in_array( $n, $redact, true ) || Scrub::secret_name( $n ) ? Scrub::MARK : constant( $n );
		}
		$out['ABSPATH'] = '(WordPress root)';
		return array( 'constants' => $out );
	}
}

final class Hooks_Inspect extends Base {
	public static function name(): string {
		return 'godmode/hooks-inspect';
	}
	public static function definition(): array {
		return self::make( 'diagnostics', 'Inspect Hook', 'List the callbacks attached to one action or filter, in priority order, with a readable name for each.', self::obj( array( 'hook' => self::str( 200, 1 ) ), array( 'hook' ) ), self::obj( array( 'hook' => self::str(), 'callbacks' => self::list_of( self::map() ), 'count' => array( 'type' => 'integer' ) ), array( 'hook', 'callbacks', 'count' ) ), false );
	}
	public function execute( $input ) {
		global $wp_filter;
		$hook = (string) $input['hook'];
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return array( 'hook' => $hook, 'callbacks' => array(), 'count' => 0 );
		}
		$out = array();
		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $cbs ) {
			foreach ( $cbs as $cb ) {
				$fn   = $cb['function'];
				$name = 'closure';
				if ( is_string( $fn ) ) {
					$name = $fn;
				} elseif ( is_array( $fn ) ) {
					$name = ( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0] ) . '::' . (string) $fn[1];
				} elseif ( is_object( $fn ) && ! $fn instanceof \Closure ) {
					$name = get_class( $fn ) . '::__invoke';
				}
				$out[] = array( 'priority' => (int) $priority, 'callback' => $name, 'accepted_args' => (int) ( $cb['accepted_args'] ?? 1 ) );
			}
		}
		return array( 'hook' => $hook, 'callbacks' => $out, 'count' => count( $out ) );
	}
}

final class Error_Log_Tail extends Base {
	public const MAX = 262144;
	public static function name(): string {
		return 'godmode/error-log-tail';
	}
	public static function definition(): array {
		return self::make( 'diagnostics', 'Tail Error Log', 'Return the last N lines of the active PHP or WordPress debug log (wp-content/debug.log or the ini error_log). Lines are scrubbed of secrets.', self::obj( array( 'lines' => self::int( 1, 2000, 200 ) ) ), self::obj( array( 'source' => self::str(), 'lines' => self::list_of( self::str() ), 'returned' => array( 'type' => 'integer' ) ), array( 'source', 'lines', 'returned' ) ), false );
	}
	public function execute( $input ) {
		$candidates = array();
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) ) {
			$candidates[] = WP_DEBUG_LOG;
		}
		$candidates[] = rtrim( (string) WP_CONTENT_DIR, '/' ) . '/debug.log';
		$ini          = (string) ini_get( 'error_log' );
		if ( '' !== $ini ) {
			$candidates[] = $ini;
		}
		$file = '';
		foreach ( $candidates as $c ) {
			if ( $c && is_readable( $c ) && is_file( $c ) ) {
				$file = $c;
				break;
			}
		}
		if ( '' === $file ) {
			return array( 'source' => 'none', 'lines' => array(), 'returned' => 0 );
		}
		$want = (int) ( $input['lines'] ?? 200 );
		$size = filesize( $file );
		$data = $size > self::MAX ? (string) file_get_contents( $file, false, null, $size - self::MAX ) : (string) file_get_contents( $file );
		$all  = explode( "\n", rtrim( $data, "\n" ) );
		$tail = array_slice( $all, -1 * $want );
		$tail = array_map( array( Scrub::class, 'text' ), $tail );
		$src  = 0 === strpos( $file, Paths::root() ) ? Paths::display( $file ) : basename( $file );
		return array( 'source' => $src, 'lines' => array_values( $tail ), 'returned' => count( $tail ) );
	}
}
