<?php
/**
 * run-php: execute arbitrary PHP as the admin request that called it.
 *
 * This is the literal meaning of Godmode and the one ability that subsumes
 * every other. It ships off like all of them, is audit logged (the code is
 * captured, truncated and stored in the intent record), and refuses when the
 * audit log cannot record it unless acknowledge_unlogged is passed.
 *
 * The code runs in a function scope with $input available. Whatever it
 * returns becomes the "returned" field; anything it echoes becomes "output".
 * A thrown Throwable or a parse error is caught and reported, not fatal.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Run_Php extends Base {
	public const MAX_OUTPUT = 100000;

	public static function name(): string {
		return 'godmode/run-php';
	}

	public static function definition(): array {
		return self::make(
			'run-php',
			'Run PHP',
			'Execute PHP code as an administrator inside WordPress. The code runs in a function scope; return a value to get it back as "returned", echo to get "output". Errors are caught and reported. This is total control of the site: audit logged, and refused when the log is unavailable unless acknowledge_unlogged is set. Do not paste code you have not read.',
			self::obj(
				array(
					'code' => self::str( 200000, 1 ),
				) + self::ack(),
				array( 'code' )
			),
			self::obj(
				array(
					'ok'       => self::bool(),
					'returned' => self::any(),
					'output'   => self::str(),
					'error'    => self::nullable( self::str() ),
					'seconds'  => array( 'type' => 'number' ),
				),
				array( 'ok', 'output', 'seconds' )
			),
			true,
			false
		);
	}

	public function execute( $input ) {
		$code = (string) $input['code'];
		// Strip a leading <?php so both forms work.
		$code = preg_replace( '/^\s*<\?php\s+/', '', $code );
		$code = preg_replace( '/\?>\s*$/', '', (string) $code );

		$runner = static function ( $input ) use ( $code ) {
			return eval( $code ); // phpcs:ignore Squiz.PHP.Eval.Discouraged, Generic.PHP.ForbiddenFunctions.Found -- run-php exists to execute the PHP an administrator sends, behind the master switch, its own switch, manage_options and the audit log, which records the code before it runs.
		};

		$start = microtime( true );
		ob_start();
		try {
			$returned = $runner( $input );
			$output   = (string) ob_get_clean();
			$error    = null;
			$ok       = true;
		} catch ( \Throwable $e ) {
			$output   = (string) ob_get_clean();
			$returned = null;
			$error    = get_class( $e ) . ': ' . $e->getMessage();
			$ok       = false;
		}
		$seconds = round( microtime( true ) - $start, 3 );

		if ( is_object( $returned ) ) {
			$returned = json_decode( wp_json_encode( $returned ), true );
		}
		if ( strlen( $output ) > self::MAX_OUTPUT ) {
			$output = substr( $output, 0, self::MAX_OUTPUT ) . "\n...[truncated, " . strlen( $output ) . " bytes]";
		}
		return array( 'ok' => $ok, 'returned' => $returned, 'output' => $output, 'error' => $error, 'seconds' => $seconds );
	}
}
