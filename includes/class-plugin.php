<?php
/**
 * Plugin bootstrap: wires settings, registrar, audit, admin UI, and the
 * optional MCP bridge together. One instance per request.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/** @var Plugin|null */
	private static ?Plugin $instance = null;

	public Settings $settings;
	public Audit $audit;
	public Registrar $registrar;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings  = new Settings();
		$this->audit     = new Audit( $this->settings );
		$this->registrar = new Registrar( $this->settings, $this->audit );
	}

	// -----------------------------------------------------------------------
	// Section: Boot. Called on plugins_loaded once the Abilities API is known
	// to exist.
	// -----------------------------------------------------------------------
	public function boot(): void {
		$this->audit->hooks();
		$this->registrar->hooks();
		if ( is_admin() ) {
			( new Admin( $this->settings, $this->audit, $this->registrar ) )->hooks();
		}
		( new Mcp_Bridge( $this->settings, $this->registrar ) )->hooks();
		( new Surface( $this->settings, $this->registrar ) )->hooks();
		if ( is_admin() ) {
			( new Notices( $this->settings ) )->hooks();
		}
		$this->heartbeat_hooks();
	}

	// -----------------------------------------------------------------------
	// Section: Heartbeat. A periodic beacon to the off-site log so that silence
	// is unambiguous. Without it, a quiet site and a site whose logger has been
	// deleted look exactly the same from the outside.
	//
	// Only scheduled when the off-site log is actually provisioned, so a site
	// that never set one up carries no cron event it does not need.
	// -----------------------------------------------------------------------
	public const HEARTBEAT_HOOK = 'godmode_audit_heartbeat';

	private function heartbeat_hooks(): void {
		add_action( self::HEARTBEAT_HOOK, array( $this, 'run_heartbeat' ) );

		$wanted = (bool) $this->settings->get( 'heartbeat', true )
			&& '' !== (string) $this->settings->get( 'cf_endpoint', '' )
			&& '' !== (string) $this->settings->get( 'cf_ingest_key', '' );
		$scheduled = (bool) wp_next_scheduled( self::HEARTBEAT_HOOK );

		if ( $wanted && ! $scheduled ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::HEARTBEAT_HOOK );
		} elseif ( ! $wanted && $scheduled ) {
			wp_clear_scheduled_hook( self::HEARTBEAT_HOOK );
		}
	}

	/**
	 * The heartbeat itself. Failures are deliberately not retried and not
	 * escalated: a missing heartbeat is information, and the whole point is
	 * that the gap shows up in the log rather than being papered over.
	 */
	public function run_heartbeat(): void {
		$this->audit->write_heartbeat();
	}

	// -----------------------------------------------------------------------
	// Section: Activation. Seeds defaults only when absent so a reinstall never
	// silently re-arms. Everything starts off.
	// -----------------------------------------------------------------------
	public static function activate(): void {
		Settings::seed_defaults();
		$audit = new Audit( new Settings() );
		$audit->write_event( 'plugin_activated', array( 'version' => GODMODE_VERSION ) );
	}

	// -----------------------------------------------------------------------
	// Section: Deactivation. Disarm everything so reactivation is safe.
	// -----------------------------------------------------------------------
	public static function deactivate(): void {
		$settings = new Settings();
		$settings->set_armed( false );
		$settings->set_all_abilities( false );
		wp_clear_scheduled_hook( self::HEARTBEAT_HOOK );
		$audit = new Audit( $settings );
		$audit->write_event( 'plugin_deactivated', array( 'version' => GODMODE_VERSION ) );
	}
}
