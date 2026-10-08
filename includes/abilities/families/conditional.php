<?php
/**
 * Conditional, third-party abilities. Each registers only when its host
 * plugin is active, following the Easy MCP pattern, so it appears on sites
 * that have the plugin and is silent everywhere else.
 *
 * v0.2.0 ships one: SiteGround Speed Optimizer cache purge. Kadence and Pods
 * have no single generic action worth a first-class ability yet; run-php or
 * a later dedicated family covers them.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Siteground_Purge_Cache extends Base {
	public static function name(): string {
		return 'godmode/siteground-purge-cache';
	}

	/** Only offered when Speed Optimizer is active. */
	public static function is_available(): bool {
		return defined( 'SG_CACHEPRESS_VERSION' ) || class_exists( '\\SiteGround_Optimizer\\Supercacher\\Supercacher' ) || function_exists( 'sg_cachepress_purge_cache' );
	}

	public static function definition(): array {
		return self::make(
			'siteground',
			'Purge SiteGround Cache',
			'Flush the SiteGround Speed Optimizer dynamic and file caches. Only present when Speed Optimizer is active.',
			self::obj( array() + self::ack() ),
			self::obj( array( 'purged' => self::bool(), 'method' => self::str() ), array( 'purged', 'method' ) ),
			true
		);
	}

	public function execute( $input ) {
		if ( class_exists( '\\SiteGround_Optimizer\\Supercacher\\Supercacher' ) && method_exists( '\\SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_everything' ) ) {
			\SiteGround_Optimizer\Supercacher\Supercacher::purge_everything();
			return array( 'purged' => true, 'method' => 'Supercacher::purge_everything' );
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
			return array( 'purged' => true, 'method' => 'sg_cachepress_purge_cache' );
		}
		do_action( 'sg_cachepress_purge_cache' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- SiteGround Speed Optimizer's own purge hook.
		return array( 'purged' => true, 'method' => 'action:sg_cachepress_purge_cache' );
	}
}
