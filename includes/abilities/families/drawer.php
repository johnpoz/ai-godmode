<?php
/**
 * The drawer family: the four abilities that reach everything the compact
 * tool surface does not list. See includes/class-surface.php for the design.
 *
 *  godmode/find     search this plugin's abilities by words; returns name,
 *                   purpose, kind, switch state and input schema. An empty
 *                   query lists the whole drawer by family.
 *  godmode/call     run any registered ability by name through the Abilities
 *                   API, so every gate that applies to a direct call applies
 *                   here: the switches, the capability check, schema
 *                   validation and the audit intent record, all under the
 *                   inner ability's own name.
 *  godmode/wp-find  the same search over Easy MCP AI's own tools.
 *  godmode/wp-call  run an Easy MCP AI tool through Easy MCP's own composite
 *                   call entry point, so its token allowlist, per-tool
 *                   switches, approvals, audit and undo history all apply.
 *
 * find and wp-find are READ. call and wp-call are WRITE: a dispatcher cannot
 * promise to be harmless, so it carries the WRITE badge, writes its own intent
 * record (naming the inner tool) to the off-site log before anything runs,
 * and is refused when that log cannot record. The inner ability then records
 * its own intent under its own name as well, so a write through call appears
 * twice in the log, once as the door and once as the deed.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Shared search helper: words in the query, all of which must appear. */
trait Drawer_Search {
	/** @return bool True when every word of $query appears in $haystack (case-insensitive). */
	private static function matches( string $query, string $haystack ): bool {
		$words = preg_split( '/[\s,]+/', strtolower( trim( $query ) ) );
		$hay   = strtolower( $haystack );
		foreach ( (array) $words as $w ) {
			$w = str_replace( array( '-', '_', '/' ), ' ', (string) $w );
			if ( '' === trim( $w ) ) {
				continue;
			}
			$h = str_replace( array( '-', '_', '/' ), ' ', $hay );
			if ( false === strpos( $h, trim( $w ) ) ) {
				return false;
			}
		}
		return true;
	}

	/** First sentence or 160 characters of a description. */
	private static function purpose( string $description ): string {
		$d = trim( $description );
		$p = strpos( $d, '. ' );
		if ( false !== $p && $p < 200 ) {
			return substr( $d, 0, $p + 1 );
		}
		return strlen( $d ) > 160 ? rtrim( substr( $d, 0, 157 ) ) . '...' : $d;
	}
}

// ---------------------------------------------------------------------------
// godmode/find
// ---------------------------------------------------------------------------
final class Find extends Base {
	use Drawer_Search;

	public const MAX_RESULTS = 12;

	public static function name(): string {
		return 'godmode/find';
	}

	public static function definition(): array {
		return self::make(
			'drawer',
			'Find Ability',
			'The listed tools are a partial set. Search every AI Godmode ability, listed or not, by words (for example "cron", "user create", "zip"). Returns name, purpose, READ or WRITE, whether its switch is on, and the input schema to pass to godmode/call. An empty query lists the whole drawer by family. Search here before saying something cannot be done.',
			self::obj(
				array(
					'query' => self::str( 200 ),
				)
			),
			self::obj(
				array(
					'matches' => self::list_of( self::map() ),
					'index'   => self::str(),
					'note'    => self::str(),
				),
				array( 'matches', 'note' )
			),
			false
		);
	}

