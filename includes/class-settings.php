<?php
/**
 * Settings storage. Three options, all default-off:
 *
 *  godmode_armed              bool   master switch. False means nothing runs.
 *  godmode_enabled_abilities  array  ability name => bool, per-ability switch.
 *  godmode_settings           array  misc: mcp_bridge (bool), sink (string).
 *
 * Effective state of an ability = armed AND enabled[ability]. Both must be
 * true. The create-disabled invariant: a newly registered ability is always
 * absent from the map, which reads as false.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Settings {

	public const OPT_ARMED    = 'godmode_armed';
	public const OPT_ENABLED  = 'godmode_enabled_abilities';
	public const OPT_SETTINGS = 'godmode_settings';

	// -----------------------------------------------------------------------
	// Section: Defaults. Seeded at activation only when the option is missing.
	// -----------------------------------------------------------------------
	public static function seed_defaults(): void {
		if ( null === get_option( self::OPT_ARMED, null ) ) {
			add_option( self::OPT_ARMED, false, '', false );
		}
		if ( null === get_option( self::OPT_ENABLED, null ) ) {
			add_option( self::OPT_ENABLED, array(), '', false );
		}
		if ( null === get_option( self::OPT_SETTINGS, null ) ) {
			add_option( self::OPT_SETTINGS, self::default_settings(), '', false );
		}
	}

	public static function default_settings(): array {
		return array(
			'mcp_bridge'      => false,
			'sink'            => 'local',
			'ring_buffer_max' => 200,

			// Off-site audit log. Empty until provisioned. The site stores the
			// endpoint and an append-only ingest key, and deliberately never
			// stores the viewer key or the Cloudflare provisioning token.
			'cf_endpoint'       => '',
			'cf_ingest_key'     => '',
			'cf_account_id'     => '',
			'cf_retention'      => 'indefinite',
			'cf_provisioned_utc'=> '',
			'cf_rotated_utc'    => '',
			'heartbeat'         => true,

			// The compact tool surface (the "tool drawer"). On by default: it
			// removes exposure rather than granting power. surface_always null
			// means the shipped always-loaded set; a list means the operator's.
			'compact_surface'   => true,
			'surface_always'    => null,
			'surface_hidden'    => array(),
			'surface_foreign'   => 'listed',

			// This site's own place in the off-site log. One bucket per site,
			// one binding per site, one registry shared by the account. None of
			// it is secret: the keys themselves live only in the operator's
			// hands and, as hashes, in the registry.
			'cf_site_slug'      => '',
			'cf_bucket'         => '',
			'cf_binding'        => '',
			'cf_kv_namespace'   => '',
			'cf_archives'       => array(),

			// The off-site chain, numbered separately from the local buffer so
			// that a gap in it means a missing record and nothing else.
			'cf_seq'            => 0,
			'cf_last_hash'      => '',
		);
	}

	// -----------------------------------------------------------------------
	// Section: Master switch.
	// -----------------------------------------------------------------------
	public function is_armed(): bool {
		return (bool) get_option( self::OPT_ARMED, false );
	}

	public function set_armed( bool $armed ): void {
		update_option( self::OPT_ARMED, $armed, false );
	}

	// -----------------------------------------------------------------------
	// Section: Per-ability switches.
	// -----------------------------------------------------------------------
	public function enabled_map(): array {
		$map = get_option( self::OPT_ENABLED, array() );
		return is_array( $map ) ? $map : array();
	}

	public function is_enabled( string $ability ): bool {
		$map = $this->enabled_map();
		return ! empty( $map[ $ability ] );
	}

	/** True only when the master switch is on AND this ability is on. */
	public function is_active( string $ability ): bool {
		return $this->is_armed() && $this->is_enabled( $ability );
	}

	public function set_enabled( string $ability, bool $enabled ): void {
		$map             = $this->enabled_map();
		$map[ $ability ] = $enabled;
		update_option( self::OPT_ENABLED, $map, false );
	}

	/** Flip every known ability at once. Used by the arm-all and disarm-all controls. */
	public function set_all_abilities( bool $enabled, array $names = array() ): void {
		$map = $this->enabled_map();
		if ( empty( $names ) ) {
			$names = array_keys( $map );
		}
		foreach ( $names as $name ) {
			$map[ $name ] = $enabled;
		}
		update_option( self::OPT_ENABLED, $map, false );
	}

	// -----------------------------------------------------------------------
	// Section: Misc settings.
	// -----------------------------------------------------------------------
	public function get( string $key, $default = null ) {
		$all = get_option( self::OPT_SETTINGS, array() );
		$all = is_array( $all ) ? $all : array();
		$all = array_merge( self::default_settings(), $all );
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public function set( string $key, $value ): void {
		$all = get_option( self::OPT_SETTINGS, array() );
		$all = is_array( $all ) ? $all : array();
		$all[ $key ] = $value;
		update_option( self::OPT_SETTINGS, $all, false );
	}
}
