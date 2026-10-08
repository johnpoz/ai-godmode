<?php
/**
 * Plugins and themes family: plugin-list, plugin-install, plugin-activate,
 * plugin-deactivate, plugin-update, plugin-delete, theme-list, theme-install,
 * theme-switch, theme-update, theme-delete.
 *
 * Installs and updates use WordPress's own upgrader classes with a silent
 * skin, so the result is what wp-admin would have done. Godmode refuses to
 * deactivate or delete itself: doing so mid-request would cut the audit
 * trail in half.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin_Tools {
	public static function load(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}

	/** Resolve a plugin argument (folder/file.php, folder, or slug) to a plugin file. */
	public static function find_plugin( string $ref ): ?string {
		self::load();
		$all = get_plugins();
		if ( isset( $all[ $ref ] ) ) {
			return $ref;
		}
		foreach ( array_keys( $all ) as $file ) {
			if ( dirname( $file ) === $ref || $file === $ref . '.php' ) {
				return $file;
			}
		}
		return null;
	}

	public static function is_self( string $file ): bool {
		return $file === GODMODE_BASENAME || dirname( $file ) === dirname( GODMODE_BASENAME );
	}

	/** Package source: wp.org slug or a direct zip URL. */
	public static function package_url( string $source, string $type ) {
		if ( preg_match( '#^https?://#i', $source ) ) {
			return $source;
		}
		if ( ! preg_match( '/^[a-z0-9\-_]+$/i', $source ) ) {
			return new \WP_Error( 'godmode_bad_source', 'Source must be a wordpress.org slug or an https zip URL.', array( 'status' => 400 ) );
		}
		if ( 'plugin' === $type ) {
			$api = plugins_api( 'plugin_information', array( 'slug' => $source, 'fields' => array( 'sections' => false ) ) );
		} else {
			$api = themes_api( 'theme_information', array( 'slug' => $source, 'fields' => array( 'sections' => false ) ) );
		}
		if ( is_wp_error( $api ) ) {
			return new \WP_Error( 'godmode_not_found', sprintf( 'wordpress.org has no %s named "%s".', $type, $source ), array( 'status' => 404 ) );
		}
		return (string) $api->download_link;
	}

	public static function describe_plugin( string $file, array $data ): array {
		return array(
			'plugin'  => $file,
			'name'    => (string) ( $data['Name'] ?? '' ),
			'version' => (string) ( $data['Version'] ?? '' ),
			'active'  => is_plugin_active( $file ),
			'author'  => wp_strip_all_tags( (string) ( $data['Author'] ?? '' ) ),
		);
	}
}

final class Plugin_List extends Base {
	public static function name(): string {
		return 'godmode/plugin-list';
	}
	public static function definition(): array {
		return self::make( 'plugins', 'List Plugins', 'List installed plugins with version, active state and available update.', self::obj( array() ), self::obj( array( 'plugins' => self::list_of( self::map() ), 'count' => array( 'type' => 'integer' ) ), array( 'plugins', 'count' ) ), false );
	}
	public function execute( $input ) {
		Plugin_Tools::load();
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();
		foreach ( get_plugins() as $file => $data ) {
			$row = Plugin_Tools::describe_plugin( $file, $data );
			$row['update_available'] = isset( $updates->response[ $file ] ) ? (string) $updates->response[ $file ]->new_version : null;
			$out[] = $row;
		}
		return array( 'plugins' => $out, 'count' => count( $out ) );
	}
}