	public function execute( $input ) {
		$plugin  = \AIGodmode\Plugin::instance();
		$query   = trim( (string) ( $input['query'] ?? '' ) );
		$surface = new \AIGodmode\Surface( $plugin->settings, $plugin->registrar );
		$always  = $surface->always_loaded();
		$matches = array();
		$index   = array();
		foreach ( $plugin->registrar->classes() as $name => $class ) {
			$def    = $class::definition();
			$family = $class::family();
			$tool   = \AIGodmode\Surface::tool_name_for_ability( $name );
			$listed = $surface->is_loaded( $tool, $always );
			$index[ $family ][] = $name . ( $listed ? ' (listed)' : '' );
			if ( '' === $query ) {
				continue;
			}
			$hay = $name . ' ' . $family . ' ' . (string) $def['label'] . ' ' . (string) $def['description'];
			if ( ! self::matches( $query, $hay ) ) {
				continue;
			}
			$matches[] = array(
				'name'         => $name,
				'family'       => $family,
				'kind'         => $class::is_mutation() ? 'WRITE' : 'READ',
				'switch'       => $plugin->settings->is_active( $name ) ? 'on' : 'off',
				'listed'       => $listed,
				'purpose'      => self::purpose( (string) $def['description'] ),
				'input_schema' => $def['input_schema'] ?? null,
			);
			if ( count( $matches ) >= self::MAX_RESULTS ) {
				break;
			}
		}
		ksort( $index );
		$lines = array();
		foreach ( $index as $family => $names ) {
			sort( $names );
			$lines[] = $family . ': ' . implode( ', ', $names );
		}
		$note = '' === $query
			? 'Every ability by family. "(listed)" ones are already tools you hold; the rest run through godmode/call with the name and an input object. A switched-off ability refuses until an administrator turns it on.'
			: ( empty( $matches ) ? 'No ability matched. Try fewer or different words, or an empty query to see the whole index.' : 'Run a match with godmode/call: name plus an input object matching input_schema. Listed ones can also be called directly.' );
		$out = array( 'matches' => $matches, 'note' => $note );
		if ( '' === $query || empty( $matches ) ) {
			$out['index'] = implode( '; ', $lines );
		}
		return $out;
	}
}

// ---------------------------------------------------------------------------
// godmode/call
// ---------------------------------------------------------------------------
final class Call extends Base {
	public static function name(): string {
		return 'godmode/call';
	}

	public static function definition(): array {
		$def = self::make(
			'drawer',
			'Call Ability',
			'Run any registered WordPress ability by name, for example "godmode/cron-list" with input {}. Use godmode/find first to get the name and input schema. The ability runs with all of its own gates: switches, capability check, validation and audit log under its own name. Not for abilities already listed as tools; call those directly.',
			self::obj(
				array(
					'name'  => self::str( 120, 3 ),
					'input' => self::map(),
				) + self::ack(),
				array( 'name' )
			),
			self::obj(
				array(
					'name'   => self::str(),
					'result' => self::any(),
				),
				array( 'name', 'result' )
			),
			true,
			false
		);
		return $def;
	}

	public function execute( $input ) {
		$name = trim( (string) ( $input['name'] ?? '' ) );
		$args = isset( $input['input'] ) && is_array( $input['input'] ) ? $input['input'] : array();
		// The acknowledgement is for this door, not the deed behind it; the
		// inner ability decides for itself whether it can run unlogged.
		if ( ! empty( $input['acknowledge_unlogged'] ) && is_array( $args ) && ! array_key_exists( 'acknowledge_unlogged', $args ) ) {
			$args['acknowledge_unlogged'] = true;
		}

		if ( self::name() === $name || 'godmode/wp-call' === $name ) {
			return self::err( 'godmode_call_recursion', 'godmode/call does not call itself or godmode/wp-call. Name the ability you want to run.', 400 );
		}
		if ( ! function_exists( 'wp_get_ability' ) ) {
			return self::err( 'godmode_no_abilities_api', 'The WordPress Abilities API is not available on this site.', 500 );
		}
		$ability = wp_get_ability( $name );
		if ( ! $ability ) {
			$close = self::close_names( $name );
			return self::err(
				'godmode_unknown_ability',
				sprintf( 'No ability named "%s" is registered.%s', $name, $close ? ' Did you mean: ' . implode( ', ', $close ) . '?' : ' Use godmode/find to search.' ),
				404
			);
		}
		// Core substitutes the input schema default when nothing is passed, so
		// an ability with no required input accepts null. Pass null for an
		// empty object to keep that behaviour.
		$exec_input = empty( $args ) ? null : $args;
		$result     = $ability->execute( $exec_input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array( 'name' => $name, 'result' => $result );
	}

	/** Up to five registered names sharing words with the one asked for. */
	private static function close_names( string $wanted ): array {
		if ( ! function_exists( 'wp_get_abilities' ) ) {
			return array();
		}
		$leaf  = strtolower( (string) preg_replace( '#^[^/]*/#', '', $wanted ) );
		$words = array_filter( preg_split( '/[^a-z0-9]+/', $leaf ) );
		$out   = array();
		foreach ( array_keys( (array) wp_get_abilities() ) as $name ) {
			foreach ( $words as $w ) {
				if ( strlen( $w ) >= 3 && false !== strpos( strtolower( $name ), $w ) ) {
					$out[] = $name;
					break;
				}
			}
			if ( count( $out ) >= 5 ) {
				break;
			}
		}
		return $out;
	}
}

// ---------------------------------------------------------------------------
// Easy MCP AI bridge: shared availability and registry access.
// ---------------------------------------------------------------------------
trait Easy_Mcp_Bridge {
	/** Only offered when Easy MCP AI is active with the entry points this needs. */
	public static function is_available(): bool {
		return class_exists( '\\Easy_MCP_AI\\Plugin' )
			&& method_exists( '\\Easy_MCP_AI\\Plugin', 'instance' )
			&& method_exists( '\\Easy_MCP_AI\\Plugin', 'get_tool_registry' )
			&& class_exists( '\\Easy_MCP_AI\\MCP\\Server' )
			&& method_exists( '\\Easy_MCP_AI\\MCP\\Server', 'call_as_current_caller' );
	}

