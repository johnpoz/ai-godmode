<?php
/**
 * Audit manager.
 *
 * Log intent before execution. Core fires wp_before_execute_ability after
 * input validation and the permission check and immediately before the
 * callback, so that is where the intent record is written. It is an action
 * (observational, cannot block), so the refusal for mutations happens inside
 * the registrar's execute wrapper, which asks this class whether the intent
 * record for the current request landed in every configured sink.
 *
 * Record shape:
 *   seq, prev_hash, hash, time, site, event, ability, mutation, user_id,
 *   input (redacted and capped), extra
 *
 * Hash chain: hash = sha256( prev_hash . canonical_json(record_without_hash) ).
 * Gaps and edits are detectable after the fact.
 *
 * Each sink keeps its own sequence numbers and its own chain. The local buffer
 * receives every execution including reads; the off-site log receives
 * mutations, heartbeats and lifecycle events. Sharing one counter between them
 * put permanent holes in the off-site sequence during entirely ordinary use,
 * which is exactly what the off-site gap alarm is meant to report, so the alarm
 * could not be trusted. See Chain.
 *
 * Cost control: reads go to local sinks only; mutations go to every
 * configured sink including remote ones.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Audit {

	private Settings $settings;

	/** @var array<string, true|\WP_Error> Result of the intent write per ability for this request. */
	private array $intent_results = array();

	/** @var array<string, int> Seq of the intent record per ability for this request. */
	private array $intent_seq = array();

	/** @var array<string, bool> Ability name => is a mutation. Filled by the registrar. */
	private array $mutation_map = array();

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function hooks(): void {
		add_action( 'wp_before_execute_ability', array( $this, 'on_before_execute' ), 10, 2 );
		add_action( 'wp_after_execute_ability', array( $this, 'on_after_execute' ), 10, 3 );
	}

	public function declare_mutation( string $ability, bool $is_mutation ): void {
		$this->mutation_map[ $ability ] = $is_mutation;
	}

	// -----------------------------------------------------------------------
	// Section: Sinks. Local ring buffer always; Cloudflare when provisioned.
	// The godmode_audit_sinks filter lets tests (and later, other backends)
	// swap or add sinks.
	// -----------------------------------------------------------------------
	public function sinks(): array {
		$sinks = array(
			new Ring_Buffer_Sink( (int) $this->settings->get( 'ring_buffer_max', 200 ) ),
			new Cloudflare_Sink( $this->settings ),
		);
		$sinks = apply_filters( 'godmode_audit_sinks', $sinks, $this->settings );
		return array_values(
			array_filter(
				(array) $sinks,
				static function ( $s ) {
					return $s instanceof I_Audit_Sink;
				}
			)
		);
	}

	public function local_sink(): Ring_Buffer_Sink {
		foreach ( $this->sinks() as $sink ) {
			if ( $sink instanceof Ring_Buffer_Sink ) {
				return $sink;
			}
		}
		return new Ring_Buffer_Sink( (int) $this->settings->get( 'ring_buffer_max', 200 ) );
	}

	/** Human readable sink status for the settings screen. */
	public function sink_status(): array {
		$out = array();
		foreach ( $this->sinks() as $sink ) {
			$out[] = array(
				'id'         => $sink->id(),
				'remote'     => $sink->is_remote(),
				'configured' => $sink->is_configured(),
			);
		}
		return $out;
	}

	// -----------------------------------------------------------------------
	// Section: Core hooks.
	// -----------------------------------------------------------------------
	public function on_before_execute( string $ability, $input ): void {
		if ( ! $this->is_ours( $ability ) ) {
			return;
		}
		$is_mutation = ! empty( $this->mutation_map[ $ability ] );
		$result      = $this->write( 'intent', $ability, $is_mutation, $input );
		$this->intent_results[ $ability ] = $result['status'];
		$this->intent_seq[ $ability ]     = $result['seq'];
	}

	public function on_after_execute( string $ability, $input = null, $output = null ): void {
		if ( ! $this->is_ours( $ability ) ) {
			return;
		}
		$is_mutation = ! empty( $this->mutation_map[ $ability ] );
		$this->write(
			'completed',
			$ability,
			$is_mutation,
			null,
			array(
				'intent_seq' => $this->intent_seq[ $ability ] ?? null,
				'ok'         => ! is_wp_error( $output ),
			)
		);
	}

	/**
	 * Asked by the registrar's execute wrapper for mutations. Returns true
	 * when the intent record landed everywhere it had to, or the WP_Error
	 * explaining which sink failed.
	 *
	 * @return true|\WP_Error
	 */
	public function intent_result( string $ability ) {
		if ( ! array_key_exists( $ability, $this->intent_results ) ) {
			return new \WP_Error( 'godmode_audit_missing', 'No audit intent record was written for this execution.' );
		}
		return $this->intent_results[ $ability ];
	}

	/** Mutation callback returned WP_Error. Best effort, local only. */
	public function write_failure( string $ability, \WP_Error $error ): void {
		$this->write(
			'failed',
			$ability,
			true,
			null,
			array(
				'intent_seq' => $this->intent_seq[ $ability ] ?? null,
				'code'       => $error->get_error_code(),
				'message'    => $error->get_error_message(),
			),
			true
		);
	}

	public function intent_seq( string $ability ): ?int {
		return isset( $this->intent_seq[ $ability ] ) ? (int) $this->intent_seq[ $ability ] : null;
	}

	/** Called when a caller proceeds with acknowledge_unlogged after a sink failure. Best effort. */
	public function write_gap_marker( string $ability, $reason ): void {
		$this->write(
			'gap',
			$ability,
			true,
			null,
			array(
				'reason'    => is_wp_error( $reason ) ? $reason->get_error_message() : (string) $reason,
				'ack'       => true,
			),
			true
		);
	}

	/** Plugin lifecycle and admin events (activation, arm, disarm, toggles). */
	public function write_event( string $event, array $extra = array() ): void {
		$this->write( $event, '', false, null, $extra, true );
	}

	/**
	 * Periodic beacon, pushed off-site even though nothing was mutated.
	 *
	 * Without it a quiet site and a deleted logger look exactly the same from
	 * the outside. With it, silence is unambiguous: if the heartbeats stop, the
	 * site stopped talking, and that is itself the finding.
	 *
	 * @return true|\WP_Error
	 */
	public function write_heartbeat( array $extra = array() ) {
		$result = $this->write(
			'heartbeat',
			'',
			false,
			null,
			array_merge( array( 'version' => defined( 'GODMODE_VERSION' ) ? GODMODE_VERSION : '' ), $extra ),
			false,
			true
		);
		return $result['status'];
	}

	// -----------------------------------------------------------------------
	// Section: Record building and fan-out.
	//
	// Returns ['status' => true|WP_Error, 'seq' => int]. Status is true only
	// when every sink that had to accept the record did so. Local sinks always
	// have to; remote sinks only for mutations (or when $force_all).
	// -----------------------------------------------------------------------
	private function write( string $event, string $ability, bool $is_mutation, $input = null, array $extra = array(), bool $local_only = false, bool $force_remote = false ): array {
		$body = array(
			'time'     => gmdate( 'c' ),
			'site'     => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
			'event'    => $event,
			'ability'  => $ability,
			'mutation' => $is_mutation,
			'user_id'  => (int) get_current_user_id(),
			'input'    => null === $input ? null : Redactor::redact( $input ),
			'extra'    => $extra,
		);

		$status    = true;
		$local_seq = 0;

		foreach ( $this->sinks() as $sink ) {
			if ( ! $sink->is_configured() ) {
				continue;
			}
			if ( $sink->is_remote() && ! $force_remote && ( ! $is_mutation || $local_only ) ) {
				continue;
			}

			// Each sink numbers and chains the record itself, so the sequence
			// in one log never depends on what another log did or did not
			// receive. The seq reported back to the caller is the local one,
			// because that is the number the admin screen shows.
			$record = $sink->stamp( $body );
			if ( ! $sink->is_remote() ) {
				$local_seq = (int) $record['seq'];
			}

			$result = $sink->send( $record );
			if ( is_wp_error( $result ) && true === $status ) {
				$status = $result;
			}
		}

		return array(
			'status' => $status,
			'seq'    => $local_seq,
		);
	}

	private function is_ours( string $ability ): bool {
		return 0 === strpos( $ability, GODMODE_NAMESPACE . '/' );
	}
}