final class Plugin_Install extends Base {
	public static function name(): string {
		return 'godmode/plugin-install';
	}
	public static function definition(): array {
		return self::make( 'plugins', 'Install Plugin', 'Install a plugin from a wordpress.org slug or an https zip URL, optionally activating it. Refuses if the folder already exists unless overwrite is true, which replaces the installed copy in place (an upgrade or downgrade) and keeps it active if it was; overwrite requires sha256, the hex digest of the zip, and the install aborts on a mismatch.', self::obj( array( 'source' => self::str( 500, 1 ), 'activate' => self::bool( false ), 'overwrite' => self::bool( false ), 'sha256' => self::str( 64 ) ) + self::ack(), array( 'source' ) ), self::obj( array( 'plugin' => self::str(), 'name' => self::str(), 'version' => self::str(), 'active' => self::bool(), 'author' => self::str(), 'version_before' => self::nullable( self::str() ) ), array( 'plugin', 'active' ) ), true, false );
	}
	public function execute( $input ) {
		Plugin_Tools::load();
		$url = Plugin_Tools::package_url( (string) $input['source'], 'plugin' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$overwrite = ! empty( $input['overwrite'] );
		$sha       = strtolower( trim( (string) ( $input['sha256'] ?? '' ) ) );
		$package   = $url;
		$temp      = null;
		$before    = null;
		if ( '' !== $sha && ! preg_match( '/^[0-9a-f]{64}$/', $sha ) ) {
			return self::err( 'godmode_bad_hash', 'sha256 must be 64 hex characters.' );
		}
		if ( $overwrite && '' === $sha ) {
			return self::err( 'godmode_hash_required', 'overwrite requires sha256: the digest of the zip you intend to install over the existing copy.' );
		}
		if ( '' !== $sha ) {
			// Fetch ourselves so the digest is checked before the upgrader
			// unpacks anything. A local path is accepted by the upgrader as a
			// package, so the verified temp file goes in instead of the URL.
			$temp = download_url( $url, 120 );
			if ( is_wp_error( $temp ) ) {
				return self::err( 'godmode_download_failed', 'Download failed: ' . $temp->get_error_message(), 502 );
			}
			$got = hash_file( 'sha256', $temp );
			if ( $got !== $sha ) {
				wp_delete_file( $temp );
				return self::err( 'godmode_hash_mismatch', 'The downloaded zip has sha256 ' . $got . ', not the one given. Nothing was installed.', 409 );
			}
			$package = $temp;
		}
		if ( $overwrite ) {
			// Record what is there now so the response can say what changed.
			$zip = new \ZipArchive();
			if ( true === $zip->open( $package ) ) {
				$top = (string) $zip->getNameIndex( 0 );
				$zip->close();
				$folder = strtok( $top, '/' );
				foreach ( get_plugins() as $pf => $pd ) {
					if ( $folder && 0 === strpos( $pf, $folder . '/' ) ) {
						$before = (string) ( $pd['Version'] ?? '' );
						if ( Plugin_Tools::is_self( $pf ) ) {
							wp_delete_file( (string) $temp );
							return self::err( 'godmode_self_protect', 'AI Godmode will not overwrite itself through this ability. Use run-php deliberately, or the Plugins screen.', 403 );
						}
					}
				}
			}
		}
		$upgrader = new \Plugin_Upgrader( new \AIGodmode\Silent_Skin() );
		$result   = $upgrader->install( $package, $overwrite ? array( 'overwrite_package' => true ) : array() );
		if ( null !== $temp ) {
			wp_delete_file( $temp );
		}
		if ( is_wp_error( $result ) ) {
			return self::err( 'godmode_install_failed', 'Install failed: ' . $result->get_error_message(), 500 );
		}
		if ( ! $result ) {
			return self::err( 'godmode_install_failed', 'Install failed: ' . ( $upgrader->skin->last_error() ?: 'the upgrader returned false (folder may already exist; pass overwrite with sha256 to replace it).' ), 500 );
		}
		$file = $upgrader->plugin_info();
		if ( ! $file ) {
			return self::err( 'godmode_install_failed', 'Installed, but the main plugin file could not be identified.', 500 );
		}
		wp_clean_plugins_cache( true );
		if ( ! empty( $input['activate'] ) ) {
			$a = activate_plugin( $file, '', false, true );
			if ( is_wp_error( $a ) ) {
				return self::err( 'godmode_activate_failed', 'Installed but activation failed: ' . $a->get_error_message(), 500 );
			}
		}
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		$out  = Plugin_Tools::describe_plugin( $file, $data );
		$out['version_before'] = $before;
		return $out;
	}
}

final class Plugin_Activate extends Base {
	public static function name(): string {
		return 'godmode/plugin-activate';
	}
	public static function definition(): array {
		return self::make( 'plugins', 'Activate Plugin', 'Activate an installed plugin by folder, slug or folder/file.php. A fatal error on load is caught and reported and the plugin stays inactive.', self::obj( array( 'plugin' => self::str( 200, 1 ) ) + self::ack(), array( 'plugin' ) ), self::obj( array( 'plugin' => self::str(), 'active' => self::bool() ), array( 'plugin', 'active' ) ), true );
	}
	public function execute( $input ) {
		$file = Plugin_Tools::find_plugin( (string) $input['plugin'] );
		if ( ! $file ) {
			return self::err( 'godmode_not_found', 'No installed plugin matches "' . $input['plugin'] . '".', 404 );
		}
		if ( is_plugin_active( $file ) ) {
			return array( 'plugin' => $file, 'active' => true );
		}
		$r = activate_plugin( $file, '', false, false );
		if ( is_wp_error( $r ) ) {
			return self::err( 'godmode_activate_failed', 'Activation failed: ' . $r->get_error_message(), 500 );
		}
		return array( 'plugin' => $file, 'active' => is_plugin_active( $file ) );
	}
}

final class Plugin_Deactivate extends Base {
	public static function name(): string {
		return 'godmode/plugin-deactivate';
	}
	public static function definition(): array {
		return self::make( 'plugins', 'Deactivate Plugin', 'Deactivate an active plugin. Refuses AI Godmode itself.', self::obj( array( 'plugin' => self::str( 200, 1 ) ) + self::ack(), array( 'plugin' ) ), self::obj( array( 'plugin' => self::str(), 'active' => self::bool() ), array( 'plugin', 'active' ) ), true );
	}
	public function execute( $input ) {
		$file = Plugin_Tools::find_plugin( (string) $input['plugin'] );
		if ( ! $file ) {
			return self::err( 'godmode_not_found', 'No installed plugin matches "' . $input['plugin'] . '".', 404 );
		}
		if ( Plugin_Tools::is_self( $file ) ) {
			return self::err( 'godmode_self_protect', 'AI Godmode will not deactivate itself. Use the Plugins screen.', 403 );
		}
		deactivate_plugins( $file );
		return array( 'plugin' => $file, 'active' => is_plugin_active( $file ) );
	}
}

final class Plugin_Update extends Base {
	public static function name(): string {
		return 'godmode/plugin-update';
	}
	public static function definition(): array {
		return self::make( 'plugins', 'Update Plugin', 'Update one plugin to the latest version wordpress.org (or its own updater) offers.', self::obj( array( 'plugin' => self::str( 200, 1 ) ) + self::ack(), array( 'plugin' ) ), self::obj( array( 'plugin' => self::str(), 'from' => self::str(), 'to' => self::str(), 'updated' => self::bool() ), array( 'plugin', 'updated' ) ), true );
	}
	public function execute( $input ) {
		$file = Plugin_Tools::find_plugin( (string) $input['plugin'] );
		if ( ! $file ) {
			return self::err( 'godmode_not_found', 'No installed plugin matches "' . $input['plugin'] . '".', 404 );
		}
		$before = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( ! isset( $updates->response[ $file ] ) ) {
			return array( 'plugin' => $file, 'from' => (string) $before['Version'], 'to' => (string) $before['Version'], 'updated' => false );
		}
		$upgrader = new \Plugin_Upgrader( new \AIGodmode\Silent_Skin() );
		$result   = $upgrader->upgrade( $file );
		if ( is_wp_error( $result ) ) {
			return self::err( 'godmode_update_failed', 'Update failed: ' . $result->get_error_message(), 500 );
		}
		if ( ! $result ) {
			return self::err( 'godmode_update_failed', 'Update failed: ' . ( $upgrader->skin->last_error() ?: 'the upgrader returned false.' ), 500 );
		}
		wp_clean_plugins_cache( true );
		$after = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		return array( 'plugin' => $file, 'from' => (string) $before['Version'], 'to' => (string) $after['Version'], 'updated' => $before['Version'] !== $after['Version'] );
	}
}

final class Plugin_Delete extends Base {
	public static function name(): string {
		return 'godmode/plugin-delete';
	}
	public static function definition(): array {
		return self::make( 'plugins', 'Delete Plugin', 'Delete an inactive plugin (deactivate first). Refuses AI Godmode itself.', self::obj( array( 'plugin' => self::str( 200, 1 ) ) + self::ack(), array( 'plugin' ) ), self::obj( array( 'plugin' => self::str(), 'deleted' => self::bool() ), array( 'plugin', 'deleted' ) ), true );
	}
	public function execute( $input ) {
		$file = Plugin_Tools::find_plugin( (string) $input['plugin'] );
		if ( ! $file ) {
			return self::err( 'godmode_not_found', 'No installed plugin matches "' . $input['plugin'] . '".', 404 );
		}
		if ( Plugin_Tools::is_self( $file ) ) {
			return self::err( 'godmode_self_protect', 'AI Godmode will not delete itself. Use the Plugins screen.', 403 );
		}
		if ( is_plugin_active( $file ) ) {
			return self::err( 'godmode_plugin_active', 'Plugin is active. Deactivate it first.', 409 );
		}
		$r = delete_plugins( array( $file ) );
		if ( is_wp_error( $r ) ) {
			return self::err( 'godmode_delete_failed', 'Delete failed: ' . $r->get_error_message(), 500 );
		}
		return array( 'plugin' => $file, 'deleted' => true === $r );
	}
}

final class Theme_List extends Base {
	public static function name(): string {
		return 'godmode/theme-list';
	}
	public static function definition(): array {
		return self::make( 'themes', 'List Themes', 'List installed themes with version, active state, parent and available update.', self::obj( array() ), self::obj( array( 'themes' => self::list_of( self::map() ), 'active' => self::str() ), array( 'themes', 'active' ) ), false );
	}
	public function execute( $input ) {
		Plugin_Tools::load();
		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		$active  = get_stylesheet();
		$out     = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[] = array(
				'theme'            => $slug,
				'name'             => (string) $theme->get( 'Name' ),
				'version'          => (string) $theme->get( 'Version' ),
				'parent'           => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
				'active'           => $slug === $active,
				'update_available' => isset( $updates->response[ $slug ]['new_version'] ) ? (string) $updates->response[ $slug ]['new_version'] : null,
			);
		}
		return array( 'themes' => $out, 'active' => $active );
	}
}

