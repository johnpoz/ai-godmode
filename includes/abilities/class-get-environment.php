<?php
/**
 * godmode/get-environment. Read, harmless. Reports the runtime the agent is
 * standing on. Nothing here is secret, but it still ships switched off.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

final class Get_Environment extends Base {

	public static function name(): string {
		return 'godmode/get-environment';
	}

	public static function definition(): array {
		return array(
			'family'        => 'diagnostics',
			'label'         => 'Get Environment',
			'description'   => 'Report WordPress, PHP, database, server and plugin runtime facts for this site. Read only.',
			'input_schema'  => array(
				'type'                 => 'object',
				'default'              => array(),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'wp_version'           => array( 'type' => 'string' ),
					'php_version'          => array( 'type' => 'string' ),
					'db_server_info'       => array( 'type' => 'string' ),
					'environment'          => array( 'type' => 'string' ),
					'home_url'             => array( 'type' => 'string' ),
					'site_url'             => array( 'type' => 'string' ),
					'multisite'            => array( 'type' => 'boolean' ),
					'active_theme'         => array( 'type' => 'string' ),
					'active_plugins_count' => array( 'type' => 'integer' ),
					'timezone'             => array( 'type' => 'string' ),
					'memory_limit'         => array( 'type' => 'string' ),
					'server_software'      => array( 'type' => 'string' ),
					'godmode_version'      => array( 'type' => 'string' ),
					'godmode_armed'        => array( 'type' => 'boolean' ),
				),
				'required'             => array( 'wp_version', 'php_version', 'db_server_info', 'environment', 'godmode_version' ),
				'additionalProperties' => false,
			),
			'annotations'   => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mutation'      => false,
			'capability'    => 'manage_options',
		);
	}

	public function execute( $input ) {
		global $wpdb;
		$theme   = wp_get_theme();
		$plugins = (array) get_option( 'active_plugins', array() );
		return array(
			'wp_version'           => (string) get_bloginfo( 'version' ),
			'php_version'          => PHP_VERSION,
			'db_server_info'       => (string) $wpdb->db_server_info(),
			'environment'          => (string) wp_get_environment_type(),
			'home_url'             => (string) home_url(),
			'site_url'             => (string) site_url(),
			'multisite'            => is_multisite(),
			'active_theme'         => (string) $theme->get( 'Name' ) . ' ' . (string) $theme->get( 'Version' ),
			'active_plugins_count' => count( $plugins ),
			'timezone'             => (string) wp_timezone_string(),
			'memory_limit'         => (string) ini_get( 'memory_limit' ),
			'server_software'      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['SERVER_SOFTWARE'] ) ) : '',
			'godmode_version'      => GODMODE_VERSION,
			'godmode_armed'        => (bool) get_option( 'godmode_armed', false ),
		);
	}
}
