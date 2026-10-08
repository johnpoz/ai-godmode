<?php
/**
 * Redaction and size caps for audit payloads and the options denylist.
 *
 * Two jobs:
 *  1. Denylist: option names that never leave the site in a response and are
 *     never written through the channel. Exact names plus substring patterns.
 *     Extend with the godmode_option_denylist filter.
 *  2. Redact: scrub an arbitrary input payload before it is logged. Values
 *     under denylisted keys become "[redacted]", long strings are truncated,
 *     and the whole payload is capped at a few KB.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Redactor {

	public const MAX_STRING = 200;
	public const MAX_BYTES  = 4096;

	// -----------------------------------------------------------------------
	// Section: Denylist. Exact option names known to hold secrets or salts.
	// -----------------------------------------------------------------------
	public static function denylist_exact(): array {
		$exact = array(
			'auth_key',
			'secure_auth_key',
			'logged_in_key',
			'nonce_key',
			'auth_salt',
			'secure_auth_salt',
			'logged_in_salt',
			'nonce_salt',
			'secret_key',
			'recovery_keys',
			'recovery_mode_email',
			'admin_email',
			'mailserver_login',
			'mailserver_pass',
			'ftp_credentials',
			'godmode_settings',
			'godmode_audit_log',
			'godmode_audit_seq',
			'godmode_armed',
			'godmode_enabled_abilities',
		);
		return (array) apply_filters( 'godmode_option_denylist', $exact );
	}

	/**
	 * Name prefixes (lowercase) that are always denied. Every option this
	 * plugin owns starts with godmode_, and the switches among them must not
	 * be reachable through the very abilities they switch on.
	 */
	public static function denylist_prefixes(): array {
		return (array) apply_filters( 'godmode_option_denylist_prefixes', array( 'godmode_' ) );
	}

	/** Substring patterns (lowercase) that mark an option or input key as secret. */
	public static function denylist_patterns(): array {
		$patterns = array(
			'password',
			'passwd',
			'secret',
			'_salt',
			'token',
			'api_key',
			'apikey',
			'private_key',
			'access_key',
			'client_secret',
			'license',
			'licence',
			'credential',
			'auth_',
			'oauth',
			'bearer',
			'nonce',
			'login_id',
			'transaction_key',
			'signature_key',
			'consumer_key',
			'webhook_secret',
		);
		return (array) apply_filters( 'godmode_option_denylist_patterns', $patterns );
	}

	public static function is_denied_key( string $key ): bool {
		$k = strtolower( trim( $key ) );
		if ( '' === $k ) {
			return true;
		}
		if ( in_array( $k, array_map( 'strtolower', self::denylist_exact() ), true ) ) {
			return true;
		}
		foreach ( self::denylist_prefixes() as $prefix ) {
			if ( '' !== $prefix && 0 === strpos( $k, strtolower( $prefix ) ) ) {
				return true;
			}
		}
		foreach ( self::denylist_patterns() as $pattern ) {
			if ( '' !== $pattern && false !== strpos( $k, strtolower( $pattern ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A key inside a stored value (a serialized settings array, a JSON blob)
	 * that holds a secret. Wider than is_denied_key: "_key" alone would be
	 * wrong for column names (meta_key, primary key) but is right for a leaf
	 * such as api_transaction_key or signature_key.
	 */
	public static function is_secret_leaf( string $key ): bool {
		$k = strtolower( trim( $key ) );
		return self::is_denied_key( $k ) || (bool) preg_match( '/(^|_)keys?(_|$)/', $k );
	}

	// -----------------------------------------------------------------------
	// Section: Value masking. Walks an option or transient value and blanks
	// every leaf stored under a secret-looking key. Name-only denial is not
	// enough: a gateway plugin keeps api_login_id and api_transaction_key
	// inside one innocently named settings array. Best effort by key name;
	// a secret stored under a key like "value" passes through.
	// -----------------------------------------------------------------------
	public static function mask( $value, int $depth = 0 ) {
		if ( $depth > 12 ) {
			return $value;
		}
		if ( is_object( $value ) ) {
			$value = json_decode( wp_json_encode( $value ), true );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && self::is_secret_leaf( $k ) && ( is_scalar( $v ) || is_array( $v ) ) && ! is_bool( $v ) && '' !== $v ) {
				$value[ $k ] = '[redacted]';
				continue;
			}
			$value[ $k ] = self::mask( $v, $depth + 1 );
		}
		return $value;
	}

	/**
	 * The inverse of mask() for a write: every '[redacted]' leaf in the new
	 * value is put back from the stored value at the same position. Without
	 * this, the natural read-modify-write (read-option, change one field,
	 * write-option) would overwrite a gateway key with the literal text
	 * "[redacted]" and break the site.
	 */
	public static function unmask( $new, $old, int $depth = 0 ) {
		if ( $depth > 12 || ! is_array( $new ) ) {
			return $new;
		}
		if ( is_object( $old ) ) {
			$old = json_decode( wp_json_encode( $old ), true );
		}
		foreach ( $new as $k => $v ) {
			$has_old = is_array( $old ) && array_key_exists( $k, $old );
			if ( '[redacted]' === $v && $has_old ) {
				$new[ $k ] = $old[ $k ];
			} elseif ( is_array( $v ) ) {
				$new[ $k ] = self::unmask( $v, $has_old ? $old[ $k ] : null, $depth + 1 );
			}
		}
		return $new;
	}

	/** True when a value still carries a redaction marker anywhere inside it. */
	public static function has_marker( $value, int $depth = 0 ): bool {
		if ( is_string( $value ) ) {
			return '[redacted]' === $value || false !== strpos( $value, Scrub::MARK );
		}
		if ( $depth > 12 || ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $v ) {
			if ( self::has_marker( $v, $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	// -----------------------------------------------------------------------
	// Section: Payload redaction for the audit log.
	// -----------------------------------------------------------------------
	public static function redact( $value, int $depth = 0 ) {
		if ( $depth > 6 ) {
			return '[depth]';
		}
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > self::MAX_STRING ) {
				return substr( $value, 0, self::MAX_STRING ) . '...[' . strlen( $value ) . ' bytes]';
			}
			return $value;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && self::is_denied_key( $k ) ) {
					$out[ $k ] = '[redacted]';
					continue;
				}
				// An option payload: redact the value when the option name is denied.
				if ( 'value' === $k && isset( $value['option'] ) && is_string( $value['option'] ) && self::is_denied_key( $value['option'] ) ) {
					$out[ $k ] = '[redacted]';
					continue;
				}
				$out[ $k ] = self::redact( $v, $depth + 1 );
			}
			$encoded = wp_json_encode( $out );
			if ( is_string( $encoded ) && strlen( $encoded ) > self::MAX_BYTES ) {
				return array( '_truncated' => true, '_bytes' => strlen( $encoded ) );
			}
			return $out;
		}
		if ( is_object( $value ) ) {
			return self::redact( (array) $value, $depth + 1 );
		}
		return $value;
	}
}
