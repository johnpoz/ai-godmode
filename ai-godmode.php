<?php
/**
 * Plugin Name: AI Godmode
 * Plugin URI: https://github.com/johnpoz/ai-godmode
 * Description: Grants server-administrator abilities to AI agents through the WordPress Abilities API. Every ability ships switched off. Arm it deliberately.
 * Version: 0.8.4
 * Requires at least: 6.9
 * Requires PHP: 8.0
 * Author: John Pozadzides
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-godmode
 *
 * @package AIGodmode
 */

// ---------------------------------------------------------------------------
// Section: Guard. Never run outside WordPress.
// ---------------------------------------------------------------------------
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Section: Constants.
// ---------------------------------------------------------------------------
define( 'GODMODE_VERSION', '0.8.4' );
define( 'GODMODE_FILE', __FILE__ );
define( 'GODMODE_DIR', plugin_dir_path( __FILE__ ) );
define( 'GODMODE_BASENAME', plugin_basename( __FILE__ ) );
define( 'GODMODE_NAMESPACE', 'godmode' );

// ---------------------------------------------------------------------------
// Section: Autoloader. Maps AIGodmode\Foo_Bar to includes/class-foo-bar.php,
// AIGodmode\Abilities\Foo to includes/abilities/class-foo.php, and
// AIGodmode\Audit_Sink (interface) to includes/interface-audit-sink.php.
// ---------------------------------------------------------------------------
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'AIGodmode\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$parts    = explode( '\\', $relative );
		$leaf     = array_pop( $parts );
		$slug     = strtolower( str_replace( '_', '-', $leaf ) );
		$subdir   = $parts ? strtolower( implode( '/', $parts ) ) . '/' : '';
		$kind     = ( 0 === strpos( $leaf, 'I_' ) ) ? 'interface' : 'class';
		if ( 'interface' === $kind ) {
			$slug = substr( $slug, 2 );
		}
		$file = GODMODE_DIR . 'includes/' . $subdir . $kind . '-' . $slug . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

// ---------------------------------------------------------------------------
// Section: Snippets guard. Must run while plugins are still loading, before
// Code Snippets evaluates snippets at plugins_loaded. It skips only the one
// snippet a guarded Godmode request names, only for that request, and does
// nothing unless Godmode is armed and that ability is on. See the class.
// ---------------------------------------------------------------------------
if ( class_exists( 'AIGodmode\\Snippets_Guard' ) ) {
	AIGodmode\Snippets_Guard::boot();
}

// ---------------------------------------------------------------------------
// Section: Lifecycle hooks. Activation seeds safe defaults (everything off).
// Deactivation disarms so a reactivated plugin never wakes up armed.
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( 'AIGodmode\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AIGodmode\\Plugin', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Section: Boot. Abilities API exists only on WordPress 6.9 and later; on
// older cores the plugin stays inert and shows one admin notice.
// ---------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Godmode needs WordPress 6.9 or later (the Abilities API). The plugin is inactive until the site is upgraded.', 'ai-godmode' ) . '</p></div>';
				}
			);
			return;
		}
		AIGodmode\Plugin::instance()->boot();
	},
	5
);