final class Theme_Install extends Base {
	public static function name(): string {
		return 'godmode/theme-install';
	}
	public static function definition(): array {
		return self::make( 'themes', 'Install Theme', 'Install a theme from a wordpress.org slug or an https zip URL. Does not switch to it.', self::obj( array( 'source' => self::str( 500, 1 ) ) + self::ack(), array( 'source' ) ), self::obj( array( 'theme' => self::str(), 'name' => self::str(), 'version' => self::str() ), array( 'theme' ) ), true, false );
	}
	public function execute( $input ) {
		Plugin_Tools::load();
		$url = Plugin_Tools::package_url( (string) $input['source'], 'theme' );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		$upgrader = new \Theme_Upgrader( new \AIGodmode\Silent_Skin() );
		$result   = $upgrader->install( $url );
		if ( is_wp_error( $result ) ) {
			return self::err( 'godmode_install_failed', 'Install failed: ' . $result->get_error_message(), 500 );
		}
		if ( ! $result ) {
			return self::err( 'godmode_install_failed', 'Install failed: ' . ( $upgrader->skin->last_error() ?: 'the upgrader returned false (folder may already exist).' ), 500 );
		}
		$slug  = (string) $upgrader->theme_info()->get_stylesheet();
		$theme = wp_get_theme( $slug );
		return array( 'theme' => $slug, 'name' => (string) $theme->get( 'Name' ), 'version' => (string) $theme->get( 'Version' ) );
	}
}

