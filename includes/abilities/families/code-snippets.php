<?php
/**
 * Code Snippets family. Manage the snippets the site runs through the Code
 * Snippets plugin, using that plugin's own functions.
 *
 * Division of labor: Godmode is what Claude uses, the snippet plugin is what
 * the site uses. These abilities do not move site logic out of Code Snippets;
 * they give an agent a safe, complete and audited way to manage it there.
 *
 * Present only when Code Snippets is active and every function this family
 * calls exists (Snippets_Bridge::status()). Every ability ships off.
 *
 * Safety properties, each tested:
 *  - New snippets are always created inactive.
 *  - The list is complete: every snippet, trashed ones marked, never a subset.
 *  - Code on an active snippet changes only by exact search and replace, and
 *    only after it parses and passes Code Snippets' own test. A failed check
 *    writes nothing; Code Snippets' own save would deactivate silently instead.
 *  - The previous code is saved as a numbered version before every change,
 *    so any change can be reverted.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Section: Shared base. Availability and the common row schema.
// ---------------------------------------------------------------------------
abstract class Snippets_Base extends Base {

	public const FAMILY = 'code-snippets';

	/** Registered only when Code Snippets is active and compatible. */
	public static function is_available(): bool {
		return Snippets_Bridge::available();
	}

	protected static function id_in(): array {
		return array( 'id' => self::int( 1 ) );
	}

	protected static function row(): array {
		return self::obj(
			array(
				'id'          => array( 'type' => 'integer' ),
				'name'        => self::str(),
				'description' => self::str(),
				'scope'       => self::str(),
				'language'    => self::str(),
				'active'      => self::bool(),
				'trashed'     => self::bool(),
				'locked'      => self::bool(),
				'priority'    => array( 'type' => 'integer' ),
				'tags'        => self::list_of( self::str() ),
				'modified'    => self::str(),
				'code_bytes'  => array( 'type' => 'integer' ),
			),
			array(),
			false
		);
	}

	protected static function syntax_out(): array {
		return self::obj(
			array(
				'ok'      => self::bool(),
				'skipped' => self::bool(),
				'message' => self::nullable( self::str() ),
				'line'    => self::nullable( array( 'type' => 'integer' ) ),
			),
			array(),
			false
		);
	}

	/** Load by the validated input id. */
	protected static function load_input( $input ) {
		return Snippets_Bridge::load( (int) ( is_array( $input ) ? ( $input['id'] ?? 0 ) : 0 ) );
	}
}

// ---------------------------------------------------------------------------
// Section: Read abilities.
// ---------------------------------------------------------------------------

final class Snippets_List extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-list';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'List Code Snippets',
			'Every Code Snippets snippet with scope, language (php, html, css, js), on/off, trashed, tags and size, no code. Complete by design: trashed rows are included and marked unless status filters them. Filter by status, a search string (name, description, code, tags) or a tag.',
			self::obj(
				array(
					'status' => array( 'type' => 'string', 'enum' => array( 'all', 'active', 'inactive', 'trashed' ), 'default' => 'all' ),
					'search' => self::str( 200 ),
					'tag'    => self::str( 100 ),
				)
			),
			self::obj(
				array(
					'total'    => array( 'type' => 'integer' ),
					'counts'   => self::obj( array( 'active' => array( 'type' => 'integer' ), 'inactive' => array( 'type' => 'integer' ), 'trashed' => array( 'type' => 'integer' ) ), array(), false ),
					'snippets' => self::list_of( self::row() ),
				),
				array( 'total', 'counts', 'snippets' )
			),
			false
		);
	}

	public function execute( $input ) {
		$status = (string) ( $input['status'] ?? 'all' );
		$search = mb_strtolower( trim( (string) ( $input['search'] ?? '' ) ) );
		$tag    = mb_strtolower( trim( (string) ( $input['tag'] ?? '' ) ) );

		$all    = \Code_Snippets\get_snippets( array(), false );
		$counts = array( 'active' => 0, 'inactive' => 0, 'trashed' => 0 );
		$rows   = array();
		foreach ( $all as $snippet ) {
			$state = $snippet->trashed ? 'trashed' : ( $snippet->active ? 'active' : 'inactive' );
			++$counts[ $state ];
			if ( 'all' !== $status && $status !== $state ) {
				continue;
			}
			$tags = array_map( 'mb_strtolower', array_map( 'strval', (array) $snippet->tags ) );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				continue;
			}
			if ( '' !== $search ) {
				$hay = mb_strtolower( $snippet->name . "\n" . $snippet->desc . "\n" . $snippet->code . "\n" . implode( ' ', $tags ) );
				if ( false === strpos( $hay, $search ) ) {
					continue;
				}
			}
			$rows[] = Snippets_Bridge::summary( $snippet );
		}
		usort( $rows, static fn( $a, $b ) => $a['id'] <=> $b['id'] );
		return array( 'total' => count( $rows ), 'counts' => $counts, 'snippets' => $rows );
	}
}

