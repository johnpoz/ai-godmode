<?php
/**
 * Snippets guard: keep Code Snippets from running the one snippet that the
 * current Godmode request is about to change.
 *
 * Why this exists. Code Snippets runs every active PHP snippet at
 * plugins_loaded, before any ability executes. That causes two problems for
 * an ability that manages a snippet:
 *
 *  1. Testing new code for an active snippet in the same request always
 *     fails, because the old version already declared its functions and
 *     classes, so Code Snippets' own validator reports them as redeclared.
 *     Its editor avoids this by skipping the snippet on its own save route.
 *
 *  2. A snippet that crashes every request also crashes the request sent to
 *     switch it off, so the tool that would fix it can never reach the site.
 *
 * Code Snippets offers a per-snippet filter, code_snippets/allow_execute_snippet,
 * applied before each snippet runs. This guard reads the incoming request
 * while plugins are still loading and, when it is a call to one of Godmode's
 * snippet write abilities, skips exactly that one snippet for exactly that
 * one request. Nothing is stored and no other request is affected.
 *
 * The request is not authenticated yet at this point, so the guard is
 * deliberately narrow. It acts only when Godmode is armed, only when the
 * named ability is switched on, and only on the two request paths that lead
 * to a Godmode ability: the core Abilities run route for a godmode/snippets-*
 * ability, and an MCP endpoint (Easy MCP AI or the WordPress MCP Adapter)
 * whose body is a tools/call for one. Those paths refuse unauthenticated
 * callers themselves, and on no other path is anything skipped. The worst a
 * forged request can do is skip one snippet on a request that is then refused.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Snippets_Guard {

	/** Snippet abilities that take an id and may need the snippet isolated. */
	public const GUARDED = array( 'update', 'replace', 'activate', 'deactivate', 'trash', 'run', 'revert' );

	/** REST path fragments that lead to an MCP server exposing abilities. */
	public const MCP_PATHS = array( '/easy-mcp-ai/v1/mcp', '/mcp/mcp-adapter', '/mcp-adapter/' );

	/** Snippet id skipped in this request, 0 when none. */
	private static int $skipped = 0;

	/** Ability name that caused the skip, for the response. */
	private static string $for = '';

	public static function skipped_id(): int {
		return self::$skipped;
	}

	/**
	 * Called once while the plugin file loads, before plugins_loaded, so the
	 * filter is in place when Code Snippets evaluates snippets.
	 */
	public static function boot(): void {
		if ( ! get_option( 'godmode_armed', false ) ) {
			return;
		}
		$path = self::request_path();
		if ( ! self::path_may_match( $path ) ) {
			return;
		}
		$hit = self::detect( $path, self::request_body(), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( null === $hit ) {
			return;
		}
		$map = get_option( 'godmode_enabled_abilities', array() );
		if ( ! is_array( $map ) || empty( $map[ 'godmode/snippets-' . $hit['action'] ] ) ) {
			return;
		}
		self::$skipped = $hit['id'];
		self::$for     = 'godmode/snippets-' . $hit['action'];
		add_filter( 'code_snippets/allow_execute_snippet', array( self::class, 'filter' ), 1, 2 );
	}

	/** @param mixed $allow Earlier value. */
	public static function filter( $allow, $snippet_id ) {
		return ( (int) $snippet_id === self::$skipped && self::$skipped > 0 ) ? false : $allow;
	}

	// -----------------------------------------------------------------------
	// Section: Request parsing. Pure functions of their inputs so the harness
	// can drive them without a web server.
	// -----------------------------------------------------------------------

	/**
	 * Decide whether a request is a guarded snippet call.
	 *
	 * @param string $path  Request path, including any rest_route value.
	 * @param string $body  Raw request body.
	 * @param array  $query Query parameters.
	 * @return array{action:string, id:int}|null
	 */
	public static function detect( string $path, string $body, array $query ): ?array {
		// Core Abilities route: /wp-abilities/v1/abilities/godmode/snippets-<action>/run
		if ( preg_match( '#/wp-abilities/v1/abilities/godmode/snippets-([a-z]+)/run#', $path, $m ) ) {
			$input = null;
			$json  = json_decode( $body, true );
			if ( is_array( $json ) && isset( $json['input'] ) ) {
				$input = $json['input'];
			} elseif ( isset( $query['input'] ) ) {
				$input = $query['input'];
			}
			if ( is_string( $input ) ) {
				$input = json_decode( $input, true );
			}
			return self::hit( $m[1], is_array( $input ) ? ( $input['id'] ?? null ) : null );
		}

		// MCP endpoints: a JSON-RPC tools/call, possibly batched.
		$is_mcp = false;
		foreach ( self::MCP_PATHS as $fragment ) {
			if ( false !== strpos( $path, $fragment ) ) {
				$is_mcp = true;
				break;
			}
		}
		if ( ! $is_mcp || '' === $body ) {
			return null;
		}
		$json = json_decode( $body, true );
		if ( ! is_array( $json ) ) {
			return null;
		}
		$calls = isset( $json['method'] ) ? array( $json ) : $json;
		foreach ( $calls as $call ) {
			if ( ! is_array( $call ) || ( $call['method'] ?? '' ) !== 'tools/call' ) {
				continue;
			}
			$name = (string) ( $call['params']['name'] ?? '' );
			if ( ! preg_match( '#godmode[/_-]+snippets[_-]([a-z]+)$#', $name, $m ) ) {
				continue;
			}
			$args = $call['params']['arguments'] ?? array();
			if ( is_string( $args ) ) {
				$args = json_decode( $args, true );
			}
			$hit = self::hit( $m[1], is_array( $args ) ? ( $args['id'] ?? null ) : null );
			if ( null !== $hit ) {
				return $hit;
			}
		}
		return null;
	}

	/** Cheap test on the path alone, so ordinary requests never read the body. */
	public static function path_may_match( string $path ): bool {
		if ( false !== strpos( $path, '/wp-abilities/v1/abilities/godmode/snippets-' ) ) {
			return true;
		}
		foreach ( self::MCP_PATHS as $fragment ) {
			if ( false !== strpos( $path, $fragment ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param mixed $id Raw id. */
	private static function hit( string $action, $id ): ?array {
		if ( ! in_array( $action, self::GUARDED, true ) ) {
			return null;
		}
		$id = is_numeric( $id ) ? (int) $id : 0;
		return $id > 0 ? array( 'action' => $action, 'id' => $id ) : null;
	}

	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$path .= ' ' . (string) wp_unslash( $_GET['rest_route'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Recommended -- read only to name the request in a crash record; never stored or echoed.
		}
		return $path;
	}

	private static function request_body(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'POST' !== $method ) {
			return '';
		}
		$body = file_get_contents( 'php://input', false, null, 0, 262144 );
		return is_string( $body ) ? $body : '';
	}
}
