<?php
/**
 * AI Engine bridge: lets the drawer reach AI Engine's own MCP tools.
 *
 * Under Easy MCP AI, godmode/wp-find and godmode/wp-call reach Easy MCP's own
 * tools through Easy MCP's composite call entry point. AI Engine (Meow Apps)
 * has no such public entry point, so until now its own tools always stayed
 * listed: once hidden, nothing could reach them. On the test site running AI
 * Engine Pro that kept 137 of its tools (112,013 characters) in every list.
 *
 * This class gives wp-find and wp-call an AI Engine equivalent. It works only
 * inside a tools/call that AI Engine itself is serving:
 *
 *  - AI Engine passes its server object as the fifth argument of the
 *    mwai_mcp_callback filter. capture() runs first on that filter, keeps the
 *    object, the JSON-RPC id and the tool name for this request, and changes
 *    nothing.
 *  - tools() asks that same server object for its tool list, so the list is
 *    exactly what this connection is allowed to see (AI Engine's role filter,
 *    read-only grants and editor sessions all apply).
 *  - call() runs a tool through that same server object's own execute_tool(),
 *    so every AI Engine gate applies exactly as for a direct call: access
 *    level, read-only grant, editor session limits, drafts only, approvals,
 *    the undo journal and the MCP Logs entry.
 *
 * Both methods are private on AI Engine's class, so they are reached through
 * a closure bound to the object. Every step checks that the class and method
 * still exist and returns null when they do not; the drawer then keeps AI
 * Engine's tools listed, as before. Written against AI Engine (free and Pro)
 * 3.9.3, labs/mcp.php.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ai_Engine_Bridge {

	/** AI Engine's MCP server class and the private methods this bridge uses. */
	public const SERVER_CLASS = 'Meow_MWAI_Labs_MCP';
	private const M_LIST      = 'get_tools_list';
	private const M_EXECUTE   = 'execute_tool';

	/** The AI Engine server serving the current tools/call, when there is one. */
	private static ?object $server = null;

	/** Nesting depth of call(), a backstop against a door calling itself. */
	private static int $depth = 0;

	/** JSON-RPC id and tool name of the call AI Engine is serving. */
	private static $call_id = null;
	private static string $call_tool = '';

	// -----------------------------------------------------------------------
	// Section: Hook. Runs before every other mwai_mcp_callback handler and
	// returns the value it was given, so it never answers a call itself.
	// -----------------------------------------------------------------------
	public static function hooks(): void {
		add_filter( 'mwai_mcp_callback', array( self::class, 'capture' ), 1, 5 );
	}

	/**
	 * Keep the server object for the call being served.
	 *
	 * @param mixed  $result Value from earlier handlers (null so far).
	 * @param string $tool   Tool name AI Engine is running.
	 * @param mixed  $args   Tool arguments (unused).
	 * @param mixed  $id     JSON-RPC id.
	 * @param mixed  $server AI Engine's server object, or null from AI Engine's Workspace.
	 * @return mixed $result unchanged.
	 */
	public static function capture( $result, $tool = '', $args = null, $id = null, $server = null ) {
		if ( is_object( $server ) && is_a( $server, self::SERVER_CLASS ) ) {
			self::$server    = $server;
			self::$call_id   = $id;
			self::$call_tool = (string) $tool;
		}
		return $result;
	}

	// -----------------------------------------------------------------------
	// Section: Availability.
	// -----------------------------------------------------------------------

	/** AI Engine is loaded with the methods this bridge needs. */
	public static function is_supported(): bool {
		return class_exists( self::SERVER_CLASS, false )
			&& method_exists( self::SERVER_CLASS, self::M_LIST )
			&& method_exists( self::SERVER_CLASS, self::M_EXECUTE );
	}

	/**
	 * AI Engine's "drafts only" setting. True when it is on, and also when it
	 * cannot be read, so an unreadable setting keeps everything listed.
	 */
	public static function drafts_only(): bool {
		$approvals = 'Meow_MWAI_Labs_MCP_Approvals';
		if ( ! class_exists( $approvals ) ) {
			return false; // No approvals module (autoloaded by AI Engine when present): no drafts-only mode.
		}
		if ( ! method_exists( $approvals, 'drafts_only' ) ) {
			return true;
		}
		try {
			return (bool) $approvals::drafts_only();
		} catch ( \Throwable $e ) {
			return true;
		}
	}

	/** True while AI Engine is serving a tools/call in this request. */
	public static function in_call(): bool {
		return null !== self::$server && self::is_supported();
	}

	/** Forget the captured server. For the harness. */
	public static function reset(): void {
		self::$server    = null;
		self::$call_id   = null;
		self::$call_tool = '';
	}

	// -----------------------------------------------------------------------
	// Section: Tool list and tool call, through the captured server.
	// -----------------------------------------------------------------------

	/**
	 * AI Engine's tool list for this connection, as its tools/list would send
	 * it before the drawer prunes it. Null outside an AI Engine call.
	 *
	 * @return array<int,array>|null
	 */
	public static function tools(): ?array {
		if ( ! self::in_call() ) {
			return null;
		}
		try {
			$fn    = \Closure::bind(
				function () {
					return $this->get_tools_list();
				},
				self::$server,
				self::SERVER_CLASS
			);
			$tools = $fn();
		} catch ( \Throwable $e ) {
			return null;
		}
		return is_array( $tools ) ? array_values( $tools ) : null;
	}

	/**
	 * Run one of AI Engine's tools through its own execute_tool(). Returns
	 * AI Engine's JSON-RPC reply, or null outside an AI Engine call.
	 *
	 * @param string $tool Tool name as AI Engine lists it.
	 * @param array  $args Arguments for that tool.
	 * @return array|null
	 */
	public static function call( string $tool, array $args ): ?array {
		if ( ! self::in_call() ) {
			return null;
		}
		// Never run this plugin's own tools here (godmode/call runs abilities),
		// and never nest: a door reaching itself would recurse without end.
		if ( 0 === strpos( $tool, 'godmode_' ) || self::$depth > 0 ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => self::$call_id,
				'error'   => array( 'code' => -32600, 'message' => 'godmode/wp-call does not run AI Godmode tools or call itself.' ),
			);
		}
		// The inner call fires mwai_mcp_callback again and so overwrites the
		// captured call; keep the outer one to put back afterwards.
		$server = self::$server;
		$id     = self::$call_id;
		$outer  = self::$call_tool;
		self::$depth++;
		try {
			$fn    = \Closure::bind(
				function ( string $tool, array $args, $id, string $outer ) {
					$reply = $this->execute_tool( $tool, $args, $id );
					// execute_tool() disarms AI Engine's fatal-error net when it
					// finishes; re-arm it for the outer call (wp-call) still running.
					if ( method_exists( $this, 'arm_fatal_net' ) ) {
						$this->arm_fatal_net( $outer, $id );
					}
					return $reply;
				},
				$server,
				self::SERVER_CLASS
			);
			$reply = $fn( $tool, $args, $id, $outer );
			self::drop_outer_journal( $server, $tool, $args );
		} catch ( \Throwable $e ) {
			$reply = array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array( 'code' => -32603, 'message' => $e->getMessage() ),
			);
		} finally {
			self::$depth--;
			self::$server    = $server;
			self::$call_id   = $id;
			self::$call_tool = $outer;
		}
		return is_array( $reply ) ? $reply : null;
	}

	/**
	 * AI Engine journals wp-call itself as a write (it is an ability not
	 * marked read-only). When the inner tool wrote, its own journal entry
	 * replaced wp-call's and nothing is left to drop. When the inner tool only
	 * read, wp-call's pending entry would be saved as an empty "cannot be
	 * undone" change, which mcp_undo_change "last" would then pick ahead of
	 * the real last change. Drop that pending entry so a read leaves no row.
	 *
	 * @param object $server AI Engine's server object.
	 * @param string $tool   Inner tool name.
	 * @param array  $args   Inner tool arguments.
	 */
	private static function drop_outer_journal( object $server, string $tool, array $args ): void {
		$undo = 'Meow_MWAI_Labs_MCP_Undo';
		if ( ! method_exists( $server, 'is_write_call' ) || ! class_exists( $undo ) || ! property_exists( $undo, 'pending' ) ) {
			return;
		}
		try {
			if ( $server->is_write_call( $tool, $args ) ) {
				return;
			}
			$clear = \Closure::bind(
				static function () {
					self::$pending = null;
				},
				null,
				$undo
			);
			$clear();
		} catch ( \Throwable $e ) {
			// Leave AI Engine's journal as it is.
			return;
		}
	}
}