final class Snippets_Get extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-get';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Get Code Snippet',
			'One snippet in full: its code, full description, and the versions Godmode has saved before changing it (newest first; pass one to godmode/snippets-revert).',
			self::obj( self::id_in(), array( 'id' ) ),
			self::obj(
				array(
					'snippet'     => self::row(),
					'description' => self::str(),
					'code'        => self::str(),
					'versions'    => self::list_of( self::obj( array( 'version' => array( 'type' => 'integer' ), 'saved_at' => self::str(), 'reason' => self::str(), 'bytes' => array( 'type' => 'integer' ) ), array(), false ) ),
				),
				array( 'snippet', 'code', 'versions' )
			),
			false
		);
	}

	public function execute( $input ) {
		$snippet = self::load_input( $input );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		return array(
			'snippet'     => Snippets_Bridge::summary( $snippet ),
			'description' => (string) $snippet->desc,
			'code'        => (string) $snippet->code,
			'versions'    => Snippets_Bridge::versions( (int) $snippet->id ),
		);
	}
}

// ---------------------------------------------------------------------------
// Section: Write abilities.
// ---------------------------------------------------------------------------

final class Snippets_Create extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-create';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Create Code Snippet',
			'Create a snippet. Always saved inactive; switch it on with godmode/snippets-activate. Scope sets the type: global, admin, front-end, single-use are PHP; *-content is HTML; *-css CSS; *-js JavaScript. Tags: comma list. PHP is parse-checked and the result reported.',
			self::obj(
				array(
					'name'        => self::str( 200, 1 ),
					'code'        => self::str( 200000, 1 ),
					'scope'       => array( 'type' => 'string', 'enum' => Snippets_Bridge::SCOPES, 'default' => 'global' ),
					'description' => self::str( 5000 ),
					'tags'        => self::str( 1000 ),
					'priority'    => self::int( 0, 0, 10 ),
				) + self::ack(),
				array( 'name', 'code' )
			),
			self::obj( array( 'snippet' => self::row(), 'syntax' => self::syntax_out() ), array( 'snippet' ) ),
			true,
			false
		);
	}

	public function execute( $input ) {
		$scope = (string) ( $input['scope'] ?? 'global' );
		$code  = (string) $input['code'];
		$is_php = 'php' === \Code_Snippets\Model\Snippet::get_type_from_scope( $scope );
		if ( $is_php ) {
			$code = Snippets_Bridge::strip_php_tags( $code );
		}

		$snippet           = new \Code_Snippets\Model\Snippet();
		$snippet->name     = (string) $input['name'];
		$snippet->desc     = (string) ( $input['description'] ?? '' );
		$snippet->code     = $code;
		$snippet->scope    = $scope;
		$snippet->tags     = Snippets_Bridge::tags( $input['tags'] ?? '' );
		$snippet->priority = (int) ( $input['priority'] ?? 10 );
		$snippet->active   = false;
		$snippet->network  = false;

		$saved = \Code_Snippets\save_snippet( $snippet );
		if ( ! $saved || ! $saved->id ) {
			return self::err( 'godmode_snippet_create_failed', 'Code Snippets did not save the snippet. Nothing was created.', 500 );
		}
		return array(
			'snippet' => Snippets_Bridge::summary( $saved ),
			'syntax'  => $is_php ? Snippets_Bridge::syntax_check( $code ) : array( 'ok' => true, 'skipped' => true, 'message' => null, 'line' => null ),
		);
	}
}

