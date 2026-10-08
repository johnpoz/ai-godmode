<?php
/**
 * Upgrader skin that prints nothing and remembers the last error, so plugin
 * and theme installs and updates can run inside an ability without leaking
 * HTML into the response.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\WP_Upgrader_Skin' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
}

final class Silent_Skin extends \WP_Upgrader_Skin {

	private string $last_error = '';

	public function header() {}
	public function footer() {}
	public function before( $title = '' ) {}
	public function after( $title = '' ) {}
	public function feedback( $feedback, ...$args ) {}

	public function error( $errors ) {
		if ( is_string( $errors ) ) {
			$this->last_error = $errors;
		} elseif ( is_wp_error( $errors ) && $errors->has_errors() ) {
			$this->last_error = $errors->get_error_message();
		}
	}

	public function request_filesystem_credentials( $error = false, $context = '', $allow_relaxed_file_ownership = false ) {
		// Direct filesystem only. Anything that needs FTP credentials is refused.
		return 'direct' === get_filesystem_method( array(), $context, $allow_relaxed_file_ownership ) ? true : false;
	}

	public function last_error(): string {
		return $this->last_error;
	}
}
