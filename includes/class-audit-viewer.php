<?php
/**
 * Reads the off-site audit log back and checks it.
 *
 * This is the authoritative chain check. The Worker performs the same check so
 * that the log stays readable when this site is wrecked, but it has to
 * reimplement PHP's json_encode to do it. Here the real wp_json_encode is
 * available, so what this class reports is the last word.
 *
 * The viewer key is supplied per request and is never persisted. A site that
 * holds the key to read its own log can be made to lie about it, which is
 * exactly the situation the off-site log exists to prevent.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit_Viewer {

	private const FIELDS = array( 'seq', 'prev_hash', 'time', 'site', 'event', 'ability', 'mutation', 'user_id', 'input', 'extra' );

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Fetch a page of records for a site.
	 *
	 * @return array{records:array,check:array,raw_check:array}|\WP_Error
	 */
	public function fetch( string $viewer_key, string $site = '', int $limit = 200 ) {
		$endpoint = (string) $this->settings->get( 'cf_endpoint', '' );
		if ( '' === $endpoint ) {
			return new \WP_Error( 'godmode_viewer_unprovisioned', 'The off-site audit log has not been provisioned yet.' );
		}
		if ( '' === trim( $viewer_key ) ) {
			return new \WP_Error( 'godmode_viewer_nokey', 'Enter the viewer key. It is not stored on this site by design.' );
		}
		if ( '' === $site ) {
			$site = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		}

		$url      = add_query_arg(
			array(
				'site'  => rawurlencode( $site ),
				'limit' => max( 1, min( 1000, $limit ) ),
			),
			trailingslashit( $endpoint ) . 'query'
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array( 'Authorization' => 'Bearer ' . trim( $viewer_key ) ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'godmode_viewer_http', 'Could not reach the off-site log: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 401 === $code ) {
			return new \WP_Error( 'godmode_viewer_denied', 'The off-site log refused that viewer key.' );
		}
		if ( $code < 200 || $code > 299 ) {
			return new \WP_Error( 'godmode_viewer_http', 'The off-site log answered with HTTP ' . $code . '.' );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['records'] ) ) {
			return new \WP_Error( 'godmode_viewer_parse', 'The off-site log returned something unreadable.' );
		}

		$records = (array) $data['records'];
		return array(
			'records'   => $records,
			'check'     => $this->verify( $records ),
			'raw_check' => (array) ( $data['check'] ?? array() ),
		);
	}

	// -----------------------------------------------------------------------
	// Section: The authoritative chain check.
	//
	// Three separate questions, because they fail for different reasons:
	//   missing         a sequence number absent between two records that exist
	//   broken_links    record N's prev_hash does not name record N-1's hash
	//   hash_mismatches a record's contents do not match its own hash
	//
	// Gaps are only counted between records that are present, so a log that
	// began mid-stream does not report its whole prehistory as missing.
	// -----------------------------------------------------------------------
	public function verify( array $records ): array {
		$usable = array();
		foreach ( $records as $record ) {
			if ( is_array( $record ) && isset( $record['seq'] ) && is_int( $record['seq'] ) ) {
				$usable[] = $record;
			}
		}
		usort(
			$usable,
			static function ( $a, $b ) {
				return $a['seq'] <=> $b['seq'];
			}
		);

		$missing    = array();
		$broken     = array();
		$mismatched = array();

		foreach ( $usable as $i => $record ) {
			if ( $i > 0 ) {
				$previous = $usable[ $i - 1 ];
				for ( $gap = $previous['seq'] + 1; $gap < $record['seq']; $gap++ ) {
					$missing[] = $gap;
					if ( count( $missing ) > 500 ) {
						break;
					}
				}
				if ( $record['seq'] === $previous['seq'] + 1
					&& ( $record['prev_hash'] ?? '' ) !== ( $previous['hash'] ?? '' ) ) {
					$broken[] = (int) $record['seq'];
				}
			}
			$recomputed = $this->recompute_hash( $record );
			if ( null !== $recomputed && $recomputed !== ( $record['hash'] ?? '' ) ) {
				$mismatched[] = (int) $record['seq'];
			}
		}

		return array(
			'ok'              => empty( $missing ) && empty( $broken ) && empty( $mismatched ),
			'count'           => count( $usable ),
			'first_seq'       => $usable ? (int) $usable[0]['seq'] : null,
			'last_seq'        => $usable ? (int) $usable[ count( $usable ) - 1 ]['seq'] : null,
			'missing'         => $missing,
			'broken_links'    => $broken,
			'hash_mismatches' => $mismatched,
		);
	}

	/**
	 * Rebuild the record in the exact field order the plugin hashed, then
	 * recompute. Any field the Worker added for its own bookkeeping (keys
	 * beginning with an underscore) is excluded, because it was not present
	 * when the hash was taken.
	 */
	private function recompute_hash( array $record ): ?string {
		$rebuilt = array();
		foreach ( self::FIELDS as $field ) {
			if ( ! array_key_exists( $field, $record ) ) {
				return null;
			}
			$rebuilt[ $field ] = $record[ $field ];
		}
		return hash( 'sha256', (string) ( $record['prev_hash'] ?? '' ) . wp_json_encode( $rebuilt ) );
	}
}