final class Snippets_Update extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-update';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Update Code Snippet',
			'Change name, description, tags, priority, scope or whole code. Code and scope change only while the snippet is inactive; for an active snippet use godmode/snippets-replace. The old code is saved as a version first.',
			self::obj(
				array(
					'name'        => self::str( 200, 1 ),
					'description' => self::str( 5000 ),
					'tags'        => self::str( 1000 ),
					'priority'    => self::int( 0 ),
					'scope'       => array( 'type' => 'string', 'enum' => Snippets_Bridge::SCOPES ),
					'code'        => self::str( 200000, 1 ),
				) + self::id_in() + self::ack(),
				array( 'id' )
			),
			self::obj( array( 'snippet' => self::row(), 'changed' => self::list_of( self::str() ), 'saved_version' => self::nullable( array( 'type' => 'integer' ) ) ), array( 'snippet', 'changed' ) ),
			true
		);
	}

	public function execute( $input ) {
		$snippet = self::load_input( $input );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		$id = (int) $snippet->id;

		if ( $snippet->active && ( isset( $input['code'] ) || isset( $input['scope'] ) ) ) {
			return self::err( 'godmode_snippet_active', sprintf( 'Snippet %d is active. Change its code with godmode/snippets-replace, or deactivate it first to replace the whole code or change its scope. Nothing was changed.', $id ), 409 );
		}
		if ( $snippet->locked && ( isset( $input['code'] ) || isset( $input['name'] ) ) ) {
			return self::err( 'godmode_snippet_locked', sprintf( 'Snippet %d is locked in Code Snippets, so its name and code cannot change. Nothing was changed.', $id ), 423 );
		}

		$changed = array();
		$version = null;

		// Code first: it is the change that can fail, and it saves a version.
		if ( isset( $input['code'] ) ) {
			$result = Snippets_Bridge::write_code( $snippet, (string) $input['code'], 'before godmode/snippets-update' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$version   = $result['saved_version'];
			$changed[] = 'code';
		}

		$fields = array();
		foreach ( array( 'name' => 'name', 'description' => 'description', 'priority' => 'priority', 'scope' => 'scope' ) as $in => $field ) {
			if ( isset( $input[ $in ] ) ) {
				$fields[ $field ] = $input[ $in ];
				$changed[]        = $in;
			}
		}
		if ( $fields ) {
			$fields['modified'] = gmdate( 'Y-m-d H:i:s' );
			\Code_Snippets\update_snippet_fields( $id, $fields, false );
		}

		// Tags are stored as a comma list. update_snippet_fields would hand
		// the parsed array straight to the database, so tags take the same
		// route Code Snippets' own save uses: its table, then its cache clear.
		if ( isset( $input['tags'] ) ) {
			global $wpdb;
			$table = \Code_Snippets\code_snippets()->db->get_table_name( false );
			$wpdb->update( $table, array( 'tags' => implode( ', ', Snippets_Bridge::tags( $input['tags'] ) ) ), array( 'id' => $id ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			\Code_Snippets\clean_snippets_cache( $table );
			$changed[] = 'tags';
		}

		$after = Snippets_Bridge::load( $id );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		return array( 'snippet' => Snippets_Bridge::summary( $after ), 'changed' => $changed, 'saved_version' => $version );
	}
}

final class Snippets_Replace extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-replace';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Search And Replace In A Snippet',
			'Exact text replacement inside one snippet\'s code. Refused, nothing written, unless search occurs exactly count times (default 1). On an active PHP snippet the new code must parse and pass Code Snippets\' own test first. Old code saved as a version.',
			self::obj(
				array(
					'search'  => self::str( 200000, 1 ),
					'replace' => self::str( 200000 ),
					'count'   => self::int( 1, 0, 1 ),
				) + self::id_in() + self::ack(),
				array( 'id', 'search', 'replace' )
			),
			self::obj( array( 'snippet' => self::row(), 'replaced' => array( 'type' => 'integer' ), 'saved_version' => array( 'type' => 'integer' ), 'syntax' => self::syntax_out() ), array( 'snippet', 'replaced', 'saved_version' ) ),
			true,
			false
		);
	}

	public function execute( $input ) {
		$snippet = self::load_input( $input );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		$search   = (string) $input['search'];
		$expected = (int) ( $input['count'] ?? 1 );
		$found    = substr_count( (string) $snippet->code, $search );
		if ( $found !== $expected ) {
			return self::err( 'godmode_snippet_match', sprintf( 'Refused, nothing written: the search text occurs %d time(s) in snippet %d, expected %d. Widen or correct it.', $found, (int) $snippet->id, $expected ), 409 );
		}
		$new    = str_replace( $search, (string) $input['replace'], (string) $snippet->code );
		$result = Snippets_Bridge::write_code( $snippet, $new, 'before godmode/snippets-replace' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'snippet' => $result['snippet'], 'replaced' => $found, 'saved_version' => $result['saved_version'], 'syntax' => $result['syntax'] );
	}
}

