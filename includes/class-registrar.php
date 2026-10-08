<?php
/**
 * Ability registrar.
 *
 * Takes each ability definition and registers it with core through
 * wp_register_ability() on wp_abilities_api_init, wrapping it with:
 *
 *  1. The switch gate: permission_callback refuses unless the master switch
 *     is armed AND the ability's own switch is on. A registered but switched
 *     off ability is not exposed (meta.public false) and not executable.
 *  2. The capability gate: current_user_can( capability ), manage_options by
 *     default. Exposure flags are never the security boundary.
 *  3. The audit gate: for mutations, the execute wrapper asks the audit
 *     manager whether the intent record landed. If not, it refuses with a
 *     crafted error unless acknowledge_unlogged is true, in which case it
 *     writes a gap marker and proceeds.
 *  4. Error shaping: anything thrown becomes a WP_Error with no paths or
 *     secrets in the message.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Registrar {

	private Settings $settings;
	private Audit $audit;

	/** @var array<string, class-string<Abilities\Base>> */
	private array $classes = array();

	public function __construct( Settings $settings, Audit $audit ) {
		$this->settings = $settings;
		$this->audit    = $audit;
	}

	public function hooks(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		add_filter( 'wp_pre_execute_ability', array( $this, 'switch_gate' ), 10, 4 );
	}

	// -----------------------------------------------------------------------
	// Section: Switch gate. Runs on wp_pre_execute_ability (WordPress 7.1),
	// before validation and permissions, so a switched-off ability answers
	// with a crafted error naming the switch. Core hides permission_callback
	// error messages from callers and logs them with _doing_it_wrong, which is
	// why the switch check does not live in the permission callback.
	// -----------------------------------------------------------------------
	public function switch_gate( $pre, string $name, $input, $ability ) {
		if ( ! isset( $this->classes()[ $name ] ) ) {
			return $pre;
		}
		$blocked = $this->switch_error( $name );
		return $blocked ? $blocked : $pre;
	}

	/** WP_Error when the switches block this ability, null when it may run. */
	private function switch_error( string $name ): ?\WP_Error {
		if ( ! $this->settings->is_armed() ) {
			return new \WP_Error(
				'godmode_disarmed',
				'AI Godmode is disarmed. An administrator must arm it on the AI Godmode settings screen before any ability can run.',
				array( 'status' => 403 )
			);
		}
		if ( ! $this->settings->is_enabled( $name ) ) {
			return new \WP_Error(
				'godmode_ability_disabled',
				sprintf( 'Ability "%s" is switched off. An administrator must enable it on the AI Godmode settings screen.', $name ),
				array( 'status' => 403 )
			);
		}
		return null;
	}

	// -----------------------------------------------------------------------
	// Section: Catalog. The shipped ability classes for this version. Other
	// plugins may add their own through the godmode_ability_classes filter;
	// they get the same gates.
	// -----------------------------------------------------------------------
	/** Family files: each holds several ability classes, so they are required, not autoloaded. */
	private static function family_files(): array {
		return array( 'options', 'database', 'filesystem', 'plugins-themes', 'users-roles', 'cron', 'diagnostics', 'runphp', 'conditional', 'code-snippets', 'reference', 'drawer', 'site-ops' );
	}

	public function classes(): array {
		if ( empty( $this->classes ) ) {
			foreach ( self::family_files() as $file ) {
				require_once GODMODE_DIR . 'includes/abilities/families/' . $file . '.php';
			}
			$classes = array(
				// Standalone files (one class each).
				Abilities\Get_Environment::class,
				Abilities\Read_Option::class,
				Abilities\Write_Option::class,
				// Options family.
				Abilities\List_Options::class,
				Abilities\Delete_Option::class,
				Abilities\Transient_Get::class,
				Abilities\Transient_Set::class,
				Abilities\Transient_Delete::class,
				// Database family.
				Abilities\Db_List_Tables::class,
				Abilities\Db_Describe_Table::class,
				Abilities\Db_Query_Read::class,
				Abilities\Db_Query_Write::class,
				Abilities\Db_Export::class,
				// Filesystem family.
				Abilities\Fs_List::class,
				Abilities\Fs_Read::class,
				Abilities\Fs_Search::class,
				Abilities\Fs_Write::class,
				Abilities\Fs_Patch::class,
				Abilities\Fs_Mkdir::class,
				Abilities\Fs_Copy::class,
				Abilities\Fs_Move::class,
				Abilities\Fs_Delete::class,
				Abilities\Fs_Chmod::class,
				Abilities\Fs_Zip::class,
				Abilities\Fs_Unzip::class,
				// Plugins and themes family.
				Abilities\Plugin_List::class,
				Abilities\Plugin_Install::class,
				Abilities\Plugin_Activate::class,
				Abilities\Plugin_Deactivate::class,
				Abilities\Plugin_Update::class,
				Abilities\Plugin_Delete::class,
				Abilities\Theme_List::class,
				Abilities\Theme_Install::class,
				Abilities\Theme_Switch::class,
				Abilities\Theme_Update::class,
				Abilities\Theme_Delete::class,
				// Users and roles family.
				Abilities\User_List::class,
				Abilities\User_Get::class,
				Abilities\User_Create::class,
				Abilities\User_Update::class,
				Abilities\User_Delete::class,
				Abilities\Role_List::class,
				Abilities\Role_Create::class,
				Abilities\Role_Delete::class,
				Abilities\Role_Add_Cap::class,
				Abilities\Role_Remove_Cap::class,
				Abilities\App_Password_List::class,
				Abilities\App_Password_Create::class,
				Abilities\App_Password_Delete::class,
				// Cron family.
				Abilities\Cron_List::class,
				Abilities\Cron_Run::class,
				Abilities\Cron_Schedule::class,
				Abilities\Cron_Unschedule::class,
				// Diagnostics family.
				Abilities\Site_Health::class,
				Abilities\Php_Info::class,
				Abilities\Constants::class,
				Abilities\Hooks_Inspect::class,
				Abilities\Error_Log_Tail::class,
				// run-php.
				Abilities\Run_Php::class,
				// Site operations.
				Abilities\Http_Fetch::class,
				Abilities\Cache_Purge::class,
				Abilities\Permalinks_Flush::class,
				// Reference.
				Abilities\Get_Reference::class,
				// Drawer: the doors to the compact tool surface. wp-find and wp-call
				// register only when Easy MCP AI is active.
				Abilities\Find::class,
				Abilities\Call::class,
				Abilities\Wp_Find::class,
				Abilities\Wp_Call::class,
				// Conditional (registered only when its host plugin is active).
				Abilities\Siteground_Purge_Cache::class,
				// Code Snippets (registered only when Code Snippets is active and compatible).
				Abilities\Snippets_List::class,
				Abilities\Snippets_Get::class,
				Abilities\Snippets_Create::class,
				Abilities\Snippets_Update::class,
				Abilities\Snippets_Replace::class,
				Abilities\Snippets_Activate::class,
				Abilities\Snippets_Deactivate::class,
				Abilities\Snippets_Trash::class,
				Abilities\Snippets_Run::class,
				Abilities\Snippets_Revert::class,
			);
			$classes = (array) apply_filters( 'godmode_ability_classes', $classes );
			foreach ( $classes as $class ) {
				if ( ! is_string( $class ) || ! class_exists( $class ) || ! is_subclass_of( $class, Abilities\Base::class ) ) {
					continue;
				}
				// A conditional ability opts out when its host plugin is absent.
				if ( method_exists( $class, 'is_available' ) && ! $class::is_available() ) {
					continue;
				}
				$this->classes[ $class::name() ] = $class;
			}
		}
		return $this->classes;
	}

	public function names(): array {
		return array_keys( $this->classes() );
	}

	/** Names of abilities whose effective switch is on. */
	public function active_names(): array {
		return array_values(
			array_filter(
				$this->names(),
				function ( string $name ): bool {
					return $this->settings->is_active( $name );
				}
			)
		);
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			GODMODE_NAMESPACE,
			array(
				'label'       => __( 'AI Godmode', 'ai-godmode' ),
				'description' => __( 'Server administrator abilities. Every one ships switched off.', 'ai-godmode' ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Section: Registration. Every ability is always registered, so the
	// settings screen can list it; exposure and executability follow the
	// switches.
	// -----------------------------------------------------------------------
	public function register_abilities(): void {
		foreach ( $this->classes() as $name => $class ) {
			$def    = $class::definition();
			$active = $this->settings->is_active( $name );
			$this->audit->declare_mutation( $name, $class::is_mutation() );

			$args = array(
				'label'               => (string) $def['label'],
				'description'         => (string) $def['description'],
				'category'            => GODMODE_NAMESPACE,
				'execute_callback'    => $this->execute_wrapper( $class ),
				'permission_callback' => $this->permission_wrapper( $class ),
				'meta'                => array(
					'public'       => $active,
					'show_in_rest' => $active,
					'mcp'          => array( 'public' => $active ),
					'annotations'  => (array) ( $def['annotations'] ?? array() ),
					'godmode'      => array(
						'mutation' => $class::is_mutation(),
						'enabled'  => $this->settings->is_enabled( $name ),
						'armed'    => $this->settings->is_armed(),
					),
				),
			);
			if ( ! empty( $def['input_schema'] ) ) {
				$args['input_schema'] = $def['input_schema'];
			}
			if ( ! empty( $def['output_schema'] ) ) {
				$args['output_schema'] = $def['output_schema'];
			}
			wp_register_ability( $name, $args );
		}
	}

	// -----------------------------------------------------------------------
	// Section: Permission wrapper. Switch state first (so a switched-off
	// ability is never executable even on cores without the pre-execute
	// filter), then the real capability check. Returns plain booleans: core
	// turns a WP_Error here into a _doing_it_wrong notice and a generic
	// "does not have necessary permission" error anyway.
	// -----------------------------------------------------------------------
	private function permission_wrapper( string $class ): callable {
		return function ( $input = null ) use ( $class ): bool {
			$name = $class::name();
			if ( null !== $this->switch_error( $name ) ) {
				return false;
			}
			return is_user_logged_in() && current_user_can( $class::capability() );
		};
	}

	// -----------------------------------------------------------------------
	// Section: Execute wrapper. The audit gate lives here because
	// wp_before_execute_ability is observational and cannot refuse.
	// -----------------------------------------------------------------------
	private function execute_wrapper( string $class ): callable {
		return function ( $input = null ) use ( $class ) {
			$name = $class::name();

			// Belt and braces: re-check the switches at execution time. The
			// permission callback already did, but a direct PHP caller could
			// bypass execute() plumbing in odd ways.
			if ( ! $this->settings->is_active( $name ) ) {
				return new \WP_Error( 'godmode_ability_disabled', sprintf( 'Ability "%s" is switched off.', $name ), array( 'status' => 403 ) );
			}

			if ( $class::is_mutation() ) {
				$intent = $this->audit->intent_result( $name );
				if ( is_wp_error( $intent ) ) {
					$ack = is_array( $input ) && ! empty( $input['acknowledge_unlogged'] );
					if ( ! $ack ) {
						return new \WP_Error(
							'godmode_audit_unavailable',
							'Refused: the audit log could not record this action before execution (' . $intent->get_error_message() . '). Investigate the audit sink, or re-run with acknowledge_unlogged set to true to proceed without an off-site record. The override itself is logged.',
							array( 'status' => 503 )
						);
					}
					$this->audit->write_gap_marker( $name, $intent );
				}
			}

			try {
				$instance = new $class();
				$result   = $instance->execute( $input );
			} catch ( \Throwable $e ) {
				return new \WP_Error(
					'godmode_execution_failed',
					sprintf( 'Ability "%s" failed: %s', $name, $this->safe_message( $e->getMessage() ) ),
					array( 'status' => 500 )
				);
			}
			if ( $class::is_mutation() && is_wp_error( $result ) ) {
				// Core fires wp_after_execute_ability only on success, so record the failure here.
				$this->audit->write_failure( $name, $result );
			}
			if ( $class::is_mutation() && is_array( $result ) && array_key_exists( 'audit_seq', $result ) ) {
				$result['audit_seq'] = $this->audit->intent_seq( $name );
			}
			return $result;
		};
	}

	/** Strip filesystem paths from a message so responses never leak layout. */
	private function safe_message( string $message ): string {
		$message = str_replace( array( ABSPATH, WP_CONTENT_DIR ), '', $message );
		$message = preg_replace( '#/[A-Za-z0-9_./-]+\.php#', '[path]', $message );
		return is_string( $message ) ? $message : 'unknown error';
	}
}
