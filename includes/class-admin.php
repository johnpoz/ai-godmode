<?php
/**
 * Settings > AI Godmode.
 *
 * A tabbed screen: Abilities, Off-site Log, Maintenance, Activity, Help. The
 * master arm switch sits above the tabs because it governs all of them and
 * should never be more than one glance away.
 *
 * Every change is nonce-checked, requires manage_options, and is itself
 * written to the audit log.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin {

	private const SLUG   = Admin_UI::SLUG;
	private const ACTION = 'godmode_save';

	private Settings $settings;
	private Audit $audit;
	private Registrar $registrar;
	private Sink_Admin $sink_admin;
	private Health $health;

	public function __construct( Settings $settings, Audit $audit, Registrar $registrar ) {
		$this->settings   = $settings;
		$this->audit      = $audit;
		$this->registrar  = $registrar;
		$this->health     = new Health( $settings );
		$this->sink_admin = new Sink_Admin( $settings, $audit, $this->health );
	}

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_post' ) );
		add_filter( 'plugin_action_links_' . GODMODE_BASENAME, array( $this, 'action_links' ) );
		$this->sink_admin->hooks();
	}

	public function menu(): void {
		add_options_page(
			__( 'AI Godmode', 'ai-godmode' ),
			__( 'AI Godmode', 'ai-godmode' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function action_links( array $links ): array {
		array_unshift(
			$links,
			'<a href="' . esc_url( Admin_UI::url() ) . '">' . esc_html__( 'Settings', 'ai-godmode' ) . '</a>',
			'<a href="' . esc_url( Admin_UI::help_url( 'getting-started' ) ) . '">' . esc_html__( 'Help', 'ai-godmode' ) . '</a>'
		);
		return $links;
	}

	// -----------------------------------------------------------------------
	// Section: POST handler.
	// -----------------------------------------------------------------------
	public function handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change AI Godmode.', 'ai-godmode' ) );
		}
		check_admin_referer( self::ACTION );

		$command = isset( $_POST['godmode_command'] ) ? sanitize_key( wp_unslash( $_POST['godmode_command'] ) ) : 'save';
		$names   = $this->registrar->names();
		$tab     = 'abilities';

		switch ( $command ) {
			case 'arm':
				$this->settings->set_armed( true );
				$this->audit->write_event( 'armed' );
				break;
			case 'disarm':
				$this->settings->set_armed( false );
				$this->audit->write_event( 'disarmed' );
				break;
			case 'enable_all':
				$this->settings->set_all_abilities( true, $names );
				$this->audit->write_event( 'all_abilities_enabled', array( 'abilities' => $names ) );
				break;
			case 'disable_all':
				$this->settings->set_all_abilities( false, $names );
				$this->audit->write_event( 'all_abilities_disabled', array( 'abilities' => $names ) );
				break;
			case 'enable_safe_only':
				$safe = array();
				foreach ( $this->registrar->classes() as $name => $class ) {
					if ( ! $class::is_mutation() ) {
						$safe[] = $name;
					}
				}
				$this->settings->set_all_abilities( false, $names );
				$this->settings->set_all_abilities( true, $safe );
				$this->audit->write_event( 'safe_abilities_only', array( 'abilities' => $safe ) );
				break;
			case 'clear_log':
				$tab = 'activity';
				$this->audit->local_sink()->clear();
				$this->audit->write_event( 'local_log_cleared' );
				break;
			case 'drawer_save':
				$tab     = 'drawer';
				$surface = new Surface( $this->settings, $this->registrar );
				$valid   = static function ( $n ) { return is_string( $n ) && 1 === preg_match( '/^[a-z0-9_]{1,64}$/', $n ); };
				$compact = ! empty( $_POST['godmode_compact'] );
				$mode    = ! empty( $_POST['godmode_foreign_drawer'] ) ? 'drawer' : 'listed';
				$posted  = isset( $_POST['godmode_always'] ) && is_array( $_POST['godmode_always'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['godmode_always'] ) ) : array();
				$posted  = array_values( array_filter( $posted, $valid ) );
				$seen    = isset( $_POST['godmode_seen_foreign'] ) && is_array( $_POST['godmode_seen_foreign'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['godmode_seen_foreign'] ) ) : array();
				$seen    = array_values( array_filter( $seen, $valid ) );
				$changes = array();
				if ( $compact !== $surface->is_compact() ) {
					$this->settings->set( Surface::SETTING_COMPACT, $compact );
					$changes['compact_surface'] = $compact;
				}
				if ( $mode !== $surface->foreign_mode() ) {
					$surface->set_foreign_mode( $mode );
					$changes['foreign_mode'] = $mode;
				}
				// Other plugins' tools shown but not ticked are the hidden list;
				// ticked ones ride in the always list like everything else.
				$hidden = array_values( array_diff( $seen, $posted ) );
				sort( $hidden );
				$old_hidden = array_keys( $surface->hidden() );
				sort( $old_hidden );
				if ( $hidden !== $old_hidden ) {
					$surface->set_hidden( $hidden );
					$changes['hidden'] = $hidden;
				}
				$before = array_keys( $surface->always_loaded() );
				sort( $before );
				$after = array_values( array_unique( array_merge( $posted, Surface::door_tools() ) ) );
				sort( $after );
				if ( $after !== $before ) {
					$surface->set_always_loaded( $posted );
					$changes['always_loaded'] = $posted;
				}
				if ( ! empty( $changes ) ) {
					$this->audit->write_event( 'tool_drawer_changed', $changes );
				}
				break;
			case 'drawer_defaults':
				$tab = 'drawer';
				( new Surface( $this->settings, $this->registrar ) )->reset_always_loaded();
				$this->audit->write_event( 'tool_drawer_changed', array( 'always_loaded' => 'defaults' ) );
				break;
			default:
				$posted  = isset( $_POST['godmode_ability'] ) && is_array( $_POST['godmode_ability'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['godmode_ability'] ) ) : array();
				$changes = array();
				foreach ( $names as $name ) {
					$new = in_array( $name, $posted, true );
					if ( $new !== $this->settings->is_enabled( $name ) ) {
						$this->settings->set_enabled( $name, $new );
						$changes[ $name ] = $new;
					}
				}
				$bridge = ! empty( $_POST['godmode_mcp_bridge'] );
				if ( $bridge !== (bool) $this->settings->get( 'mcp_bridge', false ) ) {
					$this->settings->set( 'mcp_bridge', $bridge );
					$changes['mcp_bridge'] = $bridge;
				}
				if ( ! empty( $changes ) ) {
					$this->audit->write_event( 'switches_changed', array( 'changes' => $changes ) );
				}
				break;
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', Admin_UI::url( $tab ) ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// Section: Render.
	// -----------------------------------------------------------------------
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab    = Admin_UI::current_tab();
		$armed  = $this->settings->is_armed();
		$status = $this->health->status();
		$alerts = ( Health::STATE_FAILING === $status['state'] ) ? 1 : 0;

		Admin_UI::styles();
		echo '<div class="wrap godmode-wrap">';
		Admin_UI::header( $armed, admin_url( 'admin-post.php' ), self::ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! empty( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Saved.', 'ai-godmode' ) . '</p></div>';
		}

		Admin_UI::tab_bar( $tab, $alerts );

		switch ( $tab ) {
			case 'offsite':
				$this->sink_admin->render_offsite();
				break;
			case 'maintenance':
				$this->sink_admin->render_maintenance();
				break;
			case 'drawer':
				$this->render_drawer();
				break;
			case 'activity':
				$this->render_activity();
				break;
			case 'help':
				( new Help_Admin() )->render();
				break;
			default:
				$this->render_abilities( $armed, Health::STATE_FAILING === $status['state'] );
				break;
		}

		echo '</div>';
	}

	// -----------------------------------------------------------------------
	// Section: Abilities tab.
	// -----------------------------------------------------------------------
	private function render_abilities( bool $armed, bool $degraded = false ): void {
		$names    = $this->registrar->names();
		$classes  = $this->registrar->classes();
		$post_url = admin_url( 'admin-post.php' );

		$total   = count( $names );
		$warning = 0;
		$on      = 0;
		foreach ( $names as $name ) {
			if ( $classes[ $name ]::is_mutation() ) {
				$warning++;
			}
			if ( $this->settings->is_enabled( $name ) ) {
				$on++;
			}
		}

		echo Admin_UI::card_open( __( 'Abilities', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<p class="godmode-lede">
			<?php
			printf(
				/* translators: 1: total abilities, 2: number that change the site, 3: number switched on */
				esc_html__( '%1$d abilities are registered. %2$d of them write, meaning they change your site. %3$d are switched on right now. An ability runs only when the master switch is armed AND its own switch is on, so a switch on this page does nothing by itself.', 'ai-godmode' ),
				(int) $total,
				(int) $warning,
				(int) $on
			);
			?>
		</p>
		<?php if ( ! $armed ) : ?>
			<p class="godmode-lede"><strong><?php esc_html_e( 'Nothing below is live, because the master switch is off.', 'ai-godmode' ); ?></strong></p>
		<?php endif; ?>
		<?php if ( $degraded ) : ?>
			<p class="godmode-lede">
				<strong style="color:#d63638"><?php esc_html_e( 'Every WRITE below is being refused.', 'ai-godmode' ); ?></strong>
				<?php esc_html_e( 'The off-site log cannot record them, so they are blocked until it is working again. Reads are unaffected.', 'ai-godmode' ); ?>
				<?php echo Admin_UI::help_tip( 'degraded-writes', __( 'Why writes are blocked and how to clear it.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</p>
		<?php endif; ?>
		<?php
		// Code Snippets is installed but a function or class the Code Snippets
		// abilities call is gone, so the whole family stayed unregistered.
		// Say so, by name, instead of letting the family vanish silently.
		$godmode_cs = Abilities\Snippets_Bridge::status();
		if ( 'incompatible' === $godmode_cs['state'] ) :
			?>
			<p class="godmode-lede godmode-cs-incompatible">
				<strong style="color:#d63638"><?php esc_html_e( 'The Code Snippets abilities are switched off.', 'ai-godmode' ); ?></strong>
				<?php
				printf(
					/* translators: 1: Code Snippets version, 2: comma-separated list of missing names */
					esc_html__( 'Code Snippets %1$s is active, but it no longer has %2$s, which these abilities depend on. They stay hidden until AI Godmode is updated to match, so nothing half-works.', 'ai-godmode' ),
					esc_html( (string) $godmode_cs['version'] ),
					esc_html( implode( ', ', $godmode_cs['missing'] ) )
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin-top:14px">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<p style="margin:0 0 14px;display:flex;gap:8px;flex-wrap:wrap">
				<button class="godmode-btn godmode-btn-light" name="godmode_command" value="enable_safe_only"><?php esc_html_e( 'Read-only', 'ai-godmode' ); ?></button>
				<button class="godmode-btn godmode-btn-light" name="godmode_command" value="enable_all"
					onclick="return confirm('<?php echo esc_js( __( 'Switch on every ability, including the ones that can delete files, drop tables and run arbitrary code?', 'ai-godmode' ) ); ?>')"><?php esc_html_e( 'Everything', 'ai-godmode' ); ?></button>
				<button class="godmode-btn godmode-btn-light" name="godmode_command" value="disable_all"><?php esc_html_e( 'Nothing', 'ai-godmode' ); ?></button>
			</p>

			<table class="godmode-table">
				<thead>
					<tr>
						<th class="col-on"><?php esc_html_e( 'On', 'ai-godmode' ); ?></th>
						<th><?php esc_html_e( 'Ability', 'ai-godmode' ); ?></th>
						<th class="col-kind">
							<?php esc_html_e( 'Kind', 'ai-godmode' ); ?>
							<?php echo Admin_UI::help_tip( 'what-write-means', __( 'READ only looks. WRITE changes your site. Click for the full explanation.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</th>
						<th class="col-live">
							<?php esc_html_e( 'Live now', 'ai-godmode' ); ?>
							<?php echo Admin_UI::help_tip( 'arming', __( 'Live means armed AND switched on, so an AI agent can call it right now.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						</th>
						<th><?php esc_html_e( 'What it does', 'ai-godmode' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php
				$by_family = array();
				foreach ( $names as $name ) {
					$by_family[ $classes[ $name ]::family() ][] = $name;
				}
				ksort( $by_family );
				foreach ( $by_family as $family => $family_names ) :
					sort( $family_names );
					?>
					<tr class="godmode-family-row"><td colspan="5"><?php echo esc_html( $family ); ?></td></tr>
					<?php foreach ( $family_names as $name ) :
						$class     = $classes[ $name ];
						$def       = $class::definition();
						$mutation  = $class::is_mutation();
						$enabled   = $this->settings->is_enabled( $name );
						$live      = $this->settings->is_active( $name );
						?>
						<tr>
							<td class="col-on">
								<input type="checkbox" name="godmode_ability[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $enabled ); ?>>
							</td>
							<td><code><?php echo esc_html( $name ); ?></code></td>
							<td class="col-kind"><?php echo Admin_UI::risk_badge( $mutation, $degraded ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td class="col-live">
								<?php if ( $live ) : ?>
									<span class="godmode-badge-live"><?php esc_html_e( 'LIVE', 'ai-godmode' ); ?></span>
								<?php else : ?>
									<span class="godmode-badge-off"><?php esc_html_e( 'off', 'ai-godmode' ); ?></span>
								<?php endif; ?>
							</td>
							<td><span class="godmode-desc"><?php echo esc_html( (string) ( $def['description'] ?? '' ) ); ?></span></td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin:18px 0 0">
				<button class="godmode-btn godmode-btn-primary" name="godmode_command" value="save"><?php esc_html_e( 'Save switches', 'ai-godmode' ); ?></button>
			</p>
		</form>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	// Section: Tool Drawer tab. The compact tool surface and its always-loaded
	// set, one expandable section per family or plugin, each with a master
	// checkbox. See includes/class-surface.php for why it exists.
	// -----------------------------------------------------------------------

	/**
	 * Every tool the connector could list, grouped into sections.
	 *
	 * @return array<int,array{key:string,label:string,kind:string,rows:array<int,array{tool:string,desc:string,door:bool}>}>
	 */
	private function drawer_sections(): array {
		$classes  = $this->registrar->classes();
		$sections = array();

		// This plugin's abilities, one section per family.
		$by_family = array();
		foreach ( $classes as $ability => $class ) {
			$by_family[ $class::family() ][] = array(
				'tool' => Surface::tool_name_for_ability( $ability ),
				'desc' => (string) ( $class::definition()['description'] ?? '' ),
				'door' => in_array( Surface::tool_name_for_ability( $ability ), Surface::door_tools(), true ),
			);
		}
		ksort( $by_family );
		foreach ( $by_family as $family => $rows ) {
			usort( $rows, static function ( $a, $b ) { return strcmp( $a['tool'], $b['tool'] ); } );
			$sections[] = array( 'key' => 'godmode-' . $family, 'label' => 'AI Godmode: ' . $family, 'kind' => 'godmode', 'rows' => $rows );
		}

		if ( ! Abilities\Wp_Find::is_available() ) {
			return $sections;
		}
		try {
			$registry = \Easy_MCP_AI\Plugin::instance()->get_tool_registry();
			$defs     = is_object( $registry ) && method_exists( $registry, 'get_all_definitions' ) ? (array) $registry->get_all_definitions() : array();
		} catch ( \Throwable $e ) {
			$defs = array();
		}

		// Other plugins' abilities: map tool name back to its ability so the
		// section can carry the plugin's own category label.
		$owner = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( (array) wp_get_abilities() as $name => $ability ) {
				$tool = Surface::tool_name_for_ability( (string) $name );
				$cat  = is_object( $ability ) && method_exists( $ability, 'get_category' ) ? (string) $ability->get_category() : '';
				$lab  = '';
				if ( '' !== $cat && function_exists( 'wp_get_ability_category' ) ) {
					$c   = wp_get_ability_category( $cat );
					$lab = is_object( $c ) && method_exists( $c, 'get_label' ) ? (string) $c->get_label() : '';
				}
				$ns = (string) strtok( (string) $name, '/' );
				$owner[ $tool ] = array( 'key' => '' !== $cat ? $cat : $ns, 'label' => '' !== $lab ? $lab : ucwords( str_replace( array( '-', '_' ), ' ', $ns ) ) );
			}
		}

		$site    = array();
		$foreign = array();
		foreach ( $defs as $def ) {
			$name = (string) ( $def['name'] ?? '' );
			if ( '' === $name || Surface::is_godmode_tool( $name ) ) {
				continue;
			}
			$row = array( 'tool' => $name, 'desc' => (string) ( $def['description'] ?? '' ), 'door' => false );
			if ( Surface::is_foreign_ability_tool( $name ) ) {
				$o = $owner[ $name ] ?? array( 'key' => (string) strtok( substr( $name, strlen( Surface::ABILITY_PREFIX ) ), '_' ), 'label' => '' );
				if ( '' === $o['label'] ) {
					$o['label'] = ucwords( str_replace( '_', ' ', $o['key'] ) );
				}
				$foreign[ $o['key'] ]['label'] = $o['label'];
				$foreign[ $o['key'] ]['rows'][] = $row;
			} else {
				$site[ Surface::site_group( $name ) ][] = $row;
			}
		}
		ksort( $site );
		foreach ( $site as $group => $rows ) {
			usort( $rows, static function ( $a, $b ) { return strcmp( $a['tool'], $b['tool'] ); } );
			$sections[] = array( 'key' => 'site-' . $group, 'label' => 'Site tools: ' . $group, 'kind' => 'site', 'rows' => $rows );
		}
		uasort( $foreign, static function ( $a, $b ) { return strcasecmp( $a['label'], $b['label'] ); } );
		foreach ( $foreign as $key => $f ) {
			usort( $f['rows'], static function ( $a, $b ) { return strcmp( $a['tool'], $b['tool'] ); } );
			$sections[] = array( 'key' => 'plugin-' . $key, 'label' => 'Plugin: ' . $f['label'], 'kind' => 'foreign', 'rows' => $f['rows'] );
		}
		return $sections;
	}

	private function render_drawer(): void {
		// An admin request has not registered the abilities, so the family
		// files (and the drawer classes) are not loaded yet. Load them before
		// anything here names one.
		$this->registrar->classes();
		$surface  = new Surface( $this->settings, $this->registrar );
		$compact  = $surface->is_compact();
		$always   = $surface->always_loaded();
		$hidden   = $surface->hidden();
		$mode     = $surface->foreign_mode();
		$post_url = admin_url( 'admin-post.php' );
		$easy_mcp = Abilities\Wp_Find::is_available();
		$sections = $this->drawer_sections();

		$listed_count = 0;
		$total_count  = 0;
		foreach ( $sections as $i => $sec ) {
			$on = 0;
			foreach ( $sec['rows'] as $r ) {
				$total_count++;
				if ( $surface->is_loaded( $r['tool'], $always, $hidden ) ) {
					$on++;
					$listed_count++;
				}
			}
			$sections[ $i ]['on'] = $on;
		}

		echo Admin_UI::card_open( __( 'Tool Drawer', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<p class="godmode-lede">
			<?php esc_html_e( 'Every tool an AI client holds is sent back to the model with every single request, whether or not it is used. With this switch on, the connector lists only the tools ticked below. Everything else goes in the drawer: still registered, still switched and audited exactly as before, and reachable through the find and call abilities, which stay listed and carry the names of what is in the drawer.', 'ai-godmode' ); ?>
			<?php echo Admin_UI::help_tip( 'tool-drawer', __( 'How the drawer works and what it costs.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</p>
		<div class="godmode-drawer-legend" role="note">
			<p><span class="godmode-drawer-key is-listed" aria-hidden="true"></span> <strong><?php esc_html_e( 'Ticked = always listed.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'The tool is in the list the AI client fetches, so its definition is sent to the model with every message, used or not.', 'ai-godmode' ); ?></p>
			<p><span class="godmode-drawer-key is-drawer" aria-hidden="true"></span> <strong><?php esc_html_e( 'Unticked = in the drawer.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'Not sent with every message. The AI still reaches it, on demand, through the find and call abilities. Nothing here switches a tool off; that is the Abilities tab.', 'ai-godmode' ); ?></p>
		</div>
		<p class="godmode-lede">
			<?php
			printf(
				/* translators: 1: always-listed tools, 2: total tools, 3: tools in the drawer */
				esc_html__( 'Right now %1$d of %2$d tools are always listed and %3$d are in the drawer.', 'ai-godmode' ),
				(int) $listed_count,
				(int) $total_count,
				(int) ( $total_count - $listed_count )
			);
			?>
			<?php if ( ! $compact ) : ?>
				<strong><?php esc_html_e( 'The drawer is switched off, so every tool is sent with every message regardless of the ticks below.', 'ai-godmode' ); ?></strong>
			<?php endif; ?>
			<?php if ( $surface->is_using_defaults() ) : ?>
				<span class="godmode-desc"><?php esc_html_e( 'Using the shipped defaults.', 'ai-godmode' ); ?></span>
			<?php endif; ?>
		</p>
		<?php if ( ! $easy_mcp ) : ?>
			<p class="godmode-lede godmode-drawer-no-easymcp"><?php esc_html_e( 'Easy MCP AI is not active, so only this plugin\'s abilities appear here. The drawer still applies to the core abilities listing.', 'ai-godmode' ); ?></p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin-top:14px" id="godmode-drawer-form">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<p style="margin:0 0 10px">
				<label><input type="checkbox" name="godmode_compact" value="1" <?php checked( $compact ); ?>> <strong><?php esc_html_e( 'Compact tool surface', 'ai-godmode' ); ?></strong>
				<span class="godmode-desc"><?php esc_html_e( 'Send only the ticked tools to the AI with every message; put the rest in the drawer. Reconnect the AI client after changing this so it fetches the new list.', 'ai-godmode' ); ?></span></label>
			</p>
			<p style="margin:0 0 14px">
				<label><input type="checkbox" name="godmode_foreign_drawer" value="1" <?php checked( 'drawer' === $mode ); ?>> <strong><?php esc_html_e( 'Put other plugins\' abilities in the drawer too', 'ai-godmode' ); ?></strong>
				<span class="godmode-desc"><?php esc_html_e( 'Abilities published by other plugins (the "Plugin:" sections below) stay always listed unless you tick this; the find and call abilities reach them either way. A tick or untick on an individual tool always wins.', 'ai-godmode' ); ?></span></label>
			</p>

			<?php foreach ( $sections as $sec ) : ?>
				<?php
				$n   = count( $sec['rows'] );
				$all = $n > 0 && $sec['on'] === $n;
				?>
				<details class="godmode-drawer-section" data-kind="<?php echo esc_attr( $sec['kind'] ); ?>">
					<summary>
						<input type="checkbox" class="godmode-master" <?php checked( $all ); ?> title="<?php esc_attr_e( 'Tick: always list every tool in this section. Untick: put the whole section in the drawer.', 'ai-godmode' ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: section label */ __( 'Always list every tool in %s', 'ai-godmode' ), $sec['label'] ) ); ?>">
						<span class="godmode-drawer-title"><?php echo esc_html( $sec['label'] ); ?></span>
						<span class="godmode-drawer-count" data-total="<?php echo (int) $n; ?>"><?php echo esc_html( sprintf( /* translators: 1: always listed, 2: in the drawer */ __( '%1$d always listed, %2$d in the drawer', 'ai-godmode' ), (int) $sec['on'], (int) ( $n - $sec['on'] ) ) ); ?></span>
					</summary>
					<table class="godmode-table godmode-drawer-table">
						<thead>
							<tr>
								<th class="col-on"><?php esc_html_e( 'Listed', 'ai-godmode' ); ?></th>
								<th class="col-where"><?php esc_html_e( 'Where it lives', 'ai-godmode' ); ?></th>
								<th><?php esc_html_e( 'Tool', 'ai-godmode' ); ?></th>
								<th><?php esc_html_e( 'What it does', 'ai-godmode' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $sec['rows'] as $r ) : ?>
							<?php $on = $surface->is_loaded( $r['tool'], $always, $hidden ); ?>
							<tr>
								<td class="col-on">
									<?php if ( $r['door'] ) : ?>
										<input type="checkbox" checked disabled title="<?php esc_attr_e( 'The doors to the drawer are always listed.', 'ai-godmode' ); ?>">
									<?php else : ?>
										<input type="checkbox" class="godmode-tool" name="godmode_always[]" value="<?php echo esc_attr( $r['tool'] ); ?>" <?php checked( $on ); ?> title="<?php esc_attr_e( 'Ticked: sent to the AI with every message. Unticked: in the drawer, fetched on demand.', 'ai-godmode' ); ?>">
										<?php if ( 'foreign' === $sec['kind'] ) : ?>
											<input type="hidden" name="godmode_seen_foreign[]" value="<?php echo esc_attr( $r['tool'] ); ?>">
										<?php endif; ?>
									<?php endif; ?>
								</td>
								<td class="col-where"><span class="godmode-drawer-where <?php echo $on ? 'is-listed' : 'is-drawer'; ?>"><?php echo $on ? esc_html__( 'Always listed', 'ai-godmode' ) : esc_html__( 'In the drawer', 'ai-godmode' ); ?></span></td>
								<td><code><?php echo esc_html( $r['tool'] ); ?></code></td>
								<td><span class="godmode-desc"><?php echo esc_html( $r['desc'] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</details>
			<?php endforeach; ?>

			<p style="margin:18px 0 0;display:flex;gap:8px;flex-wrap:wrap">
				<button class="godmode-btn godmode-btn-primary" name="godmode_command" value="drawer_save"><?php esc_html_e( 'Save drawer', 'ai-godmode' ); ?></button>
				<button class="godmode-btn godmode-btn-light" name="godmode_command" value="drawer_defaults"><?php esc_html_e( 'Restore defaults', 'ai-godmode' ); ?></button>
			</p>
		</form>
		<script>
		(function () {
			var form = document.getElementById('godmode-drawer-form');
			if (!form) { return; }
			var words = {
				listed: <?php echo wp_json_encode( __( 'Always listed', 'ai-godmode' ) ); ?>,
				drawer: <?php echo wp_json_encode( __( 'In the drawer', 'ai-godmode' ) ); ?>,
				count: <?php /* translators: 1: always listed, 2: in the drawer */ echo wp_json_encode( __( '%1$d always listed, %2$d in the drawer', 'ai-godmode' ) ); ?>
			};
			function where(box) {
				var cell = box.closest('tr').querySelector('.godmode-drawer-where');
				if (!cell) { return; }
				cell.textContent = box.checked ? words.listed : words.drawer;
				cell.className = 'godmode-drawer-where ' + (box.checked ? 'is-listed' : 'is-drawer');
			}
			function sync(section) {
				var boxes = section.querySelectorAll('input.godmode-tool');
				var on = 0;
				boxes.forEach(function (b) { if (b.checked) { on++; } where(b); });
				var master = section.querySelector('input.godmode-master');
				var doors = section.querySelectorAll('input[disabled][checked]').length;
				master.checked = boxes.length > 0 ? on === boxes.length : true;
				master.indeterminate = on > 0 && on < boxes.length;
				var count = section.querySelector('.godmode-drawer-count');
				var total = parseInt(count.getAttribute('data-total'), 10);
				count.textContent = words.count.replace('%1$d', on + doors).replace('%2$d', total - on - doors);
			}
			form.querySelectorAll('details.godmode-drawer-section').forEach(function (section) {
				var master = section.querySelector('input.godmode-master');
				// A click on the master box must not open or close the section.
				master.addEventListener('click', function (e) { e.stopPropagation(); });
				master.addEventListener('change', function () {
					section.querySelectorAll('input.godmode-tool').forEach(function (b) { b.checked = master.checked; });
					sync(section);
				});
				section.querySelectorAll('input.godmode-tool').forEach(function (b) {
					b.addEventListener('change', function () { sync(section); });
				});
				sync(section);
			});
		})();
		</script>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	// Section: Activity tab. The local buffer.
	// -----------------------------------------------------------------------
	private function render_activity(): void {
		$log      = array_slice( array_reverse( $this->audit->local_sink()->all() ), 0, 100 );
		$post_url = admin_url( 'admin-post.php' );

		echo Admin_UI::card_open( __( 'Recent activity on this server', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<p class="godmode-lede">
			<?php esc_html_e( 'The local record, kept in this site\'s own database. It covers reads as well as changes, which the off-site log deliberately does not, because only changes are worth a network round trip.', 'ai-godmode' ); ?>
			<?php echo Admin_UI::help_tip( 'offsite-log', __( 'Why a local log is not enough on its own.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</p>
		<p class="godmode-lede">
			<strong><?php esc_html_e( 'This one is not evidence.', 'ai-godmode' ); ?></strong>
			<?php esc_html_e( 'It lives where this plugin can reach it, so anything able to do damage is also able to edit this list afterwards. The off-site log is the copy that counts.', 'ai-godmode' ); ?>
		</p>

		<table class="godmode-table" style="margin-top:14px">
			<thead>
				<tr>
					<th style="width:64px"><?php esc_html_e( '#', 'ai-godmode' ); ?></th>
					<th style="width:180px"><?php esc_html_e( 'Time (UTC)', 'ai-godmode' ); ?></th>
					<th style="width:120px"><?php esc_html_e( 'Event', 'ai-godmode' ); ?></th>
					<th><?php esc_html_e( 'Ability', 'ai-godmode' ); ?></th>
					<th style="width:64px"><?php esc_html_e( 'User', 'ai-godmode' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $log ) ) : ?>
				<tr><td colspan="5"><?php esc_html_e( 'Nothing recorded yet.', 'ai-godmode' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $log as $r ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $r['seq'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $r['time'] ?? '' ) ); ?></td>
					<td>
						<?php echo esc_html( (string) ( $r['event'] ?? '' ) ); ?>
						<?php if ( 'gap' === ( $r['event'] ?? '' ) ) : ?>
							<a class="godmode-badge godmode-badge-warning" href="<?php echo esc_url( Admin_UI::help_url( 'acknowledge-unlogged' ) ); ?>"><?php esc_html_e( 'GAP', 'ai-godmode' ); ?></a>
						<?php endif; ?>
					</td>
					<td><?php echo '' !== (string) ( $r['ability'] ?? '' ) ? '<code>' . esc_html( (string) $r['ability'] ) . '</code>' : '<span class="godmode-desc">' . esc_html__( 'plugin event', 'ai-godmode' ) . '</span>'; ?></td>
					<td><?php echo esc_html( (string) ( $r['user_id'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin-top:16px">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<button class="godmode-btn godmode-btn-light" name="godmode_command" value="clear_log"
				onclick="return confirm('<?php echo esc_js( __( 'Clear the local buffer? The clearing is itself recorded as the first new entry, and nothing off-site is affected.', 'ai-godmode' ) ); ?>')">
				<?php esc_html_e( 'Clear this list', 'ai-godmode' ); ?>
			</button>
		</form>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