final class Snippets_Activate extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-activate';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Activate Code Snippet',
			'Switch a snippet on. PHP must parse and pass Code Snippets\' own test first, which runs the code once in this request with errors caught; any failure leaves it off and returns the error and line.',
			self::obj( self::id_in() + self::ack(), array( 'id' ) ),
			self::obj( array( 'snippet' => self::row(), 'tested' => self::bool(), 'already' => self::bool() ), array( 'snippet', 'tested', 'already' ) ),
			true
		);
	}

	public function execute( $input ) {
		$snippet = self::load_input( $input );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		$id = (int) $snippet->id;
		if ( $snippet->trashed ) {
			return self::err( 'godmode_snippet_trashed', sprintf( 'Snippet %d is in the trash. Restore it with godmode/snippets-trash and restore true first.', $id ), 409 );
		}
		if ( $snippet->active ) {
			return array( 'snippet' => Snippets_Bridge::summary( $snippet ), 'tested' => false, 'already' => true );
		}
		$tested = false;
		if ( 'php' === $snippet->type ) {
			$syntax = Snippets_Bridge::syntax_check( (string) $snippet->code );
			if ( ! $syntax['ok'] ) {
				return self::err( 'godmode_snippet_syntax', sprintf( 'Left off: snippet %d does not parse (%s, line %d).', $id, $syntax['message'], (int) $syntax['line'] ), 422 );
			}
			// Code Snippets' own test: its duplicate-declaration validator,
			// then one run with errors caught. This runs the code, which
			// declares its functions, so Code Snippets' activate_snippet()
			// cannot be called afterwards in this request: its validator
			// would see those functions and refuse the snippet as a duplicate
			// of itself. Found on the live site, 2026-09-24. The validator
			// has already passed inside the test, so the switch is flipped
			// directly and Code Snippets' activation hook fired by hand.
			$test = Snippets_Bridge::code_snippets_test( $snippet, (string) $snippet->code );
			if ( null !== $test ) {
				return self::err( 'godmode_snippet_test', sprintf( 'Left off: Code Snippets rejected snippet %d: %s.', $id, $test ), 422 );
			}
			$tested = true;
			\Code_Snippets\update_snippet_fields( $id, array( 'active' => 1 ), false );
			do_action( 'code_snippets/activate_snippet', $snippet, false ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- the Code Snippets plugin's own hook, fired so its listeners see the activation.
		} else {
			$result = \Code_Snippets\activate_snippet( $id, false );
			if ( is_string( $result ) ) {
				return self::err( 'godmode_snippet_activate', 'Left off: ' . $result, 422 );
			}
		}
		$after = Snippets_Bridge::load( $id );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		if ( ! $after->active ) {
			return self::err( 'godmode_snippet_activate', sprintf( 'Code Snippets reported success but snippet %d reads back as inactive.', $id ), 500 );
		}
		return array( 'snippet' => Snippets_Bridge::summary( $after ), 'tested' => $tested, 'already' => false );
	}
}

