<?php
/**
 * Uninstall cleanup. Removes every option the plugin created, including the
 * local audit buffer. The off-site log (when provisioned) is untouched by
 * design: the site cannot delete it.
 *
 * @package AIGodmode
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'godmode_armed', 'godmode_enabled_abilities', 'godmode_settings', 'godmode_audit_log', 'godmode_audit_seq' ) as $godmode_option ) {
	delete_option( $godmode_option );
}

// Versions of Code Snippets code that Godmode saved before changing it.
global $wpdb;
$godmode_history = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'godmode_snippet_history_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
foreach ( (array) $godmode_history as $godmode_option ) {
	delete_option( $godmode_option );
}
