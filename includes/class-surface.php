<?php
/**
 * The compact tool surface, or "tool drawer".
 *
 * Every tool definition an MCP client holds is sent back to the model with
 * every request. On a site running Easy MCP AI plus this plugin that was 243
 * tools and about 70,000 tokens per request, measured on 2026-10-07. Most of
 * those tools are used once a month. So the connector now lists a small core
 * set, and the rest sit in a drawer: still registered, still switched and
 * audited exactly as before, reachable through godmode/find (what is in the
 * drawer, with schemas) and godmode/call (run one by name), and for Easy MCP
 * AI's own tools through godmode/wp-find and godmode/wp-call.
 *
 * How hiding works. Easy MCP AI serves its MCP endpoint as a normal WordPress
 * REST route, so the tools/list reply passes through core's rest_post_dispatch
 * filter. This class removes drawer tools from that reply and nothing else:
 * a tools/call for a drawer tool still runs through Easy MCP's complete
 * pipeline (token allowlist, per-tool switches, approvals, its audit and undo
 * history), because that pipeline never consults the listing. Nothing in Easy
 * MCP is patched. The core abilities REST listing is pruned the same way for
 * this plugin's own abilities.
 *
 * The drawer index. A model that cannot see a tool will not ask for it. So
 * the listing-time filter also appends to the find tools' descriptions the
 * names of everything currently in the drawer, grouped by family. Names are
 * self-describing and cost about three tokens each, which is the cheapest
 * form of "here is what else you can ask for" that still works.
 *
 * The switch ships on. Compact mode removes exposure; it grants no power, and
 * every drawer tool keeps its own switch. The find and call abilities are new
 * power and ship off like everything else.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Surface {

	public const SETTING_COMPACT = 'compact_surface';
	public const SETTING_ALWAYS  = 'surface_always';
	public const SETTING_HIDDEN  = 'surface_hidden';
	public const SETTING_FOREIGN = 'surface_foreign';

	/** Easy MCP AI's REST namespace and the prefix it gives ability tools. */
	public const EASY_MCP_ROUTE   = '/easy-mcp-ai/';
	public const ABILITY_PREFIX   = 'wp_ability_';
	public const GODMODE_PREFIX   = 'wp_ability_godmode_';
	public const CORE_ABILITIES   = '/wp-abilities/v1/abilities';

	private Settings $settings;
	private Registrar $registrar;

	public function __construct( Settings $settings, Registrar $registrar ) {
		$this->settings  = $settings;
		$this->registrar = $registrar;
	}

	public function hooks(): void {
		add_filter( 'rest_post_dispatch', array( $this, 'prune_rest_response' ), 20, 3 );
	}

	// -----------------------------------------------------------------------
	// Section: Settings.
	// -----------------------------------------------------------------------
	public function is_compact(): bool {
		return (bool) $this->settings->get( self::SETTING_COMPACT, true );
	}

	/**
	 * The always-loaded set as MCP tool names. Stored as a flat list; absent
	 * means the shipped defaults. Keys are tool names, values true.
	 *
	 * @return array<string,bool>
	 */
	public function always_loaded(): array {
		$stored = $this->settings->get( self::SETTING_ALWAYS, null );
		$list   = is_array( $stored ) ? $stored : self::default_always_loaded();
		$map    = array();
		foreach ( $list as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$map[ $name ] = true;
			}
		}
		// The drawer's own doors can never be put in the drawer.
		foreach ( self::door_tools() as $door ) {
			$map[ $door ] = true;
		}
		return $map;
	}

	public function set_always_loaded( array $names ): void {
		$this->settings->set( self::SETTING_ALWAYS, array_values( array_unique( array_map( 'strval', $names ) ) ) );
	}

	public function reset_always_loaded(): void {
		$this->settings->set( self::SETTING_ALWAYS, null );
		$this->settings->set( self::SETTING_HIDDEN, array() );
	}

	/**
	 * Other plugins' ability tools the operator has put in the drawer by
	 * hand. Needed because the default for those is "listed", so absence
	 * from the always list cannot mean hidden.
	 *
	 * @return array<string,bool>
	 */
	public function hidden(): array {
		$map = array();
		foreach ( (array) $this->settings->get( self::SETTING_HIDDEN, array() ) as $name ) {
			if ( is_string( $name ) && '' !== $name ) {
				$map[ $name ] = true;
			}
		}
		return $map;
	}

	public function set_hidden( array $names ): void {
		$this->settings->set( self::SETTING_HIDDEN, array_values( array_unique( array_map( 'strval', $names ) ) ) );
	}

	/** "listed" (the default) or "drawer": where other plugins' ability tools go unless ticked. */
	public function foreign_mode(): string {
		return 'drawer' === (string) $this->settings->get( self::SETTING_FOREIGN, 'listed' ) ? 'drawer' : 'listed';
	}

	public function set_foreign_mode( string $mode ): void {
		$this->settings->set( self::SETTING_FOREIGN, 'drawer' === $mode ? 'drawer' : 'listed' );
	}

	public function is_using_defaults(): bool {
		return ! is_array( $this->settings->get( self::SETTING_ALWAYS, null ) );
	}

	/** The tools that open the drawer. Always listed in compact mode. */
	public static function door_tools(): array {
		return array(
			self::GODMODE_PREFIX . 'find',
			self::GODMODE_PREFIX . 'call',
			self::GODMODE_PREFIX . 'wp_find',
			self::GODMODE_PREFIX . 'wp_call',
		);
	}

	/**
	 * The shipped always-loaded set: the handful of tools that do most of the
	 * work on a site, measured over this project's own sessions. Everything
	 * else is one find away.
	 */
	public static function default_always_loaded(): array {
		return array(
			// AI Godmode abilities.
			self::GODMODE_PREFIX . 'run_php',
			self::GODMODE_PREFIX . 'fs_read',
			self::GODMODE_PREFIX . 'fs_write',
			self::GODMODE_PREFIX . 'fs_patch',
			self::GODMODE_PREFIX . 'fs_list',
			self::GODMODE_PREFIX . 'fs_search',
			self::GODMODE_PREFIX . 'db_query_read',
			self::GODMODE_PREFIX . 'read_option',
			self::GODMODE_PREFIX . 'write_option',
			self::GODMODE_PREFIX . 'plugin_list',
			self::GODMODE_PREFIX . 'error_log_tail',
			self::GODMODE_PREFIX . 'get_reference',
			// Easy MCP AI's own content tools.
			'wp_list_posts',
			'wp_get_post_full',
			'wp_create_post',
			'wp_update_post',
			'wp_search_posts',
			'wp_list_pages',
			'wp_get_page',
			'wp_update_page',
			'wp_list_media',
			'wp_upload_media_from_url',
			'wp_replace_in_post',
			'wp_get_post_meta',
			'wp_update_post_meta',
			'wp_get_site_settings',
			'wp_history_list',
			'wp_rest_read',
		);
	}

	/**
	 * Whether a listed tool stays in the listing.
	 *
	 * This plugin's abilities and the MCP server's own tools are listed only
	 * when ticked (the shipped defaults until the operator saves a list).
	 * Other plugins' ability tools follow the foreign mode: listed unless
	 * ticked off, or in the drawer unless ticked on. Their exposure was
	 * their owner's decision, so the shipped default leaves them alone.
	 */
	public function is_loaded( string $tool_name, ?array $always = null, ?array $hidden = null ): bool {
		if ( ! $this->is_compact() ) {
			return true;
		}
		$always = $always ?? $this->always_loaded();
		if ( isset( $always[ $tool_name ] ) ) {
			return true;
		}
		if ( self::is_foreign_ability_tool( $tool_name ) ) {
			$hidden = $hidden ?? $this->hidden();
			if ( isset( $hidden[ $tool_name ] ) ) {
				return false;
			}
			return 'listed' === $this->foreign_mode();
		}
		return false;
	}

	public static function is_godmode_tool( string $tool_name ): bool {
		return 0 === strpos( $tool_name, self::GODMODE_PREFIX );
	}

	public static function is_foreign_ability_tool( string $tool_name ): bool {
		return 0 === strpos( $tool_name, self::ABILITY_PREFIX ) && ! self::is_godmode_tool( $tool_name );
	}

	/** Ability name (godmode/fs-read) to Easy MCP tool name (wp_ability_godmode_fs_read). */
	public static function tool_name_for_ability( string $ability ): string {
		$n = strtolower( $ability );
		$n = preg_replace( '/[^a-z0-9]+/', '_', $n );
		return self::ABILITY_PREFIX . trim( (string) $n, '_' );
	}

	// -----------------------------------------------------------------------
	// Section: The listing filter. Runs on every REST response; does nothing
	// unless the route is Easy MCP's MCP endpoint or core's abilities list.
	// -----------------------------------------------------------------------
	public function prune_rest_response( $result, $server, $request ) {
		if ( ! $this->is_compact() || ! is_object( $result ) || ! method_exists( $result, 'get_data' ) ) {
			return $result;
		}
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
		// Any MCP server plugin that answers through the REST layer sends the
		// same JSON-RPC tools/list shape (Easy MCP AI, the WordPress MCP
		// Adapter, and whatever comes next), so the shape is the trigger, not
		// the route. Nothing else on a WordPress site answers in that shape.
		$data = $result->get_data();
		if ( self::looks_like_jsonrpc( $data ) ) {
			$new = $this->prune_mcp_payload( $data );
			if ( $new !== $data ) {
				$result->set_data( $new );
			}
			return $result;
		}
		if ( 0 === strpos( $route, self::CORE_ABILITIES ) ) {
			$data = $result->get_data();
			$new  = $this->prune_core_abilities( $data );
			if ( $new !== $data ) {
				$result->set_data( $new );
			}
		}
		return $result;
	}

	/** A JSON-RPC 2.0 reply, or a batch of them. */
	public static function looks_like_jsonrpc( $data ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}
		if ( isset( $data['jsonrpc'] ) && '2.0' === (string) $data['jsonrpc'] ) {
			return true;
		}
		if ( ! empty( $data ) && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			foreach ( $data as $reply ) {
				if ( is_array( $reply ) && isset( $reply['jsonrpc'] ) && '2.0' === (string) $reply['jsonrpc'] ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Prune a JSON-RPC payload, or a batch of them. Only a tools/list reply
	 * has result.tools; everything else is returned untouched.
	 *
	 * @param mixed $data Decoded response body.
	 * @return mixed
	 */
	public function prune_mcp_payload( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		// Easy MCP encodes an empty result as an object, so look before indexing.
		if ( isset( $data['result'] ) && is_array( $data['result'] ) && isset( $data['result']['tools'] ) && is_array( $data['result']['tools'] ) ) {
			$data['result']['tools'] = $this->prune_tools( $data['result']['tools'] );
			return $data;
		}
		// A batch is a list of replies.
		if ( ! empty( $data ) && array_keys( $data ) === range( 0, count( $data ) - 1 ) ) {
			foreach ( $data as $i => $reply ) {
				if ( is_array( $reply ) && isset( $reply['result'] ) && is_array( $reply['result'] ) && isset( $reply['result']['tools'] ) ) {
					$data[ $i ] = $this->prune_mcp_payload( $reply );
				}
			}
		}
		return $data;
	}

	/**
	 * The actual pruning: keep always-loaded tools, drop the rest, then write
	 * the drawer index into the find tools' descriptions.
	 *
	 * @param array $tools Tool definitions as the client would receive them.
	 * @return array
	 */
	public function prune_tools( array $tools ): array {
		$always  = $this->always_loaded();
		$hid_map = $this->hidden();
		$kept    = array();
		$hidden  = array( 'godmode' => array(), 'site' => array() );
		foreach ( $tools as $tool ) {
			$name = isset( $tool['name'] ) ? (string) $tool['name'] : '';
			if ( '' === $name || $this->is_loaded( $name, $always, $hid_map ) ) {
				$kept[] = $tool;
				continue;
			}
			if ( self::is_godmode_tool( $name ) ) {
				$hidden['godmode'][] = $name;
			} else {
				$hidden['site'][] = $name;
			}
		}
		foreach ( $kept as $i => $tool ) {
			$name = (string) ( $tool['name'] ?? '' );
			if ( self::GODMODE_PREFIX . 'find' === $name ) {
				$kept[ $i ]['description'] = self::with_index( (string) ( $tool['description'] ?? '' ), self::index( $hidden['godmode'], 'godmode' ) );
			} elseif ( self::GODMODE_PREFIX . 'wp_find' === $name ) {
				$kept[ $i ]['description'] = self::with_index( (string) ( $tool['description'] ?? '' ), self::index( $hidden['site'], 'site' ) );
			}
		}
		return array_values( $kept );
	}

	/**
	 * Core's /wp-abilities/v1/abilities listing: drop this plugin's drawer
	 * abilities. Other plugins' abilities are left alone here; core's own
	 * listing is their business.
	 */
	public function prune_core_abilities( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		// A single ability (abilities/{name}) has a name key; a listing is a list.
		if ( isset( $data['name'] ) ) {
			return $data;
		}
		$always = $this->always_loaded();
		$out    = array();
		foreach ( $data as $item ) {
			$ability = is_array( $item ) && isset( $item['name'] ) ? (string) $item['name'] : '';
			if ( '' !== $ability && 0 === strpos( $ability, GODMODE_NAMESPACE . '/' ) && ! $this->is_loaded( self::tool_name_for_ability( $ability ), $always ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	// -----------------------------------------------------------------------
	// Section: The drawer index. Names grouped by family, nothing else.
	// -----------------------------------------------------------------------

	/** Family of a godmode tool name, from the registered ability when known. */
	private function godmode_family( string $tool_name ): string {
		foreach ( $this->registrar->classes() as $ability => $class ) {
			if ( self::tool_name_for_ability( $ability ) === $tool_name ) {
				return $class::family();
			}
		}
		$parts = explode( '_', substr( $tool_name, strlen( self::GODMODE_PREFIX ) ) );
		return $parts[0];
	}

	/**
	 * Group of a site tool name: wp_list_posts to "post", wp_wc_get_order to
	 * "wc", wp_update_custom_css to "theme". The first known noun in the name
	 * wins; a name with none falls back to its first word after the verb.
	 */
	public static function site_group( string $tool_name ): string {
		if ( 0 === strpos( $tool_name, self::ABILITY_PREFIX ) ) {
			$rest = substr( $tool_name, strlen( self::ABILITY_PREFIX ) );
			$p    = explode( '_', $rest );
			return 'ability:' . $p[0];
		}
		$rest  = 0 === strpos( $tool_name, 'wp_' ) ? substr( $tool_name, 3 ) : $tool_name;
		$words = explode( '_', $rest );
		// Nouns in priority order; plural and variant spellings map to one group.
		static $nouns = array(
			'wc'         => 'wc',
			'ga'         => 'ga',
			'gsc'        => 'gsc',
			'tsf'        => 'seo',
			'seo'        => 'seo',
			'acf'        => 'acf',
			'history'    => 'history',
			'audit'      => 'audit',
			'operation'  => 'approval',
			'privileged' => 'approval',
			'cron'       => 'cron',
			'rest'       => 'rest',
			'cpt'        => 'cpt',
			'revision'   => 'revision',
			'revisions'  => 'revision',
			'comment'    => 'comment',
			'comments'   => 'comment',
			'menu'       => 'menu',
			'menus'      => 'menu',
			'media'      => 'media',
			'category'   => 'term',
			'categories' => 'term',
			'tag'        => 'term',
			'tags'       => 'term',
			'term'       => 'term',
			'terms'      => 'term',
			'taxonomies' => 'term',
			'taxonomy'   => 'term',
			'user'       => 'user',
			'users'      => 'user',
			'plugin'     => 'plugin',
			'plugins'    => 'plugin',
			'theme'      => 'theme',
			'themes'     => 'theme',
			'css'        => 'theme',
			'styles'     => 'theme',
			'template'   => 'template',
			'templates'  => 'template',
			'pattern'    => 'block',
			'patterns'   => 'block',
			'block'      => 'block',
			'blocks'     => 'block',
			'widget'     => 'widget',
			'widgets'    => 'widget',
			'sidebar'    => 'widget',
			'sidebars'   => 'widget',
			'page'       => 'page',
			'pages'      => 'page',
			'post'       => 'post',
			'posts'      => 'post',
			'preview'    => 'post',
			'site'       => 'site',
			'settings'   => 'site',
			'health'     => 'diagnostics',
			'diagnostics'=> 'diagnostics',
			'error'      => 'diagnostics',
			'feedback'   => 'feedback',
			'search'     => 'search',
		);
		foreach ( $words as $w ) {
			if ( isset( $nouns[ $w ] ) ) {
				return $nouns[ $w ];
			}
		}
		$verbs = array( 'list', 'get', 'create', 'update', 'delete', 'count', 'search', 'add', 'run', 'restore', 'reorder', 'reply', 'bulk', 'approve', 'unapprove', 'spam', 'unspam', 'trash', 'untrash', 'upload', 'switch', 'activate', 'deactivate', 'replace', 'send', 'set', 'batch' );
		if ( in_array( $words[0], $verbs, true ) ) {
			array_shift( $words );
		}
		return $words[0] ?? 'misc';
	}

	/**
	 * "DRAWER (not listed; find/call reaches them): options: read_option,
	 * write_option; cron: cron_list, ..." Short prefixes are stripped so the
	 * family name is not repeated on every entry.
	 */
	public function index( array $hidden, string $kind ): string {
		if ( empty( $hidden ) ) {
			return '';
		}
		sort( $hidden );
		$groups = array();
		foreach ( $hidden as $name ) {
			$group = 'godmode' === $kind ? $this->godmode_family( $name ) : self::site_group( $name );
			$short = 'godmode' === $kind ? substr( $name, strlen( self::GODMODE_PREFIX ) ) : $name;
			$groups[ $group ][] = $short;
		}
		ksort( $groups );
		$parts = array();
		foreach ( $groups as $group => $names ) {
			$parts[] = $group . ': ' . implode( ', ', $names );
		}
		return implode( '; ', $parts );
	}

	private static function with_index( string $description, string $index ): string {
		if ( '' === $index ) {
			return $description;
		}
		return rtrim( $description ) . ' DRAWER (' . $index . ').';
	}
}
