<?php
/**
 * Local ring buffer sink. Keeps the newest N audit records in one non-autoloaded
 * option (godmode_audit_log) and a monotonic sequence counter in another
 * (godmode_audit_seq). Reads and writes both land here. This is the local
 * record; it is not tamper-proof and the design says so. The off-site sink is
 * what makes the record survive a catastrophic mistake.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Ring_Buffer_Sink implements I_Audit_Sink {

	public const OPT_LOG = 'godmode_audit_log';
	public const OPT_SEQ = 'godmode_audit_seq';

	private int $max;

	public function __construct( int $max = 200 ) {
		$this->max = max( 10, $max );
	}

	public function id(): string {
		return 'local';
	}

	public function is_configured(): bool {
		return true;
	}

	public function is_remote(): bool {
		return false;
	}

	// -----------------------------------------------------------------------
	// Section: Sequence and chain state. The sequence is a separate option so
	// it keeps climbing even after old records fall out of the buffer.
	// -----------------------------------------------------------------------
	public function next_seq(): int {
		$seq = (int) get_option( self::OPT_SEQ, 0 );
		return $seq + 1;
	}

	/** This sink's own numbering. Reads and writes both land here, so its
	 *  sequence climbs faster than the off-site one, which is correct: they
	 *  are two different logs. */
	public function stamp( array $body ): array {
		return Chain::stamp( $body, $this->next_seq(), $this->last_hash() );
	}

	public function last_hash(): string {
		$log = $this->all();
		if ( empty( $log ) ) {
			return str_repeat( '0', 64 );
		}
		$last = end( $log );
		return isset( $last['hash'] ) ? (string) $last['hash'] : str_repeat( '0', 64 );
	}

	public function all(): array {
		$log = get_option( self::OPT_LOG, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	// -----------------------------------------------------------------------
	// Section: Send. Append, trim, persist, then read back and confirm the
	// record is really there. A write that cannot be confirmed is a failure.
	// -----------------------------------------------------------------------
	public function send( array $record ) {
		if ( apply_filters( 'godmode_audit_force_local_failure', false, $record ) ) {
			return new \WP_Error( 'godmode_audit_local_failed', 'Local audit buffer write was forced to fail (test filter).' );
		}
		$log   = $this->all();
		$log[] = $record;
		if ( count( $log ) > $this->max ) {
			$log = array_slice( $log, -1 * $this->max );
		}
		update_option( self::OPT_LOG, $log, false );
		update_option( self::OPT_SEQ, (int) $record['seq'], false );

		// Confirm durability by reading back from the database, bypassing cache.
		wp_cache_delete( self::OPT_LOG, 'options' );
		$check = $this->all();
		$tail  = end( $check );
		if ( ! is_array( $tail ) || (int) ( $tail['seq'] ?? -1 ) !== (int) $record['seq'] ) {
			return new \WP_Error( 'godmode_audit_local_failed', 'Local audit buffer write could not be confirmed.' );
		}
		return true;
	}

	public function clear(): void {
		delete_option( self::OPT_LOG );
	}
}
