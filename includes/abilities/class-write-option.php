<?php
/**
 * godmode/write-option. Mutation. Writes one wp_options row.
 *
 * Audit-logged (intent before execution, completion after). Refuses when the
 * audit log is unavailable unless acknowledge_unlogged is true; that gate is
 * enforced by the registrar wrapper before this class runs. The denylist
 * applies to writes too: secrets never traverse the channel in either
 * direction. Returns the previous value so the caller can revert.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

use AIGodmode\Redactor;

final class Write_Option extends Base {

	public static function name(): string {
		return 'godmode/write-option';
	}

	public static function definition(): array {
		return array(
			'family'        => 'options',
			'label'         => 'Write Option',
			'description'   => 'Create or update one option in wp_options. Audit logged before execution. Refuses denylisted secret names. Returns the previous value for revert.',
			'input_schema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'option'              => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 191,
					),
					'value'               => self::any(),
					'autoload'            => array(
						'type' => 'boolean',
					),
					'acknowledge_unlogged' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'required'             => array( 'option', 'value' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'option'         => array( 'type' => 'string' ),
					'changed'        => array( 'type' => 'boolean' ),
					'existed'        => array( 'type' => 'boolean' ),
					'previous_value' => self::any(),
					'value'          => self::any(),
					'audit_seq'      => self::nullable( array( 'type' => 'integer' ) ),
				),
				'required'             => array( 'option', 'changed', 'existed', 'previous_value', 'value' ),
				'additionalProperties' => false,
			),
			'annotations'   => array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => true,
			),
			'mutation'      => true,
			'capability'    => 'manage_options',
		);
	}

	public function execute( $input ) {
		global $wpdb;
		$name = isset( $input['option'] ) ? (string) $input['option'] : '';
		if ( Redactor::is_denied_key( $name ) ) {
			return new \WP_Error(
				'godmode_option_denied',
				sprintf( 'Option "%s" is on the secrets denylist and cannot be written through AI Godmode.', $name ),
				array( 'status' => 403 )
			);
		}
		$value = $input['value'];

		// Snapshot the previous state directly so "existed" is exact.
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the raw previous value is returned for revert; get_option() would filter and cache it.
		$existed = null !== $row;
		$prev    = $existed ? maybe_unserialize( $row['option_value'] ) : null;
		if ( is_object( $prev ) ) {
			$prev = json_decode( wp_json_encode( $prev ), true );
		}

		// A value read through read-option comes back masked. Put the stored
		// secrets back where the caller left the mask untouched, and refuse a
		// marker that has nothing to stand for rather than store it as text.
		$value = Redactor::unmask( $value, $prev );
		if ( Redactor::has_marker( $value ) ) {
			return new \WP_Error(
				'godmode_write_redacted',
				sprintf( 'The value for "%s" still contains a redaction marker with no stored secret behind it. Supply the real value, or leave that key out.', $name ),
				array( 'status' => 400 )
			);
		}

		// Single-statement write. update_option() adds when missing.
		$autoload = array_key_exists( 'autoload', $input ) ? (bool) $input['autoload'] : null;
		$result   = $existed ? update_option( $name, $value, $autoload ) : add_option( $name, $value, '', null === $autoload ? true : $autoload );

		// Verify by reading back, bypassing the object cache.
		wp_cache_delete( $name, 'options' );
		$after = get_option( $name, null );
		$same  = ( maybe_serialize( $after ) === maybe_serialize( $value ) );
		if ( ! $same ) {
			return new \WP_Error(
				'godmode_write_unverified',
				sprintf( 'Wrote option "%s" but the read-back value does not match. Nothing else was changed.', $name ),
				array( 'status' => 500 )
			);
		}
		return array(
			'option'         => $name,
			'changed'        => (bool) $result,
			'existed'        => $existed,
			'previous_value' => Redactor::mask( $prev ),
			'value'          => Redactor::mask( $value ),
			'audit_seq'      => null, // Filled in by the registrar wrapper.
		);
	}
}
