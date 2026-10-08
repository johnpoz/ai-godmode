<?php
/**
 * Off-site log health.
 *
 * This class exists because of a real defect. The first version decided the
 * log was "Live" by calling /health on the Worker, which is unauthenticated
 * and answers 200 whatever state the keys are in. So when the ingest key was
 * deliberately invalidated, the settings screen cheerfully reported that
 * mutations were being logged off-site while every single one of them was in
 * fact being refused. A status light that cannot go red is worse than no
 * status light, because it actively lies.
 *
 * The check now calls /ping, which validates the ingest key and writes
 * nothing. Two sources feed the verdict:
 *
 *  1. The live probe, cached briefly so wp-admin stays fast.
 *  2. The last real result recorded by the sink itself. A mutation that was
 *     actually refused is stronger evidence than any probe, and it is recorded
 *     the instant it happens.
 *
 * The recorded state lives in the plugin's own settings option, which is on
 * the secrets denylist, so the option abilities cannot be used to quietly
 * clear an alarm.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Health {

	public const STATE_LIVE         = 'live';
	public const STATE_FAILING      = 'failing';
	public const STATE_UNCONFIGURED = 'unconfigured';

	private const CACHE_KEY = 'godmode_sink_health';
	private const CACHE_TTL = 300;

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function is_configured(): bool {
		return '' !== (string) $this->settings->get( 'cf_endpoint', '' )
			&& '' !== (string) $this->settings->get( 'cf_ingest_key', '' );
	}

	// -----------------------------------------------------------------------
	// Section: Recording what actually happened.
	//
	// Called by the sink on every send. These are facts, not probes, and they
	// take precedence over anything the cached probe says.
	// -----------------------------------------------------------------------
	public function record_failure( string $message ): void {
		$this->settings->set( 'cf_last_error', $this->tidy( $message ) );
		$this->settings->set( 'cf_last_error_utc', gmdate( 'c' ) );
		delete_transient( self::CACHE_KEY );
	}

	public function record_success(): void {
		if ( '' !== (string) $this->settings->get( 'cf_last_error', '' ) ) {
			$this->settings->set( 'cf_last_error', '' );
			$this->settings->set( 'cf_last_error_utc', '' );
			delete_transient( self::CACHE_KEY );
		}
		// Throttled: a busy site should not write an option on every mutation.
		$last = (string) $this->settings->get( 'cf_last_ok_utc', '' );
		if ( '' === $last || ( time() - (int) strtotime( $last ) ) > 300 ) {
			$this->settings->set( 'cf_last_ok_utc', gmdate( 'c' ) );
		}
	}

	/**
	 * Current verdict.
	 *
	 * @param bool $force Skip the cache and probe now.
	 * @return array{state:string,headline:string,detail:string,since:string}
	 */
	public function status( bool $force = false ): array {
		if ( ! $this->is_configured() ) {
			return array(
				'state'    => self::STATE_UNCONFIGURED,
				'headline' => __( 'No off-site log. The only record is on this server.', 'ai-godmode' ),
				'detail'   => __( 'Anything this plugin can do, it can also cover up, because the audit trail lives in a database it controls. Setting up an off-site log is what closes that hole.', 'ai-godmode' ),
				'since'    => '',
			);
		}

		// The probe decides whether it is working NOW; the recorded failure
		// explains why when it is not.
		//
		// An earlier version returned early on a recorded failure without ever
		// probing again, which meant the alarm could not clear itself even
		// after the problem was fixed. Only a successful mutation could clear
		// it, and mutations were exactly what the alarm was blocking. Fixing
		// the log therefore left the alarm on forever.
		$recorded = (string) $this->settings->get( 'cf_last_error', '' );

		$probe = $force ? false : get_transient( self::CACHE_KEY );
		if ( false === $probe ) {
			$probe = $this->probe();
			set_transient( self::CACHE_KEY, $probe, self::CACHE_TTL );
		}

		if ( true === ( $probe['ok'] ?? false ) ) {
			// Working again. Retire the recorded failure so the alarm clears.
			if ( '' !== $recorded ) {
				$this->record_success();
			}
			return array(
				'state'    => self::STATE_LIVE,
				'headline' => __( 'Live. Every change is written off-site before it runs.', 'ai-godmode' ),
				'detail'   => __( 'The endpoint answered and accepted this site\'s ingest key.', 'ai-godmode' ),
				'since'    => (string) $this->settings->get( 'cf_last_ok_utc', '' ),
			);
		}

		return array(
			'state'    => self::STATE_FAILING,
			'headline' => __( 'The off-site log is NOT recording. Every change is being refused.', 'ai-godmode' ),
			'detail'   => '' !== $recorded
				? $recorded
				: (string) ( $probe['detail'] ?? __( 'The endpoint did not answer.', 'ai-godmode' ) ),
			'since'    => (string) $this->settings->get( 'cf_last_error_utc', '' ),
		);
	}

	/**
	 * The probe. /ping validates the ingest key and writes nothing, so this
	 * proves the credential works rather than merely that a server is up.
	 */
	private function probe(): array {
		$endpoint = (string) $this->settings->get( 'cf_endpoint', '' );
		$key      = (string) $this->settings->get( 'cf_ingest_key', '' );

		$response = wp_remote_post(
			trailingslashit( $endpoint ) . 'ping',
			array(
				'timeout' => 8,
				'headers' => array( 'Authorization' => 'Bearer ' . $key ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'     => false,
				'detail' => __( 'Could not reach the endpoint: ', 'ai-godmode' ) . $this->tidy( $response->get_error_message() ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $code ) {
			return array( 'ok' => true, 'detail' => '' );
		}
		if ( 401 === $code ) {
			return array(
				'ok'     => false,
				'detail' => __( 'The endpoint rejected this site\'s ingest key (HTTP 401). The endpoint no longer recognises the key this site holds, which means this site\'s entry in its key registry was changed or removed. Setting up or rotating another site cannot cause this.', 'ai-godmode' ),
			);
		}
		return array(
			'ok'     => false,
			/* translators: %d: HTTP status code */
			'detail' => sprintf( __( 'The endpoint answered with HTTP %d.', 'ai-godmode' ), $code ),
		);
	}

	/** Keep stored messages short and free of anything key-shaped. */
	private function tidy( string $message ): string {
		$message = wp_strip_all_tags( $message );
		$message = preg_replace( '/[0-9a-f]{32,}/i', '[redacted]', $message );
		return mb_substr( trim( (string) $message ), 0, 300 );
	}
}
