<?php
/**
 * Hash chain stamping.
 *
 * A record's identity is its position in one log. Two different logs holding
 * the same event are two different records, because each carries the sequence
 * number and the previous hash of the log it belongs to.
 *
 * That sounds obvious and it was got wrong. Until 0.5.0 one sequence counter
 * was shared by the local ring buffer and the off-site log, while only
 * mutations were sent off-site. Every read therefore consumed a number that
 * never arrived off-site, and the off-site chain checker, whose whole job is
 * to shout when a record is missing, shouted constantly on a perfectly healthy
 * site. An alarm that fires during ordinary use is an alarm nobody reads.
 *
 * So each sink stamps its own record now. The body is shared, the numbering is
 * not.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Chain {

	public const ZERO_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

	/**
	 * Build the full record for one log from the shared body.
	 *
	 * Field order matters and is load bearing: the hash is taken over
	 * wp_json_encode of the record without its own hash, and both the Worker
	 * and the admin viewer recompute it by rebuilding the fields in exactly
	 * this order. Reordering them here silently invalidates every future
	 * verification.
	 */
	public static function stamp( array $body, int $seq, string $prev_hash ): array {
		$prev_hash = self::is_hash( $prev_hash ) ? $prev_hash : self::ZERO_HASH;

		$record = array(
			'seq'       => $seq,
			'prev_hash' => $prev_hash,
			'time'      => (string) ( $body['time'] ?? gmdate( 'c' ) ),
			'site'      => (string) ( $body['site'] ?? '' ),
			'event'     => (string) ( $body['event'] ?? '' ),
			'ability'   => (string) ( $body['ability'] ?? '' ),
			'mutation'  => (bool) ( $body['mutation'] ?? false ),
			'user_id'   => (int) ( $body['user_id'] ?? 0 ),
			'input'     => $body['input'] ?? null,
			'extra'     => isset( $body['extra'] ) && is_array( $body['extra'] ) ? $body['extra'] : array(),
		);

		$record['hash'] = hash( 'sha256', $prev_hash . wp_json_encode( $record ) );
		return $record;
	}

	public static function is_hash( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{64}$/', $value );
	}
}
