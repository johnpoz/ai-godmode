<?php
/**
 * Cloudflare Worker + R2 sink. STUB in 0.1.0.
 *
 * The full design (see the KB "Decision And Scope Record") is: a Worker in
 * front of an R2 bucket, one small JSON POST per mutation, append-only keys
 * derived from the sequence number, conditional writes, bucket locks, a
 * separate viewer key that never touches the site. Provisioning happens from
 * a pasted scoped Cloudflare token that the plugin discards immediately.
 *
 * In 0.1.0 none of that is provisioned. This class exists so the audit
 * manager, the settings screen, and the fail-closed path all compile against
 * the real interface. is_configured() returns false until an endpoint and an
 * ingest key are stored, so the sink is skipped and only the local ring
 * buffer is the sink of record.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cloudflare_Sink implements I_Audit_Sink {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function id(): string {
		return 'cloudflare';
	}

	public function is_remote(): bool {
		return true;
	}

	public function is_configured(): bool {
		$endpoint = (string) $this->settings->get( 'cf_endpoint', '' );
		$key      = (string) $this->settings->get( 'cf_ingest_key', '' );
		return '' !== $endpoint && '' !== $key;
	}

	// -----------------------------------------------------------------------
	// Section: This sink's own chain.
	//
	// The off-site log receives mutations, heartbeats and lifecycle events, and
	// not ordinary reads. Numbering it separately from the local buffer is what
	// makes its sequence dense, and a dense sequence is what lets a gap mean
	// exactly one thing: a record that should be there is not.
	// -----------------------------------------------------------------------
	public function stamp( array $body ): array {
		$seq  = (int) $this->settings->get( 'cf_seq', 0 ) + 1;
		$prev = (string) $this->settings->get( 'cf_last_hash', Chain::ZERO_HASH );
		return Chain::stamp( $body, $seq, $prev );
	}

	private function advance( array $record ): void {
		$this->settings->set( 'cf_seq', (int) $record['seq'] );
		$this->settings->set( 'cf_last_hash', (string) $record['hash'] );
	}

	// -----------------------------------------------------------------------
	// Section: Send. Synchronous POST with a short timeout. Anything other
	// than a 2xx is a failure and the caller decides whether to proceed.
	// -----------------------------------------------------------------------
	public function send( array $record ) {
		if ( ! $this->is_configured() ) {
			return new \WP_Error( 'godmode_audit_sink_unconfigured', 'Cloudflare audit sink is not provisioned.' );
		}

		$result = $this->post( $record );

		// A 409 means the endpoint already holds a record at this sequence
		// number, which can only happen if a previous append succeeded and this
		// site failed to write down that it had. The counter is behind reality,
		// so move it on and try the next number once. Failing closed on the
		// first 409 would wedge the site permanently over a bookkeeping slip.
		if ( 'sequence_exists' === $result ) {
			$this->settings->set( 'cf_seq', (int) $record['seq'] );
			$record = $this->stamp( $record );
			$result = $this->post( $record );
			if ( 'sequence_exists' === $result ) {
				$message = 'The off-site log already holds records at this site\'s next two sequence numbers. Something else is writing to this log.';
				( new Health( $this->settings ) )->record_failure( $message );
				return new \WP_Error( 'godmode_audit_sink_failed', $message );
			}
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->advance( $record );
		return true;
	}

	/**
	 * One append attempt. Returns true, the string "sequence_exists", or a
	 * WP_Error. Health is recorded here because this is where the endpoint's
	 * behaviour is actually observed.
	 *
	 * @return true|string|\WP_Error
	 */
	private function post( array $record ) {
		$endpoint = (string) $this->settings->get( 'cf_endpoint', '' );
		$key      = (string) $this->settings->get( 'cf_ingest_key', '' );
		$response = wp_remote_post(
			trailingslashit( $endpoint ) . 'append',
			array(
				'timeout'  => 5,
				'blocking' => true,
				'headers'  => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				),
				'body'     => wp_json_encode( $record ),
			)
		);
		$health = new Health( $this->settings );

		if ( is_wp_error( $response ) ) {
			$message = 'The off-site log could not be reached: ' . $response->get_error_message();
			$health->record_failure( $message );
			return new \WP_Error( 'godmode_audit_sink_failed', $message );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 409 === $code ) {
			return 'sequence_exists';
		}
		if ( $code < 200 || $code > 299 ) {
			$message = 401 === $code
				? 'The off-site log rejected this site\'s ingest key (HTTP 401). The key it holds is no longer the one the endpoint expects, so this site is not registered on it any more.'
				: ( 403 === $code
					? 'The off-site log refused the record because it names a different site than the key belongs to (HTTP 403).'
					: 'The off-site log refused the record with HTTP ' . $code . '.' );
			$health->record_failure( $message );
			return new \WP_Error( 'godmode_audit_sink_failed', $message );
		}

		$health->record_success();
		return true;
	}
}
