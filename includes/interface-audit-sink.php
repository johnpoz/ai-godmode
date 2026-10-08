<?php
/**
 * Audit sink contract. A sink receives one audit record at a time and must
 * either persist it synchronously or return a WP_Error. The audit manager
 * treats any WP_Error from a configured sink as "the log is unavailable" and
 * refuses mutations unless the caller passes acknowledge_unlogged.
 *
 * Shipped implementations: Ring_Buffer_Sink (local, always configured) and
 * Cloudflare_Sink (remote, stub in 0.1.0, not configured until provisioned).
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface I_Audit_Sink {

	/** Short machine id, e.g. "local" or "cloudflare". */
	public function id(): string;

	/** True when the sink has what it needs to accept records. */
	public function is_configured(): bool;

	/** True when this sink is remote (off-site). Reads skip remote sinks. */
	public function is_remote(): bool;

	/**
	 * Turn the shared record body into this sink's own record.
	 *
	 * Every sink keeps its own sequence numbers and its own hash chain, so the
	 * same event becomes a different record in each log. The audit manager
	 * builds the body once and asks each configured sink to stamp it.
	 *
	 * @param array $body Shared fields: time, site, event, ability, mutation,
	 *                    user_id, input, extra.
	 * @return array Full record including seq, prev_hash and hash.
	 */
	public function stamp( array $body ): array;

	/**
	 * Persist one record. Must be synchronous: return only after the record
	 * is durable. Return true on success or WP_Error on failure.
	 *
	 * A sink advances its own chain state only when this call succeeds. A
	 * number consumed by a record that never landed is a permanent hole in the
	 * log, and a hole is indistinguishable from a deletion.
	 *
	 * @param array $record Fully built audit record including seq and hash.
	 * @return true|\WP_Error
	 */
	public function send( array $record );
}
