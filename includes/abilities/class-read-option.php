<?php
/**
 * godmode/read-option. Read one wp_options row. The secrets denylist is
 * applied: a denied option name returns a crafted error and never a value.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

use AIGodmode\Redactor;

final class Read_Option extends Base {

	public static function name(): string {
		return 'godmode/read-option';
	}

	public static function definition(): array {
		return array(
			'family'        => 'options',
			'label'         => 'Read Option',
			'description'   => 'Read one option from wp_options by name. Secret and salt options are refused by the denylist; inside any other value, entries under secret-looking keys (password, token, *_secret, *_key, login_id, license) are masked. Read only.',
			'input_schema'  => array(
				'type'                 => 'object',
				'properties'           => array(
					'option' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 191,
					),
				),
				'required'             => array( 'option' ),
				'additionalProperties' => false,
			),
			'output_schema' => array(
				'type'                 => 'object',
				'properties'           => array(
					'option'   => array( 'type' => 'string' ),
					'exists'   => array( 'type' => 'boolean' ),
					'value'    => self::any(),
					'autoload' => array( 'type' => 'string' ),
				),
				'required'             => array( 'option', 'exists', 'value' ),
				'additionalProperties' => false,
			),
			'annotations'   => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mutation'      => false,
			'capability'    => 'manage_options',
		);
	}

	public function execute( $input ) {
		global $wpdb;
		$name = isset( $input['option'] ) ? (string) $input['option'] : '';
		if ( Redactor::is_denied_key( $name ) ) {
			return new \WP_Error(
				'godmode_option_denied',
				sprintf( 'Option "%s" is on the secrets denylist and cannot be read through AI Godmode.', $name ),
				array( 'status' => 403 )
			);
		}
		// Direct row lookup so "exists" is accurate even when the value is falsy.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the raw stored value and autoload flag are what the administrator asked to see; get_option() would filter and cache it.
		if ( null === $row ) {
			return array(
				'option' => $name,
				'exists' => false,
				'value'  => null,
			);
		}
		$value = maybe_unserialize( $row['option_value'] );
		if ( is_object( $value ) ) {
			$value = json_decode( wp_json_encode( $value ), true );
		}
		// The name passed the denylist; the value may still carry secrets
		// under keys of its own (a gateway's api_transaction_key, say).
		return array(
			'option'   => $name,
			'exists'   => true,
			'value'    => Redactor::mask( $value ),
			'autoload' => (string) $row['autoload'],
		);
	}
}
