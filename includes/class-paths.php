<?php
/**
 * Path confinement for the filesystem family.
 *
 * Every filesystem ability takes paths RELATIVE to the WordPress root
 * (ABSPATH). This class resolves them, refuses anything that escapes the
 * root (".." tricks, absolute paths, symlinks pointing outside), and gives a
 * short display form for responses so absolute server paths never leak.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Paths {

	/** Real, slash-terminated WordPress root. */
	public static function root(): string {
		$root = realpath( ABSPATH );
		return rtrim( false === $root ? ABSPATH : $root, '/\\' ) . '/';
	}

	/**
	 * Resolve a relative path inside the root. When $must_exist is false the
	 * parent directory must exist and be inside the root; the leaf may not.
	 *
	 * @return string|\WP_Error Absolute path or a crafted error.
	 */
	public static function resolve( string $relative, bool $must_exist = true ) {
		$relative = str_replace( '\\', '/', trim( $relative ) );
		if ( '' === $relative || '.' === $relative ) {
			$relative = '';
		}
		if ( 0 === strpos( $relative, '/' ) || preg_match( '#^[A-Za-z]:/#', $relative ) ) {
			return new \WP_Error( 'godmode_path_absolute', 'Paths must be relative to the WordPress root, not absolute.', array( 'status' => 400 ) );
		}
		if ( false !== strpos( $relative, "\0" ) ) {
			return new \WP_Error( 'godmode_path_invalid', 'Path contains a null byte.', array( 'status' => 400 ) );
		}
		$root      = self::root();
		$candidate = $root . ltrim( $relative, '/' );

		if ( $must_exist ) {
			$real = realpath( $candidate );
			if ( false === $real ) {
				return new \WP_Error( 'godmode_path_missing', sprintf( 'Path "%s" does not exist.', $relative ), array( 'status' => 404 ) );
			}
		} else {
			$parent = realpath( dirname( $candidate ) );
			if ( false === $parent ) {
				return new \WP_Error( 'godmode_path_missing', sprintf( 'Parent directory of "%s" does not exist.', $relative ), array( 'status' => 404 ) );
			}
			$real = rtrim( $parent, '/' ) . '/' . basename( $candidate );
		}
		$real_cmp = rtrim( $real, '/' ) . '/';
		if ( 0 !== strpos( $real_cmp, $root ) ) {
			return new \WP_Error( 'godmode_path_escape', sprintf( 'Path "%s" resolves outside the WordPress root and was refused.', $relative ), array( 'status' => 403 ) );
		}
		return $real;
	}

	/**
	 * Resolve a relative path whose leaf, and any number of parent
	 * directories, may not exist yet (fs-mkdir, fs-unzip targets, fs-write
	 * with create_dirs). The deepest existing ancestor is resolved with
	 * realpath and must sit inside the root; the components below it must be
	 * plain names, so ".." cannot climb out and a symlinked ancestor cannot
	 * lead out.
	 *
	 * @return string|\WP_Error Absolute path or a crafted error.
	 */
	public static function resolve_new( string $relative ) {
		$relative = str_replace( '\\', '/', trim( $relative ) );
		if ( 0 === strpos( $relative, '/' ) || preg_match( '#^[A-Za-z]:/#', $relative ) ) {
			return new \WP_Error( 'godmode_path_absolute', 'Paths must be relative to the WordPress root, not absolute.', array( 'status' => 400 ) );
		}
		if ( false !== strpos( $relative, "\0" ) ) {
			return new \WP_Error( 'godmode_path_invalid', 'Path contains a null byte.', array( 'status' => 400 ) );
		}
		$root  = self::root();
		$parts = array_values( array_filter( explode( '/', $relative ), static function ( $p ) { return '' !== $p && '.' !== $p; } ) );
		if ( in_array( '..', $parts, true ) ) {
			return new \WP_Error( 'godmode_path_escape', 'Path must not contain "..".', array( 'status' => 403 ) );
		}
		// The leaf is never resolved, so a symlink there is still visible to
		// the caller's is_link() check, exactly as with resolve( $p, false ).
		$existing = rtrim( $root, '/' );
		$rest     = $parts ? array( array_pop( $parts ) ) : array();
		while ( $parts ) {
			$candidate = $existing . '/' . implode( '/', $parts );
			if ( file_exists( $candidate ) || is_link( $candidate ) ) {
				break;
			}
			array_unshift( $rest, array_pop( $parts ) );
		}
		$real = realpath( $existing . ( $parts ? '/' . implode( '/', $parts ) : '' ) );
		if ( false === $real || 0 !== strpos( rtrim( $real, '/' ) . '/', $root ) ) {
			return new \WP_Error( 'godmode_path_escape', sprintf( 'Path "%s" resolves outside the WordPress root and was refused.', $relative ), array( 'status' => 403 ) );
		}
		return rtrim( $real, '/' ) . ( $rest ? '/' . implode( '/', $rest ) : '' );
	}

	/** Display form: relative to the root, forward slashes, no leading slash. */
	public static function display( string $absolute ): string {
		$root = self::root();
		$abs  = str_replace( '\\', '/', $absolute );
		if ( 0 === strpos( $abs, $root ) ) {
			$abs = substr( $abs, strlen( $root ) );
		}
		return '' === $abs ? '.' : $abs;
	}

	/** True for the root itself and the handful of directories nothing should delete or move. */
	public static function is_protected_dir( string $absolute ): bool {
		$root = self::root();
		$abs  = rtrim( str_replace( '\\', '/', $absolute ), '/' ) . '/';
		$protected = array(
			$root,
			$root . 'wp-admin/',
			$root . 'wp-includes/',
			rtrim( str_replace( '\\', '/', WP_CONTENT_DIR ), '/' ) . '/',
			rtrim( str_replace( '\\', '/', WP_PLUGIN_DIR ), '/' ) . '/',
			rtrim( str_replace( '\\', '/', GODMODE_DIR ), '/' ) . '/',
		);
		return in_array( $abs, $protected, true );
	}

	/**
	 * Files and trees nothing should write, move, delete or chmod: the site
	 * config, the root .htaccess, dotenv files, any .git tree, and this
	 * plugin's own directory. Reading stays allowed (scrubbed). Same spirit
	 * as the refusal to deactivate or delete itself: a change here can cut
	 * the audit trail or lock the operator out mid-request.
	 */
	public static function is_protected_file( string $absolute ): bool {
		$rel = self::display( $absolute );
		if ( '.' === $rel || 0 === strpos( $rel, '/' ) ) {
			return true;
		}
		$base = basename( $rel );
		if ( in_array( $rel, array( 'wp-config.php', '.htaccess' ), true ) || '.env' === $base || 0 === strpos( $base, '.env.' ) ) {
			return true;
		}
		if ( in_array( '.git', explode( '/', $rel ), true ) ) {
			return true;
		}
		$self = rtrim( str_replace( '\\', '/', GODMODE_DIR ), '/' ) . '/';
		return 0 === strpos( rtrim( str_replace( '\\', '/', $absolute ), '/' ) . '/', $self );
	}

	/**
	 * True when the web server reads .htaccess at all. nginx and IIS ignore
	 * it, so on those a "web-denied" directory is only as safe as its file
	 * names are unguessable.
	 */
	public static function htaccess_enforced(): bool {
		$software = strtolower( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return false !== strpos( $software, 'apache' ) || false !== strpos( $software, 'litespeed' );
	}

	/** Warning text for responses that just wrote into a protected directory, or null when .htaccess holds. */
	public static function htaccess_warning(): ?string {
		return self::htaccess_enforced() ? null : 'This web server ignores .htaccess, so the file is protected only by its unguessable name. Deny web access to wp-content/uploads/godmode-* in the server configuration, or delete the file when done.';
	}

	/** A random suffix for backup and export names, so they cannot be guessed where .htaccess is not enforced. */
	public static function unguessable(): string {
		return wp_generate_password( 32, false, false );
	}

	/**
	 * A directory under uploads for Godmode artifacts (file backups, database
	 * exports) with web access denied. Backups must never sit beside the file
	 * they copy: a backup of wp-config.php written next to it carries a
	 * non-PHP extension, so the web server would hand out the original source,
	 * credentials and all, to anyone who guessed the name.
	 *
	 * The markers are rewritten whenever they are missing, not only when the
	 * directory is first made, so a cleaned uploads folder does not quietly
	 * lose its guard.
	 *
	 * @return string|\WP_Error Absolute directory path.
	 */
	public static function protected_dir( string $name ) {
		$up  = wp_upload_dir();
		$dir = rtrim( (string) $up['basedir'], '/' ) . '/godmode-' . $name;
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error( 'godmode_protected_dir_failed', sprintf( 'Could not create the godmode-%s directory in uploads.', $name ), array( 'status' => 500 ) );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.html' ) ) {
			@file_put_contents( $dir . '/index.html', '' );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * The direct filesystem driver, for the few places that delete, rename
	 * or chmod. Direct on purpose: these abilities act on the server the
	 * plugin runs on, and an FTP or SSH transport configured in wp-config
	 * would make them fail inside a REST request with no one to type a
	 * password. Everything else (reads, writes, copies) uses plain PHP.
	 */
	public static function fs(): \WP_Filesystem_Direct {
		static $fs = null;
		if ( null === $fs ) {
			if ( ! class_exists( '\WP_Filesystem_Direct' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			}
			$fs = new \WP_Filesystem_Direct( false );
		}
		return $fs;
	}

	/** Recursive delete. Returns count of removed entries or WP_Error. */
	public static function rm_rf( string $absolute ) {
		$count = 0;
		if ( is_link( $absolute ) || is_file( $absolute ) ) {
			// Type 'f' forces an unlink, so a symlink to a directory is removed as a link, never followed.
			return self::fs()->delete( $absolute, false, 'f' ) ? 1 : new \WP_Error( 'godmode_fs_delete_failed', 'Could not delete ' . self::display( $absolute ) . '.' );
		}
		if ( ! is_dir( $absolute ) ) {
			return 0;
		}
		foreach ( scandir( $absolute ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$r = self::rm_rf( $absolute . '/' . $entry );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$count += (int) $r;
		}
		if ( ! self::fs()->rmdir( $absolute ) ) {
			return new \WP_Error( 'godmode_fs_delete_failed', 'Could not remove directory ' . self::display( $absolute ) . '.' );
		}
		return $count + 1;
	}

	/** Recursive copy. Returns count of copied entries or WP_Error. */
	public static function copy_r( string $from, string $to ) {
		if ( is_file( $from ) ) {
			if ( ! @copy( $from, $to ) ) {
				return new \WP_Error( 'godmode_fs_copy_failed', 'Could not copy ' . self::display( $from ) . '.' );
			}
			return 1;
		}
		if ( ! is_dir( $to ) && ! wp_mkdir_p( $to ) ) {
			return new \WP_Error( 'godmode_fs_copy_failed', 'Could not create ' . self::display( $to ) . '.' );
		}
		$count = 1;
		foreach ( scandir( $from ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$r = self::copy_r( $from . '/' . $entry, $to . '/' . $entry );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$count += (int) $r;
		}
		return $count;
	}

	/** Atomic write: temp file in the same directory, then rename. */
	public static function atomic_write( string $absolute, string $content ) {
		$dir = dirname( $absolute );
		$tmp = $dir . '/.godmode-' . wp_generate_password( 8, false ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $content ) ) {
			return new \WP_Error( 'godmode_fs_write_failed', 'Could not write a temporary file in ' . self::display( $dir ) . '.' );
		}
		// rename() is the atomic replace. WP_Filesystem::move() deletes the
		// destination before renaming, which opens a window with no file at all.
		if ( ! @rename( $tmp, $absolute ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			self::fs()->delete( $tmp, false, 'f' );
			return new \WP_Error( 'godmode_fs_write_failed', 'Could not move the temporary file into place at ' . self::display( $absolute ) . '.' );
		}
		return true;
	}
}