	/**
	 * Easy MCP's tool definitions as its own listing would send them, minus
	 * tools the current context could not use anyway.
	 *
	 * @return array<int,array>
	 */
	private static function site_tool_definitions(): array {
		try {
			$registry = \Easy_MCP_AI\Plugin::instance()->get_tool_registry();
			if ( ! is_object( $registry ) || ! method_exists( $registry, 'get_all_definitions' ) ) {
				return array();
			}
			$defs = (array) $registry->get_all_definitions();
			if ( method_exists( '\\Easy_MCP_AI\\MCP\\Server', 'available_tools' ) ) {
				$defs = (array) \Easy_MCP_AI\MCP\Server::available_tools( $registry, false, $defs );
			}
			return array_values( $defs );
		} catch ( \Throwable $e ) {
			return array();
		}
	}
}

// ---------------------------------------------------------------------------
// godmode/wp-find
// ---------------------------------------------------------------------------
final class Wp_Find extends Base {
	use Drawer_Search;
	use Easy_Mcp_Bridge;

	public const MAX_RESULTS = 10;

	public static function name(): string {
		return 'godmode/wp-find';
	}

	public static function definition(): array {
		return self::make(
			'drawer',
			'Find Site Tool',
			'The listed site tools are a partial set. Search every Easy MCP AI tool on this site, listed or not, by words (for example "menu item", "comment approve", "woocommerce order", "theme mod"). Returns name, purpose and the input schema to pass to godmode/wp-call. An empty query lists the whole drawer by group. Search here before saying something cannot be done.',
			self::obj(
				array(
					'query' => self::str( 200 ),
				)
			),
			self::obj(
				array(
					'matches' => self::list_of( self::map() ),
					'index'   => self::str(),
					'note'    => self::str(),
				),
				array( 'matches', 'note' )
			),
			false
		);
	}

	public function execute( $input ) {
		$query = trim( (string) ( $input['query'] ?? '' ) );
		$defs  = self::site_tool_definitions();
		if ( empty( $defs ) ) {
			return self::err( 'godmode_easy_mcp_unavailable', 'Easy MCP AI did not return a tool list. It may be paused or inactive.', 503 );
		}
		$plugin  = \AIGodmode\Plugin::instance();
		$surface = new \AIGodmode\Surface( $plugin->settings, $plugin->registrar );
		$always  = $surface->always_loaded();
		$matches = array();
		$names   = array();
		foreach ( $defs as $def ) {
			$name = (string) ( $def['name'] ?? '' );
			if ( '' === $name || \AIGodmode\Surface::is_godmode_tool( $name ) ) {
				continue; // This plugin's own abilities belong to godmode/find.
			}
			$listed  = $surface->is_loaded( $name, $always );
			$names[] = $name;
			if ( '' === $query ) {
				continue;
			}
			$desc = (string) ( $def['description'] ?? '' );
			if ( ! self::matches( $query, $name . ' ' . $desc ) ) {
				continue;
			}
			$matches[] = array(
				'name'         => $name,
				'listed'       => $listed,
				'purpose'      => self::purpose( $desc ),
				'input_schema' => $def['inputSchema'] ?? null,
			);
			if ( count( $matches ) >= self::MAX_RESULTS ) {
				break;
			}
		}
		$note = '' === $query
			? 'Every site tool. Run an unlisted one with godmode/wp-call: name plus an arguments object.'
			: ( empty( $matches ) ? 'No site tool matched. Try fewer or different words, or an empty query for the whole index.' : 'Run a match with godmode/wp-call: name plus an arguments object matching input_schema. Listed ones can also be called directly.' );
		$out = array( 'matches' => $matches, 'note' => $note );
		if ( '' === $query || empty( $matches ) ) {
			$out['index'] = $surface->index( $names, 'site' );
		}
		return $out;
	}
}

// ---------------------------------------------------------------------------
// godmode/wp-call
// ---------------------------------------------------------------------------
final class Wp_Call extends Base {
	use Easy_Mcp_Bridge;

