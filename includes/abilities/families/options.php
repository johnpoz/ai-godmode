<?php
/**
 * Options family: list-options, delete-option, transient-get, transient-set,
 * transient-delete. read-option and write-option live in their own files.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

use AIGodmode\Redactor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class List_Options extends Base {
	public static function name(): string {
		return 'godmode/list-options';
	}
	public static function definition(): array {
		return self::make(
			'options',
			'List Options',
			'List wp_options rows by name prefix or substring. Returns names, autoload flag and value length, never values. Denylisted names are marked.',
			self::obj(
				array(
					'search'   => self::str( 191 ),
					'prefix'   => self::str( 191 ),
					'autoload' => array( 'type' => 'string', 'enum' => array( 'any', 'on', 'off' ), 'default' => 'any' ),
					'limit'    => self::int( 1, 1000, 200 ),
					'offset'   => self::int( 0, 0, 0 ),
				)
			),
			self::obj(
				array(
					'total'   => array( 'type' => 'integer' ),
					'count'   => array( 'type' => 'integer' ),
					'options' => self::list_of( self::obj( array( 'name' => self::str(), 'autoload' => self::str(), 'bytes' => array( 'type' => 'integer' ), 'denied' => self::bool() ) ) ),
				),
				array( 'total', 'count', 'options' )
			),
			false
		);
	}
	public function execute( $input ) {
		global $wpdb;
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $input['prefix'] ) ) {
			$where[] = 'option_name LIKE %s';
			$args[]  = $wpdb->esc_like( (string) $input['prefix'] ) . '%';
		}
		if ( ! empty( $input['search'] ) ) {
			$where[] = 'option_name LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( (string) $input['search'] ) . '%';
		}
		$autoload = $input['autoload'] ?? 'any';
		if ( 'on' === $autoload ) {
			$where[] = "autoload IN ('yes','on','auto-on','auto')";
		} elseif ( 'off' === $autoload ) {
			$where[] = "autoload IN ('no','off','auto-off')";
		}
		$limit  = (int) ( $input['limit'] ?? 200 );
		$offset = (int) ( $input['offset'] ?? 0 );
		$sql    = 'SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM ' . $wpdb->options . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY option_name LIMIT %d OFFSET %d';
		$args[] = $limit;
		$args[] = $offset;
		$rows   = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- $sql is assembled above from fixed fragments and placeholders; the values travel through prepare(). This ability lists option names and sizes, which no cached API offers.
		$count_sql = 'SELECT COUNT(*) FROM ' . $wpdb->options . ' WHERE ' . implode( ' AND ', $where );
		$count_args = array_slice( $args, 0, -2 );
		$total = (int) ( $count_args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $count_args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery, PluginCheck.Security.DirectDB -- same statement as above without the LIMIT; no variable reaches it unprepared.
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'name'     => (string) $r['option_name'],
				'autoload' => (string) $r['autoload'],
				'bytes'    => (int) $r['bytes'],
				'denied'   => Redactor::is_denied_key( (string) $r['option_name'] ),
			);
		}
		return array( 'total' => $total, 'count' => count( $out ), 'options' => $out );
	}
}

final class Delete_Option extends Base {
	public static function name(): string {
		return 'godmode/delete-option';
	}
	public static function definition(): array {
		return self::make(
			'options',
			'Delete Option',
			'Delete one wp_options row by name. Returns the previous value so it can be restored with write-option. Denylisted names are refused.',
			self::obj( array( 'option' => self::str( 191, 1 ) ) + self::ack(), array( 'option' ) ),
			self::obj( array( 'option' => self::str(), 'existed' => self::bool(), 'deleted' => self::bool(), 'previous_value' => self::any() ), array( 'option', 'existed', 'deleted', 'previous_value' ) ),
			true
		);
	}
	public function execute( $input ) {
		$name = (string) $input['option'];
		if ( Redactor::is_denied_key( $name ) ) {
			return self::err( 'godmode_option_denied', sprintf( 'Option "%s" is on the secrets denylist and cannot be deleted through AI Godmode.', $name ), 403 );
		}
		global $wpdb;
		$row     = $wpdb->get_row( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the raw stored value is returned for revert; get_option() would filter and cache it.
		$existed = null !== $row;
		$prev    = $existed ? maybe_unserialize( $row['option_value'] ) : null;
		if ( is_object( $prev ) ) {
			$prev = json_decode( wp_json_encode( $prev ), true );
		}
		$deleted = $existed ? (bool) delete_option( $name ) : false;
		return array( 'option' => $name, 'existed' => $existed, 'deleted' => $deleted, 'previous_value' => Redactor::mask( $prev ) );
	}
}

final class Transient_Get extends Base {
	public static function name(): string {
		return 'godmode/transient-get';
	}
	public static function definition(): array {
		return self::make(
			'options',
			'Get Transient',
			'Read one transient by key, with its expiry when stored in the options table. Denylisted keys are refused.',
			self::obj( array( 'key' => self::str( 172, 1 ), 'site_wide' => self::bool( false ) ), array( 'key' ) ),
			self::obj( array( 'key' => self::str(), 'exists' => self::bool(), 'value' => self::any(), 'expires_at' => self::nullable( array( 'type' => 'integer' ) ) ), array( 'key', 'exists', 'value' ) ),
			false
		);
	}
	public function execute( $input ) {
		$key = (string) $input['key'];
		if ( Redactor::is_denied_key( $key ) ) {
			return self::err( 'godmode_option_denied', sprintf( 'Transient "%s" is on the secrets denylist.', $key ), 403 );
		}
		$site  = ! empty( $input['site_wide'] );
		$value = $site ? get_site_transient( $key ) : get_transient( $key );
		if ( is_object( $value ) ) {
			$value = json_decode( wp_json_encode( $value ), true );
		}
		$timeout = $site ? get_site_option( '_site_transient_timeout_' . $key, null ) : get_option( '_transient_timeout_' . $key, null );
		return array( 'key' => $key, 'exists' => false !== $value, 'value' => false === $value ? null : Redactor::mask( $value ), 'expires_at' => null === $timeout ? null : (int) $timeout );
	}
}

final class Transient_Set extends Base {
	public static function name(): string {
		return 'godmode/transient-set';
	}
	public static function definition(): array {
		return self::make(
			'options',
			'Set Transient',
			'Create or replace one transient. expiration is seconds from now, 0 means no expiry. Denylisted keys are refused.',
			self::obj( array( 'key' => self::str( 172, 1 ), 'value' => self::any(), 'expiration' => self::int( 0, 0, 0 ), 'site_wide' => self::bool( false ) ) + self::ack(), array( 'key', 'value' ) ),
			self::obj( array( 'key' => self::str(), 'set' => self::bool() ), array( 'key', 'set' ) ),
			true
		);
	}
	public function execute( $input ) {
		$key = (string) $input['key'];
		if ( Redactor::is_denied_key( $key ) ) {
			return self::err( 'godmode_option_denied', sprintf( 'Transient "%s" is on the secrets denylist.', $key ), 403 );
		}
		$exp   = (int) ( $input['expiration'] ?? 0 );
		$site  = ! empty( $input['site_wide'] );
		$value = Redactor::unmask( $input['value'], $site ? get_site_transient( $key ) : get_transient( $key ) );
		if ( Redactor::has_marker( $value ) ) {
			return self::err( 'godmode_write_redacted', sprintf( 'The value for transient "%s" still contains a redaction marker with no stored secret behind it.', $key ) );
		}
		$ok = $site ? set_site_transient( $key, $value, $exp ) : set_transient( $key, $value, $exp );
		return array( 'key' => $key, 'set' => (bool) $ok );
	}
}

final class Transient_Delete extends Base {
	public static function name(): string {
		return 'godmode/transient-delete';
	}
	public static function definition(): array {
		return self::make(
			'options',
			'Delete Transient',
			'Delete one transient by key, or every expired transient when key is "*expired*". Denylisted keys are refused.',
			self::obj( array( 'key' => self::str( 172, 1 ), 'site_wide' => self::bool( false ) ) + self::ack(), array( 'key' ) ),
			self::obj( array( 'key' => self::str(), 'deleted' => self::bool(), 'purged' => self::nullable( array( 'type' => 'integer' ) ) ), array( 'key', 'deleted' ) ),
			true
		);
	}
	public function execute( $input ) {
		$key = (string) $input['key'];
		if ( '*expired*' === $key ) {
			global $wpdb;
			$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- counts what delete_expired_transients() is about to remove, so the response can report it.
			delete_expired_transients( true );
			return array( 'key' => $key, 'deleted' => true, 'purged' => $before );
		}
		if ( Redactor::is_denied_key( $key ) ) {
			return self::err( 'godmode_option_denied', sprintf( 'Transient "%s" is on the secrets denylist.', $key ), 403 );
		}
		$ok = ! empty( $input['site_wide'] ) ? delete_site_transient( $key ) : delete_transient( $key );
		return array( 'key' => $key, 'deleted' => (bool) $ok, 'purged' => null );
	}
}
