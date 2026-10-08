<?php
/**
 * The Off-site Log and Maintenance tabs.
 *
 * The viewer form posts to this page rather than through admin-post.php on
 * purpose. The viewer key is used inside the one request that renders the
 * results and is never written to the database, an option, a transient or a
 * cookie. If it were stored here, a compromised site could read and rewrite
 * its own log, which is the whole thing this design exists to stop.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Sink_Admin {

	public const ACTION     = 'godmode_sink';
	public const VIEW_NONCE = 'godmode_sink_view';

	private const NOTICE_OPT   = 'godmode_sink_notice';
	// Links the operator clicks to create their own Cloudflare token and account. Nothing is loaded from these addresses.
	private const TOKEN_URL    = 'https://dash.cloudflare.com/profile/api-tokens'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- a link for the operator to open, not an asset.
	private const SIGNUP_URL   = 'https://dash.cloudflare.com/sign-up'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- a link for the operator to open, not an asset.

	private Settings $settings;
	private Audit $audit;
	private Health $health;

	public function __construct( Settings $settings, Audit $audit, Health $health ) {
		$this->settings = $settings;
		$this->audit    = $audit;
		$this->health   = $health;
	}

	public function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_post' ) );
	}

	// -----------------------------------------------------------------------
	// Section: POST handling.
	// -----------------------------------------------------------------------
	public function handle_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to change AI Godmode.', 'ai-godmode' ) );
		}
		check_admin_referer( self::ACTION );

		$command = isset( $_POST['godmode_sink_command'] ) ? sanitize_key( wp_unslash( $_POST['godmode_sink_command'] ) ) : '';
		$token   = isset( $_POST['godmode_cf_token'] ) ? sanitize_text_field( wp_unslash( $_POST['godmode_cf_token'] ) ) : '';

		$provisioner = new Cloudflare_Provisioner( $this->settings );
		$notice      = array();
		$tab         = 'offsite';

		switch ( $command ) {
			case 'provision':
				$result = $provisioner->provision( $token, $this->read_retention() );
				$notice = $this->notice_from( $result, $provisioner, 'provisioned' );
				break;

			case 'rotate':
				$tab    = 'maintenance';
				$result = $provisioner->rotate_keys( $token );
				$notice = $this->notice_from( $result, $provisioner, 'rotated' );
				break;

			case 'update_endpoint':
				$tab    = 'maintenance';
				$result = $provisioner->update_endpoint_software( $token );
				$notice = is_wp_error( $result )
					? array( 'type' => 'error', 'message' => __( 'The endpoint software was not updated: ', 'ai-godmode' ) . $result->get_error_message() )
					: array( 'type' => 'success', 'message' => __( 'Endpoint software updated. Every site in this Cloudflare account is now served by the new version, and no key changed.', 'ai-godmode' ) );
				break;

			case 'save_archive':
				$tab    = 'maintenance';
				$result = $this->save_archive( $provisioner, $token );
				$notice = is_wp_error( $result )
					? array( 'type' => 'error', 'message' => __( 'The archive was not attached: ', 'ai-godmode' ) . $result->get_error_message() )
					: array( 'type' => 'success', 'message' => __( 'Archive attached. Your current viewer key now opens it alongside this site\'s live log. Nothing can write to it.', 'ai-godmode' ) );
				break;

			case 'remove_archive':
				$tab    = 'maintenance';
				$result = $this->remove_archive( $provisioner, $token );
				$notice = is_wp_error( $result )
					? array( 'type' => 'error', 'message' => __( 'The archive was not detached: ', 'ai-godmode' ) . $result->get_error_message() )
					: array( 'type' => 'success', 'message' => __( 'Archive detached from this site. The bucket and everything in it are untouched in Cloudflare.', 'ai-godmode' ) );
				break;

			case 'recheck':
				$status = $this->health->status( true );
				$notice = Health::STATE_LIVE === $status['state']
					? array( 'type' => 'success', 'message' => __( 'Checked just now: the endpoint answered and accepted this site\'s key.', 'ai-godmode' ) )
					: array( 'type' => 'error', 'message' => __( 'Checked just now and it is still failing: ', 'ai-godmode' ) . $status['detail'] );
				break;

			case 'heartbeat_now':
				$tab    = 'maintenance';
				$result = $this->audit->write_heartbeat( array( 'manual' => true ) );
				$notice = is_wp_error( $result )
					? array( 'type' => 'error', 'message' => __( 'The heartbeat did not get through: ', 'ai-godmode' ) . $result->get_error_message() )
					: array( 'type' => 'success', 'message' => __( 'Heartbeat written to the off-site log. The whole path works right now.', 'ai-godmode' ) );
				break;

			case 'save_options':
				$tab = 'maintenance';
				$this->settings->set( 'heartbeat', ! empty( $_POST['godmode_heartbeat'] ) );
				$notice = array( 'type' => 'success', 'message' => __( 'Saved.', 'ai-godmode' ) );
				break;

			case 'forget':
				$tab = 'maintenance';
				$this->settings->set( 'cf_endpoint', '' );
				$this->settings->set( 'cf_ingest_key', '' );
				$this->settings->set( 'cf_last_error', '' );
				$this->settings->set( 'cf_last_error_utc', '' );
				// The chain state goes too. A site that reconnects later starts
				// a fresh chain rather than claiming a sequence number its new
				// bucket has never seen.
				$this->settings->set( 'cf_seq', 0 );
				$this->settings->set( 'cf_last_hash', '' );
				$this->audit->write_event( 'offsite_sink_disconnected' );
				$notice = array(
					'type'    => 'success',
					'message' => __( 'This site has forgotten the endpoint and its ingest key. Nothing in Cloudflare was touched: this site\'s bucket, its lock and every record are still there, and the log is still readable with your viewer key. No other site connected to the same endpoint is affected.', 'ai-godmode' ),
				);
				break;
		}

		if ( $notice ) {
			set_transient( self::NOTICE_OPT . '_' . get_current_user_id(), $notice, 180 );
		}
		wp_safe_redirect( Admin_UI::url( $tab ) );
		exit;
	}

	/**
	 * Attach an old bucket to this site as a read-only archive.
	 *
	 * The operator supplies a bucket name and the prefix their old records sit
	 * under. Everything else is derived, because a binding name is an
	 * implementation detail and asking for one is asking them to be careful
	 * about something they cannot check.
	 */
	private function save_archive( Cloudflare_Provisioner $provisioner, string $token ) {
		$bucket = isset( $_POST['godmode_archive_bucket'] ) ? sanitize_text_field( wp_unslash( $_POST['godmode_archive_bucket'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verified the nonce with check_admin_referer() before calling this.
		$prefix = isset( $_POST['godmode_archive_prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['godmode_archive_prefix'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verified the nonce with check_admin_referer() before calling this.
		$label  = isset( $_POST['godmode_archive_label'] ) ? sanitize_text_field( wp_unslash( $_POST['godmode_archive_label'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verified the nonce with check_admin_referer() before calling this.

		if ( '' === $bucket ) {
			return new \WP_Error( 'godmode_archive_bucket', __( 'Name the R2 bucket the old records are in.', 'ai-godmode' ) );
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/', $bucket ) ) {
			return new \WP_Error( 'godmode_archive_bucket', __( 'That is not a valid R2 bucket name. Lowercase letters, digits and hyphens only.', 'ai-godmode' ) );
		}

		$archives = $this->settings->get( 'cf_archives', array() );
		$archives = is_array( $archives ) ? $archives : array();
		$binding  = Cloudflare_Provisioner::archive_binding_name( $bucket );

		foreach ( $archives as $existing ) {
			if ( is_array( $existing ) && ( $existing['binding'] ?? '' ) === $binding && ( $existing['prefix'] ?? '' ) === $prefix ) {
				return new \WP_Error( 'godmode_archive_dupe', __( 'That bucket and prefix are already attached to this site.', 'ai-godmode' ) );
			}
		}

		$archives[] = array(
			'binding' => $binding,
			'bucket'  => $bucket,
			'prefix'  => $prefix,
			'label'   => '' === $label ? $bucket : $label,
		);

		// Store first, apply second, then roll the setting back if Cloudflare
		// refused. A setting that claims an archive the endpoint cannot reach is
		// worse than no archive: it puts a link on the viewer that leads nowhere.
		$before = $this->settings->get( 'cf_archives', array() );
		$this->settings->set( 'cf_archives', $archives );
		$applied = $provisioner->apply_archives( $token );
		if ( is_wp_error( $applied ) ) {
			$this->settings->set( 'cf_archives', $before );
			return $applied;
		}
		return true;
	}

	private function remove_archive( Cloudflare_Provisioner $provisioner, string $token ) {
		$binding = isset( $_POST['godmode_archive_binding'] ) ? sanitize_text_field( wp_unslash( $_POST['godmode_archive_binding'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verified the nonce with check_admin_referer() before calling this.
		if ( '' === $binding ) {
			return new \WP_Error( 'godmode_archive_binding', __( 'No archive was named.', 'ai-godmode' ) );
		}

		$before = $this->settings->get( 'cf_archives', array() );
		$before = is_array( $before ) ? $before : array();
		$kept   = array();
		foreach ( $before as $archive ) {
			if ( is_array( $archive ) && ( $archive['binding'] ?? '' ) === $binding ) {
				continue;
			}
			$kept[] = $archive;
		}

		$this->settings->set( 'cf_archives', $kept );
		$applied = $provisioner->apply_archives( $token );
		if ( is_wp_error( $applied ) ) {
			$this->settings->set( 'cf_archives', $before );
			return $applied;
		}
		return true;
	}

	private function read_retention() {
		$mode = isset( $_POST['godmode_retention_mode'] ) ? sanitize_key( wp_unslash( $_POST['godmode_retention_mode'] ) ) : 'indefinite'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verified the nonce with check_admin_referer() before calling this.
		if ( 'days' === $mode ) {
			$days = isset( $_POST['godmode_retention_days'] ) ? absint( wp_unslash( $_POST['godmode_retention_days'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- handle() verified the nonce with check_admin_referer() before calling this.
			if ( $days > 0 ) {
				return $days;
			}
		}
		return 'indefinite';
	}

	private function notice_from( $result, Cloudflare_Provisioner $provisioner, string $verb ): array {
		if ( is_wp_error( $result ) ) {
			$this->audit->write_event( 'offsite_sink_' . $verb . '_failed', array( 'error' => $result->get_error_message() ) );
			return array(
				'type'    => 'error',
				'message' => __( 'Could not finish: ', 'ai-godmode' ) . $result->get_error_message(),
				'trace'   => $provisioner->trace(),
			);
		}
		$this->audit->write_event( 'offsite_sink_' . $verb, array( 'endpoint' => $result['endpoint'] ) );
		return array(
			'type'       => empty( $result['warning'] ) ? 'success' : 'warning',
			'message'    => 'rotated' === $verb
				? __( 'Both of this site\'s keys rotated. The old pair stopped working immediately. No other site sharing this endpoint was touched.', 'ai-godmode' )
				: __( 'The off-site audit log is built.', 'ai-godmode' ),
			'warning'    => $result['warning'] ?? null,
			'trace'      => $provisioner->trace(),
			'viewer_key' => $result['viewer_key'],
			'endpoint'   => $result['endpoint'],
		);
	}

	// -----------------------------------------------------------------------
	// Section: Off-site Log tab.
	// -----------------------------------------------------------------------
	public function render_offsite(): void {
		$this->render_notice();

		if ( ! $this->health->is_configured() ) {
			$this->render_setup();
			return;
		}
		$this->render_status();
		$this->render_viewer();
	}

	private function render_status(): void {
		$status   = $this->health->status();
		$failing  = Health::STATE_FAILING === $status['state'];
		$endpoint = (string) $this->settings->get( 'cf_endpoint', '' );

		echo Admin_UI::card_open( __( 'Status', 'ai-godmode' ), 'offsite-log', __( 'What the off-site log is and why it exists.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<div class="godmode-status <?php echo $failing ? 'bad' : 'ok'; ?>">
			<div class="godmode-status-icon"><?php echo $failing ? '!' : '&#10003;'; ?></div>
			<div class="godmode-status-body">
				<h3><?php echo esc_html( $status['headline'] ); ?></h3>
				<p><?php echo esc_html( $status['detail'] ); ?></p>
				<?php if ( $failing ) : ?>
					<p>
						<strong><?php esc_html_e( 'Read the log before you fix this.', 'ai-godmode' ); ?></strong>
						<?php esc_html_e( 'Breaking the recording is the first thing anyone would do in order to work unobserved, so the last records before it went quiet are the ones that matter.', 'ai-godmode' ); ?>
						<?php echo Admin_UI::help_tip( 'log-stopped', __( 'A walkthrough of what to do now.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</p>
					<p style="margin-top:10px">
						<a class="godmode-btn godmode-btn-danger" href="<?php echo esc_url( trailingslashit( $endpoint ) . 'view' ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Read the log now', 'ai-godmode' ); ?></a>
						<a class="godmode-btn godmode-btn-light" href="<?php echo esc_url( Admin_UI::url( 'maintenance' ) ); ?>"><?php esc_html_e( 'Rotate the keys', 'ai-godmode' ); ?></a>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="godmode_sink_command" value="recheck">
			<button class="godmode-btn godmode-btn-light"><?php esc_html_e( 'Check again now', 'ai-godmode' ); ?></button>
			<span class="godmode-hint" style="color:#646970;font-size:12.5px">
				<?php esc_html_e( 'Sends the endpoint a test that validates this site\'s key without writing anything.', 'ai-godmode' ); ?>
			</span>
		</form>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput

		$retention = $this->settings->get( 'cf_retention', 'indefinite' );
		echo Admin_UI::card_open( __( 'Details', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<table class="godmode-dl">
			<tr>
				<th><?php esc_html_e( 'Endpoint', 'ai-godmode' ); ?></th>
				<td><code><?php echo esc_html( $endpoint ); ?></code></td>
			</tr>
			<tr>
				<th>
					<?php esc_html_e( 'Read it from anywhere', 'ai-godmode' ); ?>
					<?php echo Admin_UI::help_tip( 'reading-the-log', __( 'The two ways to read your log, and what a failed check means.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</th>
				<td>
					<a href="<?php echo esc_url( trailingslashit( $endpoint ) . 'view' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( trailingslashit( $endpoint ) . 'view' ); ?></a>
					<div class="godmode-desc" style="margin-top:4px">
						<?php esc_html_e( 'Works from any browser with your viewer key, whether or not this site is alive. Bookmark it somewhere that is not this server.', 'ai-godmode' ); ?>
					</div>
				</td>
			</tr>
			<tr>
				<th>
					<?php esc_html_e( 'Retention', 'ai-godmode' ); ?>
					<?php echo Admin_UI::help_tip( 'offsite-log', __( 'How records are protected once written.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</th>
				<td>
					<?php
					echo esc_html(
						'indefinite' === $retention
							? __( 'Forever. Records cannot be deleted or overwritten at all.', 'ai-godmode' )
							: sprintf( /* translators: %d: days */ __( '%d days, enforced by a lock on the storage bucket.', 'ai-godmode' ), (int) $retention )
					);
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Set up', 'ai-godmode' ); ?></th>
				<td>
					<?php echo esc_html( (string) $this->settings->get( 'cf_provisioned_utc', __( 'unknown', 'ai-godmode' ) ) ); ?>
					<?php $rotated = (string) $this->settings->get( 'cf_rotated_utc', '' ); ?>
					<?php if ( '' !== $rotated ) : ?>
						<div class="godmode-desc" style="margin-top:4px"><?php echo esc_html( sprintf( /* translators: %s: timestamp */ __( 'Keys last rotated %s', 'ai-godmode' ), $rotated ) ); ?></div>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>
					<?php esc_html_e( 'Kept on this site', 'ai-godmode' ); ?>
					<?php echo Admin_UI::help_tip( 'keys', __( 'Why the viewer key is deliberately not stored here.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</th>
				<td>
					<?php esc_html_e( 'The endpoint address, and a key that can only append. Not the viewer key, and not the Cloudflare token, which was discarded the moment setup finished.', 'ai-godmode' ); ?>
				</td>
			</tr>
		</table>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function render_viewer(): void {
		$key     = '';
		$results = null;
		if ( isset( $_POST['godmode_viewer_key'] ) && check_admin_referer( self::VIEW_NONCE, 'godmode_view_nonce' ) ) {
			$key     = sanitize_text_field( wp_unslash( $_POST['godmode_viewer_key'] ) );
			$results = ( new Audit_Viewer( $this->settings ) )->fetch( $key, '', 200 );
		}

		echo Admin_UI::card_open( __( 'Read the log from here', 'ai-godmode' ), 'reading-the-log', __( 'How to read your log and what a failed check means.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<form method="post" action="">
			<?php wp_nonce_field( self::VIEW_NONCE, 'godmode_view_nonce' ); ?>
			<div class="godmode-inline">
				<input type="password" name="godmode_viewer_key" autocomplete="off" placeholder="<?php esc_attr_e( 'Viewer key', 'ai-godmode' ); ?>">
				<button type="submit" class="godmode-btn godmode-btn-primary"><?php esc_html_e( 'Fetch and verify', 'ai-godmode' ); ?></button>
			</div>
			<span class="godmode-hint" style="display:block;margin-top:8px;color:#646970;font-size:12.5px;max-width:74ch">
				<?php esc_html_e( 'Used for this one page load and never stored. This is the authoritative check: it rebuilds every fingerprint using this site\'s own PHP.', 'ai-godmode' ); ?>
			</span>
		</form>
		<?php
		if ( null !== $results ) {
			if ( is_wp_error( $results ) ) {
				echo '<div class="godmode-status bad" style="margin-top:16px"><div class="godmode-status-icon">!</div><div class="godmode-status-body"><p>' . esc_html( $results->get_error_message() ) . '</p></div></div>';
			} else {
				$this->render_results( $results );
			}
		}
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private function render_results( array $results ): void {
		$check = $results['check'];
		?>
		<div class="godmode-status <?php echo $check['ok'] ? 'ok' : 'bad'; ?>" style="margin-top:16px">
			<div class="godmode-status-icon"><?php echo $check['ok'] ? '&#10003;' : '!'; ?></div>
			<div class="godmode-status-body">
				<?php if ( $check['ok'] ) : ?>
					<h3><?php esc_html_e( 'Chain intact.', 'ai-godmode' ); ?></h3>
					<p>
						<?php
						printf(
							/* translators: 1: count, 2: first number, 3: last number */
							esc_html__( '%1$d records, numbered %2$d to %3$d. Nothing is missing and nothing has been altered.', 'ai-godmode' ),
							(int) $check['count'],
							(int) $check['first_seq'],
							(int) $check['last_seq']
						);
						?>
					</p>
				<?php else : ?>
					<h3><?php esc_html_e( 'Chain check FAILED. Treat this as an incident.', 'ai-godmode' ); ?></h3>
					<?php if ( ! empty( $check['missing'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Missing records:', 'ai-godmode' ); ?></strong> <?php echo esc_html( implode( ', ', array_slice( $check['missing'], 0, 40 ) ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $check['broken_links'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Broken links at:', 'ai-godmode' ); ?></strong> <?php echo esc_html( implode( ', ', $check['broken_links'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $check['hash_mismatches'] ) ) : ?>
						<p><strong><?php esc_html_e( 'Contents edited at:', 'ai-godmode' ); ?></strong> <?php echo esc_html( implode( ', ', $check['hash_mismatches'] ) ); ?></p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>

		<table class="godmode-table" style="margin-top:16px">
			<thead>
				<tr>
					<th style="width:64px"><?php esc_html_e( '#', 'ai-godmode' ); ?></th>
					<th style="width:180px"><?php esc_html_e( 'Time (UTC)', 'ai-godmode' ); ?></th>
					<th style="width:110px"><?php esc_html_e( 'Event', 'ai-godmode' ); ?></th>
					<th><?php esc_html_e( 'Ability', 'ai-godmode' ); ?></th>
					<th style="width:64px"><?php esc_html_e( 'User', 'ai-godmode' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$rows = $results['records'];
			usort(
				$rows,
				static function ( $a, $b ) {
					return ( $b['seq'] ?? 0 ) <=> ( $a['seq'] ?? 0 );
				}
			);
			foreach ( array_slice( $rows, 0, 100 ) as $row ) :
				?>
				<tr>
					<td><?php echo esc_html( (string) ( $row['seq'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['time'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['event'] ?? '' ) ); ?></td>
					<td><?php echo '' !== (string) ( $row['ability'] ?? '' ) ? '<code>' . esc_html( (string) $row['ability'] ) . '</code>' : ''; ?></td>
					<td><?php echo esc_html( (string) ( $row['user_id'] ?? '' ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// -----------------------------------------------------------------------
	// Section: Setup, shown when no log exists yet.
	// -----------------------------------------------------------------------
	private function render_setup(): void {
		echo Admin_UI::card_open( __( 'Status', 'ai-godmode' ), 'offsite-log', __( 'Why a log on this server is not good enough.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<div class="godmode-status idle">
			<div class="godmode-status-icon">!</div>
			<div class="godmode-status-body">
				<h3><?php esc_html_e( 'No off-site log yet. The only record is on this server.', 'ai-godmode' ); ?></h3>
				<p><?php esc_html_e( 'This plugin can delete files, write to any table and run arbitrary code. Anything it can write, it can also erase, so a log kept inside the thing it is watching proves nothing against that thing. Setting this up is what turns the audit trail into evidence.', 'ai-godmode' ); ?></p>
			</div>
		</div>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput

		echo Admin_UI::card_open( __( 'Set it up', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<p class="godmode-lede">
			<?php esc_html_e( 'You need a Cloudflare account. It is free, the free plan covers everything this needs, and no card is required to sign up. The records are a few hundred bytes each, so a busy site is a rounding error against the free allowance.', 'ai-godmode' ); ?>
			<?php echo Admin_UI::help_tip( 'offsite-log', __( 'What this is for, in plain language.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		</p>
		<p class="godmode-lede">
			<strong><?php esc_html_e( 'Your token is used once and thrown away.', 'ai-godmode' ); ?></strong>
			<?php esc_html_e( 'It is never written to this database. A token that can create things in your Cloudflare account, sitting in a settings row that run-php could read, would turn a break-in here into a break-in there.', 'ai-godmode' ); ?>
		</p>

		<h4 style="margin:20px 0 8px;font-size:13.5px"><?php esc_html_e( 'Step 1. Create a token', 'ai-godmode' ); ?></h4>
		<p style="margin:0 0 10px">
			<a class="godmode-btn godmode-btn-primary" href="<?php echo esc_url( self::TOKEN_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open Cloudflare API tokens', 'ai-godmode' ); ?></a>
			<a class="godmode-btn godmode-btn-light" href="<?php echo esc_url( self::SIGNUP_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'No account yet? Sign up free', 'ai-godmode' ); ?></a>
		</p>
		<p class="godmode-lede"><?php esc_html_e( 'Choose Create Custom Token, and give it exactly these four permissions:', 'ai-godmode' ); ?></p>
		<?php Admin_UI::token_permissions(); ?>

		<h4 style="margin:22px 0 8px;font-size:13.5px"><?php esc_html_e( 'Step 2. Paste it here', 'ai-godmode' ); ?></h4>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="godmode_sink_command" value="provision">

			<div class="godmode-field">
				<label for="godmode_cf_token"><?php esc_html_e( 'Cloudflare API token', 'ai-godmode' ); ?></label>
				<input type="password" id="godmode_cf_token" name="godmode_cf_token" autocomplete="off" required>
				<span class="godmode-hint"><?php esc_html_e( 'Used once to build everything, then discarded. It is never saved.', 'ai-godmode' ); ?></span>
			</div>

			<div class="godmode-field">
				<label><?php esc_html_e( 'Keep records for', 'ai-godmode' ); ?></label>
				<p style="margin:0 0 6px">
					<label style="font-weight:400">
						<input type="radio" name="godmode_retention_mode" value="indefinite" checked>
						<?php esc_html_e( 'Forever (recommended)', 'ai-godmode' ); ?>
					</label>
				</p>
				<p style="margin:0 0 6px">
					<label style="font-weight:400">
						<input type="radio" name="godmode_retention_mode" value="days">
						<?php esc_html_e( 'A fixed number of days:', 'ai-godmode' ); ?>
					</label>
					<input type="number" name="godmode_retention_days" min="1" max="36500" value="365" style="width:100px;margin-left:6px">
				</p>
				<span class="godmode-hint">
					<?php esc_html_e( 'Either way, records cannot be deleted or overwritten before the period is up, not by this plugin and not by anything holding its keys. An audit trail with an expiry date has an expiry date, which is why forever is the default.', 'ai-godmode' ); ?>
				</span>
			</div>

			<button class="godmode-btn godmode-btn-primary"><?php esc_html_e( 'Build the off-site log', 'ai-godmode' ); ?></button>
		</form>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	// Section: Maintenance tab.
	// -----------------------------------------------------------------------
	public function render_maintenance(): void {
		$this->render_notice();

		$configured = $this->health->is_configured();
		$post_url   = admin_url( 'admin-post.php' );
		$heartbeat  = (bool) $this->settings->get( 'heartbeat', true );
		$off        = $configured ? '' : ' godmode-disabled';

		// --- Heartbeat -----------------------------------------------------
		echo Admin_UI::card_open( __( 'Heartbeat', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<?php if ( ! $configured ) : ?>
			<div class="godmode-off-state">
				<strong><?php esc_html_e( 'The Cloudflare off-site log is not active, so there is no heartbeat to send.', 'ai-godmode' ); ?></strong>
				<?php esc_html_e( 'The heartbeat only has somewhere to go once this site is connected to a Cloudflare endpoint of your own.', 'ai-godmode' ); ?>
				<a href="<?php echo esc_url( Admin_UI::url( 'offsite' ) ); ?>"><?php esc_html_e( 'Activate the off-site log here.', 'ai-godmode' ); ?></a>
				<?php esc_html_e( 'That screen walks you through it, and this card switches itself on when it is done.', 'ai-godmode' ); ?>
			</div>
		<?php endif; ?>

		<p class="godmode-lede" style="margin:0 0 10px">
			<?php esc_html_e( 'While the Cloudflare off-site log is active, this setting has the site ping your Cloudflare endpoint once an hour and write a short "still here" record, whether or not anything else happened. That is how you find out the path from this server to Cloudflare has been cut. Without it, a quiet site and a site whose logging was torn out look identical from the outside. With it, silence means something.', 'ai-godmode' ); ?>
		</p>

		<div class="<?php echo esc_attr( ltrim( $off ) ); ?>">
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="godmode_sink_command" value="save_options">
			<p style="margin:0 0 12px">
				<label style="font-weight:600;font-size:13px">
					<input type="checkbox" name="godmode_heartbeat" value="1" <?php checked( $heartbeat ); ?> <?php disabled( ! $configured ); ?>>
					<?php esc_html_e( 'Send an hourly heartbeat', 'ai-godmode' ); ?>
				</label>
			</p>
			<button class="godmode-btn godmode-btn-light" <?php disabled( ! $configured ); ?>><?php esc_html_e( 'Save', 'ai-godmode' ); ?></button>
		</form>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>" style="margin-top:16px;padding-top:16px;border-top:1px solid #f0f0f1">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="godmode_sink_command" value="heartbeat_now">
			<button class="godmode-btn godmode-btn-light" <?php disabled( ! $configured ); ?>><?php esc_html_e( 'Send a heartbeat now', 'ai-godmode' ); ?></button>
			<span class="godmode-hint" style="color:#646970;font-size:12.5px"><?php esc_html_e( 'A real round trip that proves the whole path works this second.', 'ai-godmode' ); ?></span>
		</form>
		</div>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput

		if ( ! $configured ) {
			echo Admin_UI::card_open( __( 'Keys and disconnection', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<p class="godmode-lede">' . esc_html__( 'Key rotation and disconnection appear here once the off-site log is active. There are no keys to rotate and nothing to disconnect until then.', 'ai-godmode' ) . ' ';
			echo '<a href="' . esc_url( Admin_UI::url( 'offsite' ) ) . '">' . esc_html__( 'Set the off-site log up.', 'ai-godmode' ) . '</a></p>';
			echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}

		// --- Rotation ------------------------------------------------------
		echo Admin_UI::card_open( __( 'Rotate the keys', 'ai-godmode' ), 'keys', __( 'What the two keys are and why only one is stored here.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<p class="godmode-lede">
			<?php esc_html_e( 'Rotating issues a brand new ingest key and a brand new viewer key. The old pair stops working immediately. Every record you already have is untouched.', 'ai-godmode' ); ?>
		</p>
		<p class="godmode-lede"><strong><?php esc_html_e( 'Rotate when:', 'ai-godmode' ); ?></strong></p>
		<ul class="godmode-lede godmode-bullets">
			<li><?php esc_html_e( 'Your viewer key has been seen by anyone who should not have it, including in a screenshot or a chat window.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'The log has stopped recording because the endpoint no longer accepts this site\'s key.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'You have lost the viewer key and need a new one.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Somebody who had access to this site no longer should.', 'ai-godmode' ); ?></li>
		</ul>

		<p class="godmode-lede" style="margin-bottom:10px">
			<?php esc_html_e( 'Create a new Cloudflare API token with these four permissions:', 'ai-godmode' ); ?>
		</p>
		<?php Admin_UI::token_permissions(); ?>
		<p style="margin:0 0 16px">
			<a class="godmode-btn godmode-btn-light" href="<?php echo esc_url( self::TOKEN_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open Cloudflare API tokens', 'ai-godmode' ); ?></a>
		</p>

		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="godmode_sink_command" value="rotate">
			<div class="godmode-field">
				<label for="godmode_rotate_token"><?php esc_html_e( 'Cloudflare API token', 'ai-godmode' ); ?></label>
				<div class="godmode-inline">
					<input type="password" id="godmode_rotate_token" name="godmode_cf_token" autocomplete="off" required
						placeholder="<?php esc_attr_e( 'Paste a fresh token', 'ai-godmode' ); ?>">
					<button class="godmode-btn godmode-btn-primary"><?php esc_html_e( 'Rotate both keys', 'ai-godmode' ); ?></button>
				</div>
				<span class="godmode-hint"><?php esc_html_e( 'You will be shown the new viewer key once, on the screen after this. Have your password manager open.', 'ai-godmode' ); ?></span>
			</div>
		</form>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput

		// --- Endpoint software ---------------------------------------------
		echo Admin_UI::card_open( __( 'Endpoint software', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		$archives = $this->settings->get( 'cf_archives', array() );
		$archives = is_array( $archives ) ? $archives : array();
		?>
		<p class="godmode-lede">
			<?php esc_html_e( 'Replaces the code running at the endpoint with the version bundled in this copy of the plugin. Use it after updating AI Godmode, and before connecting any further site to this Cloudflare account.', 'ai-godmode' ); ?>
		</p>
		<p class="godmode-lede">
			<strong><?php esc_html_e( 'This affects every site in this Cloudflare account at once.', 'ai-godmode' ); ?></strong>
			<?php esc_html_e( 'One endpoint serves them all, which is why this is its own button rather than something that happens quietly during a key rotation. No key changes, no record is touched, and no site has to be reconnected afterwards.', 'ai-godmode' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="godmode_sink_command" value="update_endpoint">
			<div class="godmode-field">
				<label for="godmode_update_token"><?php esc_html_e( 'Cloudflare API token', 'ai-godmode' ); ?></label>
				<div class="godmode-inline">
					<input type="password" id="godmode_update_token" name="godmode_cf_token" autocomplete="off" required
						placeholder="<?php esc_attr_e( 'Paste a token', 'ai-godmode' ); ?>">
					<button class="godmode-btn godmode-btn-primary"><?php esc_html_e( 'Update endpoint software', 'ai-godmode' ); ?></button>
				</div>
				<span class="godmode-hint"><?php esc_html_e( 'The same four permissions as setup. It is discarded as soon as this finishes.', 'ai-godmode' ); ?></span>
			</div>
		</form>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput

		// --- Archives ------------------------------------------------------
		echo Admin_UI::card_open( __( 'Archived logs', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<p class="godmode-lede">
			<?php esc_html_e( 'If this site used to log somewhere else, name that bucket here and your current viewer key will open it too, beside the live log. Attaching an archive never writes to it and never changes a key.', 'ai-godmode' ); ?>
		</p>
		<?php if ( $archives ) : ?>
			<table class="godmode-table" style="max-width:640px;margin:10px 0 16px">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Label', 'ai-godmode' ); ?></th>
						<th><?php esc_html_e( 'Bucket', 'ai-godmode' ); ?></th>
						<th><?php esc_html_e( 'Prefix', 'ai-godmode' ); ?></th>
						<th style="width:90px"></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $archives as $archive ) : ?>
					<?php if ( ! is_array( $archive ) ) { continue; } ?>
					<tr>
						<td><?php echo esc_html( (string) ( $archive['label'] ?? '' ) ); ?></td>
						<td><code><?php echo esc_html( (string) ( $archive['bucket'] ?? '' ) ); ?></code></td>
						<td><code><?php echo esc_html( '' === ( $archive['prefix'] ?? '' ) ? '(whole bucket)' : (string) $archive['prefix'] ); ?></code></td>
						<td>
							<form method="post" action="<?php echo esc_url( $post_url ); ?>">
								<?php wp_nonce_field( self::ACTION ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
								<input type="hidden" name="godmode_sink_command" value="remove_archive">
								<input type="hidden" name="godmode_archive_binding" value="<?php echo esc_attr( (string) ( $archive['binding'] ?? '' ) ); ?>">
								<input type="password" name="godmode_cf_token" autocomplete="off" required
									placeholder="<?php esc_attr_e( 'Token', 'ai-godmode' ); ?>" style="width:90px">
								<button class="godmode-btn godmode-btn-light"><?php esc_html_e( 'Detach', 'ai-godmode' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="godmode-lede"><em><?php esc_html_e( 'No archives attached. Most sites never need one.', 'ai-godmode' ); ?></em></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="godmode_sink_command" value="save_archive">
			<div class="godmode-field">
				<label for="godmode_archive_bucket"><?php esc_html_e( 'R2 bucket holding the old records', 'ai-godmode' ); ?></label>
				<input type="text" id="godmode_archive_bucket" name="godmode_archive_bucket" placeholder="ai-god-mode-audit">
			</div>
			<div class="godmode-field">
				<label for="godmode_archive_prefix"><?php esc_html_e( 'Prefix within that bucket', 'ai-godmode' ); ?></label>
				<input type="text" id="godmode_archive_prefix" name="godmode_archive_prefix"
					placeholder="<?php echo esc_attr( 'sites/' . Cloudflare_Provisioner::site_host() . '/' ); ?>">
				<span class="godmode-hint"><?php esc_html_e( 'Leave empty to read the whole bucket. If the old bucket held several sites, this is what keeps you looking at your own.', 'ai-godmode' ); ?></span>
			</div>
			<div class="godmode-field">
				<label for="godmode_archive_label"><?php esc_html_e( 'What to call it', 'ai-godmode' ); ?></label>
				<input type="text" id="godmode_archive_label" name="godmode_archive_label"
					placeholder="<?php esc_attr_e( 'Old shared log', 'ai-godmode' ); ?>">
			</div>
			<div class="godmode-field">
				<label for="godmode_archive_token"><?php esc_html_e( 'Cloudflare API token', 'ai-godmode' ); ?></label>
				<div class="godmode-inline">
					<input type="password" id="godmode_archive_token" name="godmode_cf_token" autocomplete="off" required
						placeholder="<?php esc_attr_e( 'Paste a token', 'ai-godmode' ); ?>">
					<button class="godmode-btn godmode-btn-primary"><?php esc_html_e( 'Attach archive', 'ai-godmode' ); ?></button>
				</div>
			</div>
		</form>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput

		// --- Disconnect ----------------------------------------------------
		echo '<section class="godmode-card godmode-danger-zone"><h2 class="godmode-card-title">' . esc_html__( 'Disconnect', 'ai-godmode' ) . '</h2><div class="godmode-card-body">';
		?>
		<p class="godmode-lede">
			<?php esc_html_e( 'Makes this site forget the endpoint and its ingest key, so it stops logging off-site. Nothing in Cloudflare is deleted: the bucket, the lock and every record stay exactly as they are, and the log stays readable with your viewer key.', 'ai-godmode' ); ?>
		</p>
		<p class="godmode-lede">
			<strong><?php esc_html_e( 'Changes will stop being protected.', 'ai-godmode' ); ?></strong>
			<?php esc_html_e( 'With no off-site log, the only record is the one on this server, which anything able to do damage can also edit.', 'ai-godmode' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( $post_url ); ?>"
			onsubmit="return confirm('<?php echo esc_js( __( 'Stop logging off-site? Nothing in Cloudflare is deleted.', 'ai-godmode' ) ); ?>');">
			<?php wp_nonce_field( self::ACTION ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
			<input type="hidden" name="godmode_sink_command" value="forget">
			<button class="godmode-btn godmode-btn-danger"><?php esc_html_e( 'Disconnect this site from the log', 'ai-godmode' ); ?></button>
		</form>
		<?php
		echo '</div></section>';
	}

	// -----------------------------------------------------------------------
	// Section: The one-shot notice, including the once-only viewer key.
	// -----------------------------------------------------------------------
	private function render_notice(): void {
		$transient = self::NOTICE_OPT . '_' . get_current_user_id();
		$notice    = get_transient( $transient );
		delete_transient( $transient );

		if ( ! is_array( $notice ) || empty( $notice['message'] ) ) {
			return;
		}
		$bad  = 'error' === ( $notice['type'] ?? '' );
		$warn = ! empty( $notice['warning'] );
		?>
		<div class="godmode-status <?php echo $bad ? 'bad' : ( $warn ? 'idle' : 'ok' ); ?>" style="margin-bottom:18px">
			<div class="godmode-status-icon"><?php echo $bad || $warn ? '!' : '&#10003;'; ?></div>
			<div class="godmode-status-body" style="flex:1 1 auto;min-width:0">
				<h3><?php echo esc_html( $notice['message'] ); ?></h3>

				<?php if ( $warn ) : ?>
					<p>
						<strong><?php esc_html_e( 'Not confirmed working yet.', 'ai-godmode' ); ?></strong>
						<?php echo esc_html( (string) $notice['warning'] ); ?>
						<?php esc_html_e( 'The new key is saved and Cloudflare has it, so this normally sorts itself out within a minute. Press Check again now, or Send a heartbeat now, to confirm.', 'ai-godmode' ); ?>
					</p>
				<?php endif; ?>

				<?php if ( ! empty( $notice['viewer_key'] ) ) : ?>
					<div style="background:#fff;border:2px solid #d63638;border-radius:8px;padding:14px;margin:12px 0">
						<p style="margin:0 0 8px;font-weight:700;color:#d63638">
							<?php esc_html_e( 'Your viewer key. This is the only time it will ever be shown.', 'ai-godmode' ); ?>
						</p>
						<p style="margin:0 0 10px">
							<code style="font-size:14px;word-break:break-all;background:#f6f7f7;padding:8px 10px;border-radius:5px;display:block"><?php echo esc_html( $notice['viewer_key'] ); ?></code>
						</p>
						<p style="margin:0 0 6px;font-size:13px;line-height:1.6">
							<?php esc_html_e( 'Copy it into your password manager now. It is deliberately not stored on this site, because a site that can read its own audit log can be made to lie about it.', 'ai-godmode' ); ?>
						</p>
						<p style="margin:0;font-size:13px;line-height:1.6">
							<strong><?php esc_html_e( 'Copy it from this field before you screenshot anything.', 'ai-godmode' ); ?></strong>
							<?php esc_html_e( 'It only reads, so a leak cannot corrupt the log, but anyone holding it can read everything this site has ever done.', 'ai-godmode' ); ?>
						</p>
						<?php if ( ! empty( $notice['endpoint'] ) ) : ?>
							<p style="margin:10px 0 0;font-size:13px">
								<?php esc_html_e( 'Read the log at:', 'ai-godmode' ); ?>
								<a href="<?php echo esc_url( trailingslashit( $notice['endpoint'] ) . 'view' ); ?>" target="_blank" rel="noopener"><?php echo esc_html( trailingslashit( $notice['endpoint'] ) . 'view' ); ?></a>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $notice['trace'] ) && is_array( $notice['trace'] ) ) : ?>
					<ul class="godmode-bullets" style="margin:8px 0 0;font-size:13px;line-height:1.7">
						<?php foreach ( $notice['trace'] as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}
}