	public static function name(): string {
		return 'godmode/wp-call';
	}

	public static function definition(): array {
		$def = self::make(
			'drawer',
			'Call Site Tool',
			'Run any Easy MCP AI tool on this site by name, for example "wp_update_menu_item" with its arguments. Use godmode/wp-find first for the name and schema. The tool runs through Easy MCP AI exactly as a direct call would: its token allowlist, per-tool switches, approvals, audit and undo history all apply. Not for tools already listed; call those directly.',
			self::obj(
				array(
					'name'      => self::str( 64, 3 ),
					'arguments' => self::map(),
				) + self::ack(),
				array( 'name' )
			),
			self::obj(
				array(
					'name'   => self::str(),
					'result' => self::any(),
				),
				array( 'name', 'result' )
			),
			true,
			false
		);
		return $def;
	}

	public function execute( $input ) {
		$name = trim( (string) ( $input['name'] ?? '' ) );
		$args = isset( $input['arguments'] ) && is_array( $input['arguments'] ) ? $input['arguments'] : array();

		if ( \AIGodmode\Surface::is_godmode_tool( $name ) ) {
			return self::err( 'godmode_wrong_door', 'That is an AI Godmode ability. Use godmode/call with the ability name (for example godmode/cron-list).', 400 );
		}
		try {
			$answer = \Easy_MCP_AI\MCP\Server::call_as_current_caller( $name, $args );
		} catch ( \Throwable $e ) {
			return self::err( 'godmode_easy_mcp_failed', 'Easy MCP AI refused the call: ' . $e->getMessage(), 500 );
		}
		if ( null === $answer ) {
			return self::err( 'godmode_no_easy_mcp_context', 'godmode/wp-call only works when it is itself called through an Easy MCP AI connector; there is no Easy MCP call to run inside.', 409 );
		}
		$response = is_array( $answer ) && isset( $answer['response'] ) ? $answer['response'] : $answer;
		if ( is_array( $response ) && isset( $response['error'] ) ) {
			$msg = is_array( $response['error'] ) ? (string) ( $response['error']['message'] ?? 'unknown error' ) : (string) $response['error'];
			return self::err( 'godmode_easy_mcp_error', sprintf( 'Easy MCP AI returned an error for "%s": %s', $name, $msg ), 400 );
		}
		$result = is_array( $response ) && isset( $response['result'] ) ? $response['result'] : $response;
		if ( is_array( $result ) && ! empty( $result['isError'] ) ) {
			return self::err( 'godmode_site_tool_error', sprintf( 'Tool "%s" reported an error: %s', $name, self::text_of( $result ) ), 400 );
		}
		if ( is_array( $result ) && array_key_exists( 'structuredContent', $result ) ) {
			return array( 'name' => $name, 'result' => $result['structuredContent'] );
		}
		return array( 'name' => $name, 'result' => is_array( $result ) ? self::text_of( $result ) : $result );
	}

	/** Join the text parts of an MCP tool result. */
	private static function text_of( array $result ): string {
		$parts = array();
		foreach ( (array) ( $result['content'] ?? array() ) as $c ) {
			if ( is_array( $c ) && isset( $c['text'] ) ) {
				$parts[] = (string) $c['text'];
			}
		}
		return implode( "\n", $parts );
	}
}
