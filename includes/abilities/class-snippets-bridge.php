<?php
/**
 * Bridge between Godmode and the Code Snippets plugin.
 *
 * Godmode never writes to the Code Snippets table behind its back. Every
 * read and write goes through the public functions Code Snippets itself uses
 * (namespace Code_Snippets), so its own validation, caching and hooks run.
 *
 * Two things live here rather than in the ability classes:
 *
 *  1. The compatibility check. The Code Snippets abilities register only when
 *     every function and class they call exists. If a Code Snippets release
 *     renames one, the whole family switches itself off and the Abilities tab
 *     says which name went missing, instead of an ability failing mid-call.
 *
 *  2. The version history. Code Snippets (free) keeps no code history, so
 *     Godmode saves the previous version of a snippet before every change it
 *     makes. That is what makes every edit through Godmode undoable.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Snippets_Bridge {

	/** Option prefix for per-snippet history, autoload off. */
	public const HISTORY_PREFIX = 'godmode_snippet_history_';

	/** Versions kept per snippet. Oldest drop off first. */
	public const HISTORY_KEEP = 20;

	/** Functions in the Code_Snippets namespace the family depends on. */
	public const REQUIRED_FUNCTIONS = array(
		'code_snippets',
		'get_snippets',
		'get_snippet',
		'save_snippet',
		'update_snippet_fields',
		'activate_snippet',
		'deactivate_snippet',
		'trash_snippet',
		'restore_snippet',
		'test_snippet_code',
	);

	/** Classes the family depends on. */
	public const REQUIRED_CLASSES = array(
		'Code_Snippets\\Model\\Snippet',
		'Code_Snippets\\Utils\\Validator',
	);

	/** Scopes a new snippet may be created with. "condition" is a Pro feature. */
	public const SCOPES = array(
		'global', 'admin', 'front-end', 'single-use',
		'content', 'head-content', 'body-content', 'footer-content',
		'admin-css', 'site-css', 'site-head-js', 'site-footer-js',
	);

	// -----------------------------------------------------------------------
	// Section: Compatibility.
	// -----------------------------------------------------------------------

	/**
	 * State of the Code Snippets integration.
	 *
	 * @return array{state:string, version:?string, missing:array<string>}
	 *         state is "absent" (plugin not active), "ok", or "incompatible".
	 */
	public static function status(): array {
		if ( ! defined( 'CODE_SNIPPETS_VERSION' ) && ! function_exists( 'Code_Snippets\\code_snippets' ) ) {
			return array( 'state' => 'absent', 'version' => null, 'missing' => array() );
		}
		$missing = array();
		foreach ( self::REQUIRED_FUNCTIONS as $fn ) {
			if ( ! function_exists( 'Code_Snippets\\' . $fn ) ) {
				$missing[] = 'Code_Snippets\\' . $fn . '()';
			}
		}
		foreach ( self::REQUIRED_CLASSES as $class ) {
			if ( ! class_exists( $class ) ) {
				$missing[] = $class;
			}
		}
		return array(
			'state'   => $missing ? 'incompatible' : 'ok',
			'version' => defined( 'CODE_SNIPPETS_VERSION' ) ? (string) CODE_SNIPPETS_VERSION : null,
			'missing' => $missing,
		);
	}

	public static function available(): bool {
		return 'ok' === self::status()['state'];
	}

	// -----------------------------------------------------------------------
	// Section: Loading and shaping.
	// -----------------------------------------------------------------------

	/**
	 * Load one site-wide snippet or return a crafted error.
	 *
	 * @return \Code_Snippets\Model\Snippet|\WP_Error
	 */
	public static function load( int $id ) {
		if ( $id < 1 ) {
			return new \WP_Error( 'godmode_snippet_id', 'A snippet id must be a positive integer.', array( 'status' => 400 ) );
		}
		$snippet = \Code_Snippets\get_snippet( $id, false );
		if ( ! $snippet || (int) $snippet->id !== $id ) {
			return new \WP_Error( 'godmode_snippet_missing', sprintf( 'No Code Snippets snippet has id %d.', $id ), array( 'status' => 404 ) );
		}
		return $snippet;
	}

	/** Summary row: everything except the code. */
	public static function summary( $snippet ): array {
		$desc = wp_strip_all_tags( (string) $snippet->desc );
		return array(
			'id'          => (int) $snippet->id,
			'name'        => (string) $snippet->name,
			'description' => mb_substr( $desc, 0, 200 ),
			'scope'       => (string) $snippet->scope,
			'language'    => (string) $snippet->type,
			'active'      => (bool) $snippet->active,
			'trashed'     => (bool) $snippet->trashed,
			'locked'      => (bool) $snippet->locked,
			'priority'    => (int) $snippet->priority,
			'tags'        => array_values( array_map( 'strval', (array) $snippet->tags ) ),
			'modified'    => (string) $snippet->modified,
			'code_bytes'  => strlen( (string) $snippet->code ),
		);
	}

	/**
	 * Normalize a tags input. Arrays reach abilities as JSON strings through
	 * some MCP layers, so accept a real array, a JSON array string, or a
	 * comma-separated string, and always return a clean list.
	 *
	 * @param mixed $raw Tags as received.
	 * @return array<string>
	 */
	public static function tags( $raw ): array {
		if ( is_string( $raw ) ) {
			$trim = trim( $raw );
			if ( '' !== $trim && '[' === $trim[0] ) {
				$decoded = json_decode( $trim, true );
				if ( is_array( $decoded ) ) {
					$raw = $decoded;
				}
			}
		}
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		$out = array();
		foreach ( (array) $raw as $tag ) {
			$tag = trim( (string) $tag );
			if ( '' !== $tag ) {
				$out[] = $tag;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Strip leading and trailing PHP tags the way Code Snippets does on save. */
	public static function strip_php_tags( string $code ): string {
		$code = (string) preg_replace( '|^\s*<\?(php)?|', '', $code );
		return (string) preg_replace( '|\?>\s*$|', '', $code );
	}

	// -----------------------------------------------------------------------
	// Section: Checks.
	// -----------------------------------------------------------------------

	/**
	 * Parse PHP without running any of it.
	 *
	 * The code is compiled inside an if ( false ) block, which makes every
	 * function and class declaration in it conditional, so nothing is
	 * declared and nothing executes. A ParseError is caught and returned.
	 * Code that opens with a namespace or use statement cannot be wrapped
	 * that way, so the check reports it as skipped rather than guessing.
	 *
	 * @return array{ok:bool, skipped:bool, message:?string, line:?int}
	 */
	public static function syntax_check( string $code ): array {
		if ( preg_match( '/^\s*(namespace\s|use\s|declare\s*\()/i', $code ) ) {
			return array( 'ok' => true, 'skipped' => true, 'message' => 'Syntax check skipped: code opens with namespace, use or declare.', 'line' => null );
		}
		try {
			eval( 'if ( false ) { ' . $code . "\n}" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.Found -- a syntax check only: the snippet is wrapped in if ( false ) so nothing executes; a parse error here is what the crash guard reports instead of a white screen.
		} catch ( \ParseError $e ) {
			return array( 'ok' => false, 'skipped' => false, 'message' => $e->getMessage(), 'line' => (int) $e->getLine() );
		}
		return array( 'ok' => true, 'skipped' => false, 'message' => null, 'line' => null );
	}

	/**
	 * Run Code Snippets' own pre-activation test on a copy of the snippet
	 * carrying the proposed code. This is what its editor does on save: the
	 * duplicate-declaration validator, then one execution with errors caught.
	 *
	 * @return ?string Error message with line, or null when it passed.
	 */
	public static function code_snippets_test( $snippet, string $code ): ?string {
		$probe       = clone $snippet;
		$probe->code = $code;
		\Code_Snippets\test_snippet_code( $probe );
		if ( ! empty( $probe->code_error ) ) {
			$err = (array) $probe->code_error;
			return trim( (string) ( $err[0] ?? 'Code error.' ) ) . ( isset( $err[1] ) ? ' (line ' . (int) $err[1] . ')' : '' );
		}
		return null;
	}

	/**
	 * Whether this request can safely test or re-run the snippet's code.
	 *
	 * An active PHP snippet has already run once in this request unless the
	 * snippet guard skipped it, so its functions and classes already exist
	 * and any in-request test would report them as redeclared.
	 */
	public static function isolated( $snippet ): bool {
		if ( 'php' !== $snippet->type || ! $snippet->active ) {
			return true;
		}
		return \AIGodmode\Snippets_Guard::skipped_id() === (int) $snippet->id;
	}

	public static function not_isolated_error( $snippet ): \WP_Error {
		return new \WP_Error(
			'godmode_snippet_not_isolated',
			sprintf( 'Snippet %d is active and already ran in this request, so its code cannot be tested here. Deactivate it with godmode/snippets-deactivate, make the change, then activate it again.', (int) $snippet->id ),
			array( 'status' => 409 )
		);
	}

	// -----------------------------------------------------------------------
	// Section: History. Saved before every change Godmode makes.
	// -----------------------------------------------------------------------

	private static function history_raw( int $id ): array {
		$raw = get_option( self::HISTORY_PREFIX . $id, array() );
		if ( ! is_array( $raw ) || ! isset( $raw['entries'] ) || ! is_array( $raw['entries'] ) ) {
			$raw = array( 'next' => 1, 'entries' => array() );
		}
		return $raw;
	}

	/** Save the snippet's current state as a numbered version. Returns the version. */
	public static function remember( $snippet, string $reason ): int {
		$id  = (int) $snippet->id;
		$raw = self::history_raw( $id );
		$v   = max( 1, (int) $raw['next'] );

		$raw['entries'][] = array(
			'version'  => $v,
			'saved_at' => gmdate( 'c' ),
			'reason'   => mb_substr( $reason, 0, 120 ),
			'name'     => (string) $snippet->name,
			'scope'    => (string) $snippet->scope,
			'code'     => (string) $snippet->code,
		);
		$raw['next']    = $v + 1;
		$raw['entries'] = array_slice( $raw['entries'], -self::HISTORY_KEEP );

		if ( false === get_option( self::HISTORY_PREFIX . $id, false ) ) {
			add_option( self::HISTORY_PREFIX . $id, $raw, '', false );
		} else {
			update_option( self::HISTORY_PREFIX . $id, $raw, false );
		}
		return $v;
	}

	/** Version list without code, newest first. */
	public static function versions( int $id ): array {
		$out = array();
		foreach ( array_reverse( self::history_raw( $id )['entries'] ) as $e ) {
			$out[] = array(
				'version'  => (int) $e['version'],
				'saved_at' => (string) $e['saved_at'],
				'reason'   => (string) $e['reason'],
				'bytes'    => strlen( (string) $e['code'] ),
			);
		}
		return $out;
	}

	public static function version( int $id, int $version ): ?array {
		foreach ( self::history_raw( $id )['entries'] as $e ) {
			if ( (int) $e['version'] === $version ) {
				return $e;
			}
		}
		return null;
	}

	// -----------------------------------------------------------------------
	// Section: Writing code. The one path every code change takes.
	// -----------------------------------------------------------------------

	/**
	 * Replace a snippet's code after the checks that fit its state, saving the
	 * old code as a version first.
	 *
	 * Inactive snippet: parse check only, and a parse failure is reported but
	 * does not block the save, because inactive code never runs.
	 * Active PHP snippet: parse check, then Code Snippets' own test, and any
	 * failure refuses the change with nothing written. Code Snippets' own
	 * save would instead deactivate the snippet silently.
	 *
	 * @return array|\WP_Error
	 */
	public static function write_code( $snippet, string $new_code, string $reason ) {
		if ( $snippet->locked ) {
			return new \WP_Error( 'godmode_snippet_locked', sprintf( 'Snippet %d is locked in Code Snippets. Unlock it there before changing its code.', (int) $snippet->id ), array( 'status' => 423 ) );
		}
		$is_php   = 'php' === $snippet->type;
		$new_code = $is_php ? self::strip_php_tags( $new_code ) : $new_code;
		$syntax   = $is_php ? self::syntax_check( $new_code ) : array( 'ok' => true, 'skipped' => true, 'message' => null, 'line' => null );

		if ( $is_php && $snippet->active ) {
			if ( ! $syntax['ok'] ) {
				return new \WP_Error( 'godmode_snippet_syntax', sprintf( 'Refused, nothing written: the new code does not parse (%s, line %d). The active snippet is unchanged.', $syntax['message'], (int) $syntax['line'] ), array( 'status' => 422 ) );
			}
			if ( ! self::isolated( $snippet ) ) {
				return self::not_isolated_error( $snippet );
			}
			$test = self::code_snippets_test( $snippet, $new_code );
			if ( null !== $test ) {
				return new \WP_Error( 'godmode_snippet_test', 'Refused, nothing written: Code Snippets rejected the new code: ' . $test . '. The active snippet is unchanged.', array( 'status' => 422 ) );
			}
		}

		$saved_version = self::remember( $snippet, $reason );
		\Code_Snippets\update_snippet_fields( (int) $snippet->id, array( 'code' => $new_code, 'modified' => gmdate( 'Y-m-d H:i:s' ) ), false );
		$after = \Code_Snippets\get_snippet( (int) $snippet->id, false );

		if ( (string) $after->code !== $new_code ) {
			return new \WP_Error( 'godmode_snippet_unwritten', sprintf( 'The code for snippet %d did not read back as written. Version %d holds the previous code.', (int) $snippet->id, $saved_version ), array( 'status' => 500 ) );
		}
		return array(
			'snippet'        => self::summary( $after ),
			'saved_version'  => $saved_version,
			'syntax'         => $syntax,
		);
	}
}