final class Theme_Switch extends Base {
	public static function name(): string {
		return 'godmode/theme-switch';
	}
	public static function definition(): array {
		return self::make( 'themes', 'Switch Theme', 'Make an installed theme the active theme. Returns the previous theme so it can be switched back.', self::obj( array( 'theme' => self::str( 100, 1 ) ) + self::ack(), array( 'theme' ) ), self::obj( array( 'theme' => self::str(), 'previous' => self::str(), 'switched' => self::bool() ), array( 'theme', 'previous', 'switched' ) ), true );
	}
	public function execute( $input ) {
		$slug  = (string) $input['theme'];
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return self::err( 'godmode_not_found', 'No installed theme named "' . $slug . '".', 404 );
		}
		if ( $theme->errors() ) {
			return self::err( 'godmode_theme_broken', 'Theme is broken: ' . $theme->errors()->get_error_message(), 409 );
		}
		$previous = get_stylesheet();
		switch_theme( $slug );
		return array( 'theme' => $slug, 'previous' => $previous, 'switched' => get_stylesheet() === $slug );
	}
}

final class Theme_Update extends Base {
	public static function name(): string {
		return 'godmode/theme-update';
	}
	public static function definition(): array {
		return self::make( 'themes', 'Update Theme', 'Update one theme to the latest version offered.', self::obj( array( 'theme' => self::str( 100, 1 ) ) + self::ack(), array( 'theme' ) ), self::obj( array( 'theme' => self::str(), 'from' => self::str(), 'to' => self::str(), 'updated' => self::bool() ), array( 'theme', 'updated' ) ), true );
	}
	public function execute( $input ) {
		Plugin_Tools::load();
		$slug  = (string) $input['theme'];
		$theme = wp_get_theme( $slug );
		if ( ! $theme->exists() ) {
			return self::err( 'godmode_not_found', 'No installed theme named "' . $slug . '".', 404 );
		}
		$from = (string) $theme->get( 'Version' );
		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		if ( ! isset( $updates->response[ $slug ] ) ) {
			return array( 'theme' => $slug, 'from' => $from, 'to' => $from, 'updated' => false );
		}
		$upgrader = new \Theme_Upgrader( new \AIGodmode\Silent_Skin() );
		$result   = $upgrader->upgrade( $slug );
		if ( is_wp_error( $result ) || ! $result ) {
			return self::err( 'godmode_update_failed', 'Update failed: ' . ( is_wp_error( $result ) ? $result->get_error_message() : ( $upgrader->skin->last_error() ?: 'the upgrader returned false.' ) ), 500 );
		}
		$to = (string) wp_get_theme( $slug )->get( 'Version' );
		return array( 'theme' => $slug, 'from' => $from, 'to' => $to, 'updated' => $from !== $to );
	}
}

final class Theme_Delete extends Base {
	public static function name(): string {
		return 'godmode/theme-delete';
	}
	public static function definition(): array {
		return self::make( 'themes', 'Delete Theme', 'Delete an installed theme that is not active and not the parent of the active theme.', self::obj( array( 'theme' => self::str( 100, 1 ) ) + self::ack(), array( 'theme' ) ), self::obj( array( 'theme' => self::str(), 'deleted' => self::bool() ), array( 'theme', 'deleted' ) ), true );
	}
	public function execute( $input ) {
		Plugin_Tools::load();
		$slug = (string) $input['theme'];
		if ( ! wp_get_theme( $slug )->exists() ) {
			return self::err( 'godmode_not_found', 'No installed theme named "' . $slug . '".', 404 );
		}
		if ( $slug === get_stylesheet() || $slug === get_template() ) {
			return self::err( 'godmode_theme_active', 'That theme is active or is the parent of the active theme.', 409 );
		}
		$r = delete_theme( $slug );
		if ( is_wp_error( $r ) ) {
			return self::err( 'godmode_delete_failed', 'Delete failed: ' . $r->get_error_message(), 500 );
		}
		return array( 'theme' => $slug, 'deleted' => true === $r );
	}
}
