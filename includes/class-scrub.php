<?php
/**
 * Secret scrubbing for content that leaves the site: file contents, query
 * rows, constants, environment dumps. Complements Redactor (which handles
 * option names and audit payloads).
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Scrub {

	public const MARK = '[redacted by AI Godmode]';

	/** Constant and variable names whose values are always redacted. */
	public static function secret_name( string $name ): bool {
		$n = strtolower( $name );
		if ( Redactor::is_denied_key( $n ) ) {
			return true;
		}
		foreach ( array( 'db_password', 'db_pass', 'ftp_pass', 'ftp_pw', 'sslkey', 'smtp_pass', 'mailer_pass' ) as $needle ) {
			if ( false !== strpos( $n, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Scrub PHP or config style text: define( 'NAME', 'value' ), $name = 'value',
	 * NAME=value lines (dotenv), and "name": "value" JSON pairs, when the name
	 * looks like a secret.
	 *
	 * Fails closed: if the pattern engine gives up, the result is an empty
	 * string rather than unscrubbed text. Callers that must tell "empty" from
	 * "could not scrub" use text_checked().
	 */
	public static function text( string $text ): string {
		$out = self::text_checked( $text );
		return null === $out ? '' : $out;
	}

	/**
	 * As text(), but null when any pattern failed (backtrack or JIT stack
	 * limit), so the caller can refuse loudly instead of returning nothing.
	 *
	 * The quoted-value patterns use possessive runs ([^'\\\n]++) rather than
	 * one alternation per character. The per-character form exhausted the PCRE
	 * JIT stack on any quoted value of roughly 10 KB (a data URI, a minified
	 * bundle, a long JSON string), which blanked the whole file. Values still
	 * stop at a newline, exactly as before.
	 */
	public static function text_checked( string $text ): ?string {
		$mark = self::MARK;
		// Each quote style: the strict form first, then a fallback that runs to
		// the last matching quote on the line. The fallback only fires where a
		// stray backslash leaves the strict form unclosed, and errs toward
		// redacting more rather than less, as the old backtracking form did.
		$sq   = "(')((?:[^'\\\\\\n]++|\\\\.)*+)(')|(')([^\\n]*)(')";
		$dq   = '(")((?:[^"\\\\\\n]++|\\\\.)*+)(")|(")([^\\n]*)(")';
		$text = preg_replace_callback(
			"/(define\\s*\\(\\s*['\"])([A-Za-z0-9_]+)(['\"]\\s*,\\s*)(?|{$sq}|{$dq})/",
			static function ( $m ) use ( $mark ) {
				return self::secret_name( $m[2] ) ? $m[1] . $m[2] . $m[3] . $m[4] . $mark . $m[6] : $m[0];
			},
			$text
		);
		if ( null === $text ) {
			return null;
		}
		$text = preg_replace_callback(
			"/(\\$([A-Za-z0-9_]+)\\s*=\\s*)(?|{$sq}|{$dq})/",
			static function ( $m ) use ( $mark ) {
				return self::secret_name( $m[2] ) ? $m[1] . $m[3] . $mark . $m[5] : $m[0];
			},
			$text
		);
		if ( null === $text ) {
			return null;
		}
		$text = preg_replace_callback(
			'/^([A-Za-z0-9_]+)(\s*=\s*)(.+)$/m',
			static function ( $m ) use ( $mark ) {
				return self::secret_name( $m[1] ) ? $m[1] . $m[2] . $mark : $m[0];
			},
			$text
		);
		if ( null === $text ) {
			return null;
		}
		$text = preg_replace_callback(
			'/("([A-Za-z0-9_\-]+)"\s*:\s*")((?:[^"\\\\]++|\\\\.)*+)(")/',
			static function ( $m ) use ( $mark ) {
				return self::secret_name( $m[2] ) ? $m[1] . $mark . $m[4] : $m[0];
			},
			$text
		);
		return is_string( $text ) ? $text : null;
	}

	/**
	 * Files whose whole content is a secret. The text scrubber masks values
	 * it can recognise; an archive or raw bytes give it nothing to recognise,
	 * so these are left out of archives altogether.
	 */
	public static function sensitive_file( string $path ): bool {
		$base = strtolower( basename( str_replace( '\\', '/', $path ) ) );
		if ( in_array( $base, array( 'wp-config.php', '.env', '.htpasswd', '.my.cnf' ), true ) || 0 === strpos( $base, '.env.' ) ) {
			return true;
		}
		$ext = pathinfo( $base, PATHINFO_EXTENSION );
		if ( in_array( $ext, array( 'key', 'p12', 'pfx' ), true ) ) {
			return true;
		}
		// A .pem is usually a public CA bundle (plugins ship cacert.pem), and
		// treating every one as secret would break copying, moving and zipping
		// ordinary plugins. It is secret when it holds a private key.
		if ( 'pem' === $ext ) {
			$head = @file_get_contents( $path, false, null, 0, 65536 );
			return false === $head || false !== strpos( (string) $head, 'PRIVATE KEY' );
		}
		return false;
	}

	/**
	 * Whole-secret files at or under a path, as display paths (at most $cap).
	 * fs-copy and fs-move refuse when this is not empty: a copy is as
	 * unscrubbable as an archive, and either one can land the file somewhere
	 * the web server hands out raw, which fs-read's scrubbing never allows.
	 */
	public static function sensitive_under( string $absolute, int $cap = 20 ): array {
		if ( is_file( $absolute ) || is_link( $absolute ) ) {
			return self::sensitive_file( $absolute ) ? array( Paths::display( $absolute ) ) : array();
		}
		$found = array();
		if ( ! is_dir( $absolute ) ) {
			return $found;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $absolute, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::LEAVES_ONLY, \RecursiveIteratorIterator::CATCH_GET_CHILD );
		foreach ( $it as $file ) {
			if ( self::sensitive_file( $file->getPathname() ) ) {
				$found[] = Paths::display( $file->getPathname() );
				if ( count( $found ) >= $cap ) {
					break;
				}
			}
		}
		return $found;
	}

	/**
	 * A value that looks like a secret whatever its column is called: a
	 * WordPress or bcrypt/argon password hash, or a long bare hex or base64
	 * token. Column aliases (SELECT user_pass AS p) defeat the name checks,
	 * and this is the fallback. Best effort: a 40-character-plus word of only
	 * token characters with a digit in it is masked even when it is innocent
	 * (a lowercase hyphenated slug is let through), and a secret with spaces
	 * or punctuation in it is not caught.
	 */
	public static function looks_secret( string $value ): bool {
		if ( preg_match( '/^\$(P|wp|2[aby]|argon2(i|d|id)?)\$/', $value ) ) {
			return true;
		}
		if ( strlen( $value ) < 40 ) {
			return false;
		}
		if ( preg_match( '/^[A-Fa-f0-9]{40,}$/', $value ) ) {
			return true;
		}
		return (bool) preg_match( '/^[A-Za-z0-9+\/_\-]{40,}={0,2}$/', $value ) && preg_match( '/[0-9]/', $value ) && ( preg_match( '/[A-Z]/', $value ) || false === strpos( $value, '-' ) );
	}

	/**
	 * Mask secret-looking leaves inside a serialized or JSON value without
	 * destroying the rest of it. Returns the value unchanged when it is
	 * neither, or when nothing inside it was masked.
	 */
	public static function stored_value( string $value ): string {
		$decoded = null;
		if ( is_serialized( $value ) ) {
			$decoded = @unserialize( $value, array( 'allowed_classes' => false ) );
			$encode  = 'serialize';
		} elseif ( preg_match( '/^\s*[\[{]/', $value ) ) {
			$decoded = json_decode( $value, true );
			$encode  = 'wp_json_encode';
		}
		if ( ! is_array( $decoded ) ) {
			return $value;
		}
		// Compare against the same object-to-array normalisation mask() applies,
		// so a value that merely contains an object is not rewritten for nothing.
		$plain  = json_decode( wp_json_encode( $decoded ), true );
		$masked = Redactor::mask( $decoded );
		if ( $masked === $plain ) {
			return $value;
		}
		$out = $encode( $masked );
		return is_string( $out ) ? $out : self::MARK;
	}

	/**
	 * Scrub an associative row (database result): user_pass, activation keys,
	 * denied option values, secret leaves inside serialized values, and any
	 * cell that looks like a hash or token whatever its column is called.
	 */
	public static function row( array $row ): array {
		foreach ( $row as $col => $val ) {
			$c = strtolower( (string) $col );
			if ( in_array( $c, array( 'user_pass', 'user_activation_key', 'password', 'secret', 'token', 'api_key' ), true ) || self::secret_name( $c ) ) {
				$row[ $col ] = self::MARK;
			}
		}
		if ( isset( $row['option_name'], $row['option_value'] ) && Redactor::is_denied_key( (string) $row['option_name'] ) ) {
			$row['option_value'] = self::MARK;
		}
		if ( isset( $row['meta_key'], $row['meta_value'] ) && Redactor::is_denied_key( (string) $row['meta_key'] ) ) {
			$row['meta_value'] = self::MARK;
		}
		foreach ( $row as $col => $val ) {
			$c = strtolower( (string) $col );
			if ( ! is_string( $val ) || self::MARK === $val ) {
				continue;
			}
			// Identifier columns (option_name, meta_key, post_name, guid) hold
			// long digit-bearing names that are not secrets; the shape test
			// skips them and applies to every other column, alias or not.
			if ( ! preg_match( '/(_name|_key|_slug|_login|_nicename|_url|guid)$/', $c ) && self::looks_secret( $val ) ) {
				$row[ $col ] = self::MARK;
			} elseif ( in_array( $c, array( 'option_value', 'meta_value' ), true ) ) {
				$row[ $col ] = self::stored_value( $val );
			}
		}
		return $row;
	}

	/** Cap a string, noting the original size. */
	public static function cap( string $text, int $max ): array {
		$len = strlen( $text );
		if ( $len <= $max ) {
			return array( 'text' => $text, 'truncated' => false, 'bytes' => $len );
		}
		return array( 'text' => substr( $text, 0, $max ), 'truncated' => true, 'bytes' => $len );
	}
}
