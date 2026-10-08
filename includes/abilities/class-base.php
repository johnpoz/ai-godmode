<?php
/**
 * Base class for every Godmode ability.
 *
 * An ability is a static definition (name, label, description, schemas,
 * annotations, mutation flag, capability, family) plus an execute() method.
 * The definition is pure data with no WordPress calls so the schema check
 * shim can load it outside WordPress. Keep each definition under about 2 KB
 * of JSON; prose belongs in godmode/get-reference and the KB, not in schemas.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

abstract class Base {

	/** Namespaced ability name, e.g. "godmode/get-environment". */
	abstract public static function name(): string;

	/**
	 * Definition array:
	 *  label, description   strings
	 *  input_schema         JSON Schema array (or null for no input)
	 *  output_schema        JSON Schema array
	 *  annotations          array: readonly, destructive, idempotent (bools)
	 *  mutation             bool, true when execution changes site state
	 *  capability           WordPress capability required (default manage_options)
	 *  family               short group name for the settings screen
	 */
	abstract public static function definition(): array;

	/**
	 * Run the ability. Input has already passed schema validation, the
	 * permission gate, and (for mutations) the audit intent gate.
	 *
	 * @param mixed $input Validated input.
	 * @return mixed|\WP_Error
	 */
	abstract public function execute( $input );

	public static function is_mutation(): bool {
		$def = static::definition();
		return ! empty( $def['mutation'] );
	}

	public static function capability(): string {
		$def = static::definition();
		return isset( $def['capability'] ) && is_string( $def['capability'] ) ? $def['capability'] : 'manage_options';
	}

	public static function family(): string {
		$def = static::definition();
		return isset( $def['family'] ) && is_string( $def['family'] ) ? $def['family'] : 'core';
	}

	// -----------------------------------------------------------------------
	// Section: Definition helpers. Keep definitions short and uniform.
	// -----------------------------------------------------------------------

	/** Build a full definition. $in null means "no input". */
	protected static function make( string $family, string $label, string $description, ?array $in, array $out, bool $mutation, bool $idempotent = true ): array {
		return array(
			'family'        => $family,
			'label'         => $label,
			'description'   => $description,
			'input_schema'  => $in,
			'output_schema' => $out,
			'annotations'   => array(
				'readonly'    => ! $mutation,
				'destructive' => $mutation,
				'idempotent'  => $idempotent,
			),
			'mutation'      => $mutation,
			'capability'    => 'manage_options',
		);
	}

	/** Object schema with closed property set. */
	protected static function obj( array $props, array $required = array(), bool $closed = true ): array {
		$s = array( 'type' => 'object' );
		if ( $props ) {
			$s['properties'] = $props;
		}
		if ( $required ) {
			$s['required'] = $required;
		} else {
			// Core substitutes the schema default when the caller passes no input,
			// so an all-optional input object accepts a bare call.
			$s['default'] = array();
		}
		if ( $closed ) {
			$s['additionalProperties'] = false;
		}
		return $s;
	}

	protected static function str( int $max = 0, int $min = 0 ): array {
		$s = array( 'type' => 'string' );
		if ( $min > 0 ) {
			$s['minLength'] = $min;
		}
		if ( $max > 0 ) {
			$s['maxLength'] = $max;
		}
		return $s;
	}

	protected static function int( int $min = 0, int $max = 0, $default = null ): array {
		$s = array( 'type' => 'integer', 'minimum' => $min );
		if ( $max > 0 ) {
			$s['maximum'] = $max;
		}
		if ( null !== $default ) {
			$s['default'] = $default;
		}
		return $s;
	}

	protected static function bool( $default = null ): array {
		$s = array( 'type' => 'boolean' );
		if ( null !== $default ) {
			$s['default'] = $default;
		}
		return $s;
	}

	/**
	 * A value of one type, or null.
	 *
	 * This exists for the same reason as any() below, and the reason is worth
	 * keeping. A plain union, array( 'type' => array( 'string', 'null' ) ), is
	 * correct JSON Schema and is what this used to emit. Easy MCP rewrites it
	 * on the way out through Gemini_Safe_Schema::sanitize() into
	 * array( 'type' => 'string', 'nullable' => true ). "nullable" is an
	 * OpenAPI 3.0 keyword, not a JSON Schema one, and MCP output schemas are
	 * JSON Schema, so a validating client ignores it as an unknown annotation
	 * and reads the field as a plain string. Every null we send then fails
	 * validation and the whole tool result is rejected, on the client, after
	 * the site has already done the work.
	 *
	 * run-php was hit hardest because it returns error: null on every single
	 * success, so it failed one hundred per cent of the time in a strict
	 * client while looking fine in a lenient one.
	 *
	 * anyOf survives that transform untouched, and WordPress core resolves it
	 * before its own type check, so it satisfies both validators.
	 *
	 * @param array $type The non-null member, e.g. self::str() or self::int().
	 */
	protected static function nullable( array $type ): array {
		return array(
			'anyOf' => array(
				$type,
				array( 'type' => 'null' ),
			),
		);
	}

	/** Any JSON value. */
	protected static function any(): array {
		// A free-form JSON value has to satisfy two validators at once, and a
		// plain union type satisfies neither. WordPress core's
		// rest_validate_value_from_schema warns when a property has no "type"
		// keyword, so a bare typeless schema is out. Easy MCP's Gemini-safe
		// transform rewrites a "type" ARRAY to its first non-null member, which
		// silently turns "any JSON value" into "string" and then rejects every
		// non-string result. anyOf threads both: core resolves it before the
		// type check and adopts the matching member's type, and the Gemini-safe
		// walk recurses into anyOf members without collapsing the combinator.
		// Two-member unions such as array( 'integer', 'null' ) stay safe as
		// plain types because they survive as the type plus nullable.
		return array(
			'description' => 'Any JSON value.',
			'anyOf'       => array(
				array( 'type' => 'string' ),
				array( 'type' => 'number' ),
				array( 'type' => 'boolean' ),
				array( 'type' => 'array' ),
				array( 'type' => 'object' ),
				array( 'type' => 'null' ),
			),
		);
	}

	protected static function list_of( array $items ): array {
		return array( 'type' => 'array', 'items' => $items );
	}

	/** Open object (free-form keys). */
	protected static function map(): array {
		return array( 'type' => 'object' );
	}

	/** The acknowledge_unlogged input property every mutation accepts. */
	protected static function ack(): array {
		return array( 'acknowledge_unlogged' => self::bool( false ) );
	}

	/** Crafted error helper. */
	protected static function err( string $code, string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