final class Snippets_Deactivate extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-deactivate';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Deactivate Code Snippet',
			'Switch a snippet off. Godmode skips running that snippet in the request that asks, so this also works on a snippet that crashes every page.',
			self::obj( self::id_in() + self::ack(), array( 'id' ) ),
			self::obj( array( 'snippet' => self::row(), 'already' => self::bool(), 'isolated' => self::bool() ), array( 'snippet', 'already', 'isolated' ) ),
			true
		);
	}

	public function execute( $input ) {
		$snippet = self::load_input( $input );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		$id       = (int) $snippet->id;
		$isolated = \AIGodmode\Snippets_Guard::skipped_id() === $id;
		if ( ! $snippet->active ) {
			return array( 'snippet' => Snippets_Bridge::summary( $snippet ), 'already' => true, 'isolated' => $isolated );
		}
		\Code_Snippets\deactivate_snippet( $id, false );
		$after = Snippets_Bridge::load( $id );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		if ( $after->active ) {
			return self::err( 'godmode_snippet_deactivate', sprintf( 'Snippet %d still reads back as active.', $id ), 500 );
		}
		return array( 'snippet' => Snippets_Bridge::summary( $after ), 'already' => false, 'isolated' => $isolated );
	}
}

final class Snippets_Trash extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-trash';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Trash Or Restore Code Snippet',
			'Move a snippet to Code Snippets\' trash (which also switches it off), or restore it with restore true. Restored snippets come back inactive. Godmode never deletes permanently; that stays in the Code Snippets screen.',
			self::obj( array( 'restore' => self::bool( false ) ) + self::id_in() + self::ack(), array( 'id' ) ),
			self::obj( array( 'snippet' => self::row() ), array( 'snippet' ) ),
			true
		);
	}

	public function execute( $input ) {
		$snippet = self::load_input( $input );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		$id      = (int) $snippet->id;
		$restore = ! empty( $input['restore'] );
		if ( $restore ) {
			if ( ! $snippet->trashed ) {
				return self::err( 'godmode_snippet_not_trashed', sprintf( 'Snippet %d is not in the trash.', $id ), 409 );
			}
			\Code_Snippets\restore_snippet( $id, false );
		} else {
			if ( $snippet->trashed ) {
				return self::err( 'godmode_snippet_trashed', sprintf( 'Snippet %d is already in the trash.', $id ), 409 );
			}
			if ( ! \Code_Snippets\trash_snippet( $id, false ) ) {
				return self::err( 'godmode_snippet_locked', sprintf( 'Code Snippets refused to trash snippet %d, which means it is locked.', $id ), 423 );
			}
		}
		$after = Snippets_Bridge::load( $id );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		if ( $after->trashed === $restore ) {
			return self::err( 'godmode_snippet_trash', sprintf( 'Snippet %d did not change state.', $id ), 500 );
		}
		return array( 'snippet' => Snippets_Bridge::summary( $after ) );
	}
}

final class Snippets_Run extends Snippets_Base {
	public const MAX_OUTPUT = 100000;

