<?php
/**
 * The alarm, in its two forms.
 *
 * When the off-site log stops recording, that fact has to reach the operator
 * wherever they are in wp-admin. But a banner that can never be closed becomes
 * furniture: people stop reading it, and worse, they learn to work around it.
 * So the alarm is split in two.
 *
 *  1. A banner on every admin screen, which CAN be dismissed. Dismissing hides
 *     it for twelve hours, and it comes straight back if the failure changes,
 *     because a new failure is new information.
 *
 *  2. A red marker on the admin menu, which CANNOT be dismissed and stays for
 *     exactly as long as the problem does. It sits on Settings and on AI Godmode
 *      itself, so the warning survives closing the banner.
 *
 * The banner is the interruption. The marker is the standing fact.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Notices {

	private const META_KEY    = 'godmode_alarm_dismissed';
	private const SNOOZE      = 12 * HOUR_IN_SECONDS;
	private const DISMISS_ARG = 'godmode_dismiss_alarm';

	private Settings $settings;
	private Health $health;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->health   = new Health( $settings );
	}

	public function hooks(): void {
		add_action( 'admin_init', array( $this, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_notices', array( $this, 'render_old_copy' ) );
		// Late, so the menu is fully built before the marker is attached.
		add_action( 'admin_menu', array( $this, 'mark_menu' ), 999 );
	}

	// -----------------------------------------------------------------------
	// Section: Is the alarm on.
	// -----------------------------------------------------------------------
	public function is_failing(): bool {
		if ( ! $this->health->is_configured() ) {
			return false;
		}
		return Health::STATE_FAILING === $this->health->status()['state'];
	}

	/**
	 * The banner is suppressed only while a dismissal is still in date AND the
	 * failure is the same one that was dismissed. A different failure, or a
	 * failure that came back after being fixed, always shows again.
	 */
	private function is_snoozed( array $status ): bool {
		$saved = get_user_meta( get_current_user_id(), self::META_KEY, true );
		if ( ! is_array( $saved ) ) {
			return false;
		}
		if ( (int) ( $saved['until'] ?? 0 ) < time() ) {
			return false;
		}
		return (string) ( $saved['fingerprint'] ?? '' ) === $this->fingerprint( $status );
	}

	/** Identifies one specific failure, so a new one defeats an old dismissal. */
	private function fingerprint( array $status ): string {
		return md5( (string) $status['detail'] . '|' . (string) $status['since'] );
	}

	// -----------------------------------------------------------------------
	// Section: Dismissal.
	// -----------------------------------------------------------------------
	public function handle_dismiss(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::DISMISS_ARG ] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::DISMISS_ARG );

		$status = $this->health->status();
		update_user_meta(
			get_current_user_id(),
			self::META_KEY,
			array(
				'until'       => time() + self::SNOOZE,
				'fingerprint' => $this->fingerprint( $status ),
			)
		);

		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
		exit;
	}

	// -----------------------------------------------------------------------
	// Section: The admin menu marker.
	//
	// Uses WordPress's own update-count bubble, which is the red circle people
	// already recognise from pending updates, so it needs no explaining.
	// -----------------------------------------------------------------------
	public function mark_menu(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->is_failing() ) {
			return;
		}
		$bubble = ' <span class="update-plugins godmode-menu-alert" title="'
			. esc_attr__( 'AI Godmode: the off-site audit log has stopped recording.', 'ai-godmode' )
			. '"><span class="update-count">!</span></span>';

		// The parent Settings item, so the warning is visible without opening it.
		if ( isset( $GLOBALS['menu'] ) && is_array( $GLOBALS['menu'] ) ) {
			foreach ( $GLOBALS['menu'] as $i => $item ) {
				if ( isset( $item[2] ) && 'options-general.php' === $item[2] ) {
					$GLOBALS['menu'][ $i ][0] .= $bubble;
					break;
				}
			}
		}

		// And the plugin's own item.
		if ( isset( $GLOBALS['submenu']['options-general.php'] ) && is_array( $GLOBALS['submenu']['options-general.php'] ) ) {
			foreach ( $GLOBALS['submenu']['options-general.php'] as $i => $item ) {
				if ( isset( $item[2] ) && Admin_UI::SLUG === $item[2] ) {
					$GLOBALS['submenu']['options-general.php'][ $i ][0] .= $bubble;
					break;
				}
			}
		}
	}

	// -----------------------------------------------------------------------
	// Section: The banner.
	// -----------------------------------------------------------------------
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) || ! $this->health->is_configured() ) {
			return;
		}
		$status = $this->health->status();
		if ( Health::STATE_FAILING !== $status['state'] || $this->is_snoozed( $status ) ) {
			return;
		}

		$dismiss_url = wp_nonce_url( add_query_arg( self::DISMISS_ARG, '1' ), self::DISMISS_ARG );
		$view_url    = trailingslashit( (string) $this->settings->get( 'cf_endpoint', '' ) ) . 'view';
		?>
		<style>
		.godmode-alarm{border-left-width:5px !important;padding:14px 16px;position:relative}
		.godmode-alarm .godmode-alarm-x{position:absolute;top:8px;right:8px;width:26px;height:26px;
		 display:flex;align-items:center;justify-content:center;border-radius:4px;color:#787c82;
		 text-decoration:none;font-size:17px;line-height:1}
		.godmode-alarm .godmode-alarm-x:hover{background:#f0f0f1;color:#d63638}
		.godmode-alarm h2{margin:0 0 8px;font-size:15px;color:#d63638;padding-right:34px}
		.godmode-alarm p{margin:0 0 9px;font-size:13.5px;line-height:1.6;max-width:84ch}
		.godmode-alarm .godmode-alarm-reason{padding:9px 12px;background:#fcf0f1;border-radius:5px;font-size:13px}
		.godmode-alarm .godmode-alarm-foot{margin:0;color:#646970;font-size:12.5px}
		</style>
		<div class="notice notice-error godmode-alarm">
			<a class="godmode-alarm-x" href="<?php echo esc_url( $dismiss_url ); ?>"
				title="<?php esc_attr_e( 'Hide this for 12 hours. The red mark on the menu stays until it is fixed.', 'ai-godmode' ); ?>"
				aria-label="<?php esc_attr_e( 'Dismiss this notice for 12 hours', 'ai-godmode' ); ?>">&times;</a>

			<h2><?php esc_html_e( 'AI Godmode: the off-site audit log has stopped recording.', 'ai-godmode' ); ?></h2>

			<p>
				<?php esc_html_e( 'Until this is fixed, every change an AI agent tries to make through this plugin is being refused. That is the intended behaviour, not a malfunction. The reason given was:', 'ai-godmode' ); ?>
			</p>
			<p class="godmode-alarm-reason">
				<code style="background:none"><?php echo esc_html( $status['detail'] ); ?></code>
				<?php if ( '' !== $status['since'] ) : ?>
					<br><span style="color:#646970"><?php echo esc_html( sprintf( /* translators: %s: timestamp */ __( 'First seen %s UTC', 'ai-godmode' ), $status['since'] ) ); ?></span>
				<?php endif; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Treat this as tampering until proven otherwise.', 'ai-godmode' ); ?></strong>
				<?php esc_html_e( 'The usual innocent explanation is that the keys were rotated somewhere else. The one that matters is that somebody broke the recording in order to work unobserved. Read the log before you fix anything, because the log is the evidence.', 'ai-godmode' ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $view_url ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Read the log', 'ai-godmode' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( Admin_UI::url( 'maintenance' ) ); ?>">
					<?php esc_html_e( 'Rotate the keys', 'ai-godmode' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( Admin_UI::help_url( 'log-stopped' ) ); ?>">
					<?php esc_html_e( 'What do I do about this?', 'ai-godmode' ); ?>
				</a>
			</p>
			<p class="godmode-alarm-foot">
				<?php esc_html_e( 'Closing this hides it for 12 hours. The red mark beside Settings stays until the log is recording again, and this banner returns immediately if the failure changes.', 'ai-godmode' ); ?>
			</p>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	// Section: The old copy left behind by the 0.4.0 rename.
	//
	// Before 0.4.0 the plugin lived in an ai-god-mode folder. Its uninstall
	// routine deletes the godmode_* options, which this copy still uses. So the
	// natural way to tidy up (the Delete link) would erase the arm state, the
	// ability switches and the off-site log keys. Say so, in plain words.
	// -----------------------------------------------------------------------
	public const OLD_FOLDER = 'ai-god-mode';

	public static function old_copy_present( ?string $plugins_dir = null ): bool {
		if ( null === $plugins_dir ) {
			$plugins_dir = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR : dirname( rtrim( GODMODE_DIR, '/\\' ) );
		}
		return is_dir( rtrim( $plugins_dir, '/\\' ) . '/' . self::OLD_FOLDER );
	}

	public function render_old_copy( $plugins_dir = null ): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::old_copy_present( is_string( $plugins_dir ) ? $plugins_dir : null ) ) {
			return;
		}
		?>
		<div class="notice notice-warning godmode-old-copy">
			<p><strong><?php esc_html_e( 'The old AI God Mode folder is still installed.', 'ai-godmode' ); ?></strong></p>
			<p><?php esc_html_e( 'This plugin is now AI Godmode, in the ai-godmode folder, and it is already using all of your settings. The old ai-god-mode folder is no longer needed.', 'ai-godmode' ); ?></p>
			<p><strong><?php esc_html_e( 'Do not remove it with the Delete link on the Plugins screen.', 'ai-godmode' ); ?></strong>
			<?php esc_html_e( 'That runs the old copy\'s uninstall routine, which erases the settings this copy is using: the arm switch, every ability switch, and the off-site log keys.', 'ai-godmode' ); ?></p>
			<p><?php esc_html_e( 'Remove the wp-content/plugins/ai-god-mode folder with a file manager or SFTP instead, or have an agent call godmode/fs-delete on it.', 'ai-godmode' ); ?></p>
		</div>
		<?php
	}
}
