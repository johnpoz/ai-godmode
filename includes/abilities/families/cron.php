<?php
/**
 * Cron family: cron-list, cron-run, cron-schedule, cron-unschedule.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Cron_List extends Base {
	public static function name(): string {
		return 'godmode/cron-list';
	}
	public static function definition(): array {
		return self::make( 'cron', 'List Cron Events', 'Every scheduled WP-Cron event with hook, next run, recurrence and args, plus the available schedules and whether cron is disabled.', self::obj( array( 'hook' => self::str( 200 ) ) ), self::obj( array( 'events' => self::list_of( self::map() ), 'count' => array( 'type' => 'integer' ), 'schedules' => self::map(), 'disable_wp_cron' => self::bool(), 'now' => array( 'type' => 'integer' ) ), array( 'events', 'count', 'schedules', 'disable_wp_cron', 'now' ) ), false );
	}
	public function execute( $input ) {
		$filter = (string) ( $input['hook'] ?? '' );
		$out    = array();
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			foreach ( (array) $hooks as $hook => $events ) {
				if ( '' !== $filter && $hook !== $filter ) {
					continue;
				}
				foreach ( (array) $events as $key => $e ) {
					$out[] = array( 'hook' => (string) $hook, 'timestamp' => (int) $ts, 'next_run' => gmdate( 'c', (int) $ts ), 'schedule' => (string) ( $e['schedule'] ?? '' ), 'interval' => isset( $e['interval'] ) ? (int) $e['interval'] : null, 'args' => (array) ( $e['args'] ?? array() ), 'key' => (string) $key );
				}
			}
		}
		$schedules = array();
		foreach ( wp_get_schedules() as $k => $s ) {
			$schedules[ $k ] = (int) $s['interval'];
		}
		return array( 'events' => $out, 'count' => count( $out ), 'schedules' => $schedules, 'disable_wp_cron' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON, 'now' => time() );
	}
}

final class Cron_Run extends Base {
	public static function name(): string {
		return 'godmode/cron-run';
	}
	public static function definition(): array {
		return self::make( 'cron', 'Run Cron Event Now', 'Fire one scheduled hook immediately in this request (with its args) and reschedule recurring events as WP-Cron would. Output and errors from the hook are captured.', self::obj( array( 'hook' => self::str( 200, 1 ), 'args' => self::list_of( self::any() ) ) + self::ack(), array( 'hook' ) ), self::obj( array( 'hook' => self::str(), 'ran' => self::bool(), 'seconds' => array( 'type' => 'number' ), 'output' => self::str() ), array( 'hook', 'ran', 'seconds', 'output' ) ), true, false );
	}
	public function execute( $input ) {
		$hook = (string) $input['hook'];
		$args = isset( $input['args'] ) ? (array) $input['args'] : null;
		if ( ! has_action( $hook ) ) {
			return self::err( 'godmode_cron_no_handler', 'Nothing is hooked to "' . $hook . '".', 404 );
		}
		if ( null === $args ) {
			$args = array();
			foreach ( (array) _get_cron_array() as $ts => $hooks ) {
				if ( isset( $hooks[ $hook ] ) ) {
					$first = reset( $hooks[ $hook ] );
					$args  = (array) ( $first['args'] ?? array() );
					if ( ! empty( $first['schedule'] ) ) {
						wp_reschedule_event( $ts, $first['schedule'], $hook, $args );
					}
					wp_unschedule_event( $ts, $hook, $args );
					break;
				}
			}
		}
		$start = microtime( true );
		ob_start();
		try {
			do_action_ref_array( $hook, $args ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- runs the cron hook the administrator named; that is the ability.
		} catch ( \Throwable $e ) {
			ob_end_clean();
			return self::err( 'godmode_cron_failed', 'Hook threw: ' . $e->getMessage(), 500 );
		}
		$output = (string) ob_get_clean();
		return array( 'hook' => $hook, 'ran' => true, 'seconds' => round( microtime( true ) - $start, 3 ), 'output' => substr( $output, 0, 20000 ) );
	}
}

final class Cron_Schedule extends Base {
	public static function name(): string {
		return 'godmode/cron-schedule';
	}
	public static function definition(): array {
		return self::make( 'cron', 'Schedule Cron Event', 'Schedule a hook: once at a unix timestamp (or in delay seconds), or recurring on a named schedule (hourly, twicedaily, daily, weekly, or any registered one).', self::obj( array( 'hook' => self::str( 200, 1 ), 'timestamp' => self::int( 0 ), 'delay' => self::int( 0 ), 'schedule' => self::str( 50 ), 'args' => self::list_of( self::any() ) ) + self::ack(), array( 'hook' ) ), self::obj( array( 'hook' => self::str(), 'timestamp' => array( 'type' => 'integer' ), 'schedule' => self::nullable( self::str() ), 'scheduled' => self::bool() ), array( 'hook', 'timestamp', 'scheduled' ) ), true, false );
	}
	public function execute( $input ) {
		$hook = (string) $input['hook'];
		$args = isset( $input['args'] ) ? (array) $input['args'] : array();
		$ts   = ! empty( $input['timestamp'] ) ? (int) $input['timestamp'] : time() + (int) ( $input['delay'] ?? 0 );
		$sch  = (string) ( $input['schedule'] ?? '' );
		if ( '' !== $sch ) {
			if ( ! isset( wp_get_schedules()[ $sch ] ) ) {
				return self::err( 'godmode_cron_bad_schedule', 'Unknown schedule "' . $sch . '".' );
			}
			$r = wp_schedule_event( $ts, $sch, $hook, $args, true );
		} else {
			$r = wp_schedule_single_event( $ts, $hook, $args, true );
		}
		if ( is_wp_error( $r ) ) {
			return self::err( 'godmode_cron_schedule_failed', $r->get_error_message() );
		}
		return array( 'hook' => $hook, 'timestamp' => $ts, 'schedule' => '' !== $sch ? $sch : null, 'scheduled' => true === $r );
	}
}

final class Cron_Unschedule extends Base {
	public static function name(): string {
		return 'godmode/cron-unschedule';
	}
	public static function definition(): array {
		return self::make( 'cron', 'Unschedule Cron Event', 'Remove every scheduled occurrence of a hook (optionally only those with matching args).', self::obj( array( 'hook' => self::str( 200, 1 ), 'args' => self::list_of( self::any() ) ) + self::ack(), array( 'hook' ) ), self::obj( array( 'hook' => self::str(), 'removed' => array( 'type' => 'integer' ) ), array( 'hook', 'removed' ) ), true );
	}
	public function execute( $input ) {
		$hook = (string) $input['hook'];
		if ( isset( $input['args'] ) ) {
			$r = wp_clear_scheduled_hook( $hook, (array) $input['args'], true );
		} else {
			$r = wp_unschedule_hook( $hook, true );
		}
		if ( is_wp_error( $r ) ) {
			return self::err( 'godmode_cron_unschedule_failed', $r->get_error_message() );
		}
		return array( 'hook' => $hook, 'removed' => (int) $r );
	}
}