	public static function name(): string {
		return 'godmode/snippets-run';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Run Code Snippet Once',
			'Run one PHP snippet\'s code once in this request without switching it on. Returns what it returned and echoed; errors are caught. Refused if it would redeclare something already loaded.',
			self::obj( self::id_in() + self::ack(), array( 'id' ) ),
			self::obj(
				array(
					'ok'       => self::bool(),
					'returned' => self::any(),
					'output'   => self::str(),
					'error'    => self::nullable( self::str() ),
					'seconds'  => array( 'type' => 'number' ),
				),
				array( 'ok', 'output', 'seconds' )
			),
			true,
			false
		);
	}

	public function execute( $input ) {
		$snippet = self::load_input( $input );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		if ( 'php' !== $snippet->type ) {
			return self::err( 'godmode_snippet_not_php', sprintf( 'Snippet %d is %s, not PHP. Only PHP snippets can be run.', (int) $snippet->id, $snippet->type ), 400 );
		}
		$code   = (string) $snippet->code;
		$syntax = Snippets_Bridge::syntax_check( $code );
		if ( ! $syntax['ok'] ) {
			return self::err( 'godmode_snippet_syntax', sprintf( 'Not run: snippet %d does not parse (%s, line %d).', (int) $snippet->id, $syntax['message'], (int) $syntax['line'] ), 422 );
		}
		$dupe = ( new \Code_Snippets\Utils\Validator( $code ) )->validate();
		if ( $dupe ) {
			return self::err( 'godmode_snippet_redeclare', sprintf( 'Not run: %s (line %d). Running it would stop this request.', (string) ( $dupe['message'] ?? 'duplicate declaration' ), (int) ( $dupe['line'] ?? 0 ) ), 409 );
		}

		$runner = static function () use ( $code ) {
			return eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.Found -- runs a Code Snippets snippet the administrator chose, the way that plugin runs it, behind the master switch, the ability switch, manage_options and the audit log.
		};
		$start = microtime( true );
		ob_start();
		try {
			$returned = $runner();
			$output   = (string) ob_get_clean();
			$error    = null;
			$ok       = true;
		} catch ( \Throwable $e ) {
			$output   = (string) ob_get_clean();
			$returned = null;
			$error    = get_class( $e ) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ')';
			$ok       = false;
		}
		if ( is_object( $returned ) ) {
			$returned = json_decode( (string) wp_json_encode( $returned ), true );
		}
		if ( strlen( $output ) > self::MAX_OUTPUT ) {
			$output = substr( $output, 0, self::MAX_OUTPUT ) . "\n...[truncated, " . strlen( $output ) . ' bytes]';
		}
		return array( 'ok' => $ok, 'returned' => $returned, 'output' => $output, 'error' => $error, 'seconds' => round( microtime( true ) - $start, 3 ) );
	}
}

final class Snippets_Revert extends Snippets_Base {
	public static function name(): string {
		return 'godmode/snippets-revert';
	}

	public static function definition(): array {
		return self::make(
			self::FAMILY,
			'Revert Code Snippet',
			'Put back the code from a saved version (see godmode/snippets-get). The current code is saved as a new version first, so a revert can itself be reverted. Same checks as replace on an active PHP snippet.',
			self::obj( array( 'version' => self::int( 1 ) ) + self::id_in() + self::ack(), array( 'id', 'version' ) ),
			self::obj( array( 'snippet' => self::row(), 'restored_version' => array( 'type' => 'integer' ), 'saved_version' => array( 'type' => 'integer' ) ), array( 'snippet', 'restored_version', 'saved_version' ) ),
			true,
			false
		);
	}

	public function execute( $input ) {
		$snippet = self::load_input( $input );
		if ( is_wp_error( $snippet ) ) {
			return $snippet;
		}
		$version = (int) $input['version'];
		$entry   = Snippets_Bridge::version( (int) $snippet->id, $version );
		if ( null === $entry ) {
			return self::err( 'godmode_snippet_version', sprintf( 'Snippet %d has no saved version %d. godmode/snippets-get lists what exists.', (int) $snippet->id, $version ), 404 );
		}
		$result = Snippets_Bridge::write_code( $snippet, (string) $entry['code'], 'before revert to version ' . $version );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'snippet' => $result['snippet'], 'restored_version' => $version, 'saved_version' => $result['saved_version'] );
	}
}
