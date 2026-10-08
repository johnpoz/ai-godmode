<?php
/**
 * Optional MCP bridge. Default off.
 *
 * Finding recorded during the 0.1.0 run: WordPress 7.1 core exposes public
 * abilities over REST (/wp-json/wp-abilities/v1) but ships no MCP endpoint.
 * Reaching an MCP client needs a bridge: the official wordpress/mcp-adapter
 * plugin, or a third-party bridge such as Easy MCP AI that converts public
 * abilities into MCP tools. This version does not vendor the adapter; it
 * registers a dedicated server through the adapter when the adapter is
 * present and this switch is on. Everything is wrapped so an adapter API
 * change can never fatal the site.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Mcp_Bridge {

	private Settings $settings;
	private Registrar $registrar;

	public function __construct( Settings $settings, Registrar $registrar ) {
		$this->settings  = $settings;
		$this->registrar = $registrar;
	}

	public function hooks(): void {
		if ( ! (bool) $this->settings->get( 'mcp_bridge', false ) ) {
			return;
		}
		add_action( 'mcp_adapter_init', array( $this, 'register_server' ) );
	}

	public function register_server( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		$abilities = $this->registrar->active_names();
		if ( empty( $abilities ) ) {
			return;
		}
		try {
			$transport     = class_exists( '\\WP\\MCP\\Transport\\HttpTransport' ) ? array( '\\WP\\MCP\\Transport\\HttpTransport' ) : array();
			$error_handler = class_exists( '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler' ) ? '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler' : null;
			$observability = class_exists( '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' ) ? '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' : null;
			if ( empty( $transport ) || null === $error_handler || null === $observability ) {
				return;
			}
			$adapter->create_server(
				'godmode',
				'godmode',
				'mcp',
				'AI Godmode',
				'Server administrator abilities. Only switched-on abilities are listed.',
				GODMODE_VERSION,
				$transport,
				$error_handler,
				$observability,
				$abilities
			);
		} catch ( \Throwable $e ) {
			// Never let the bridge take the site down. Log locally and move on.
			if ( function_exists( 'error_log' ) ) {
				error_log( 'AI Godmode MCP bridge skipped: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
