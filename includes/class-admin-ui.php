<?php
/**
 * Shared admin UI: the stylesheet, the tab bar, and the small components the
 * screens are built from (cards, status pills, risk badges, help links).
 *
 * Everything is scoped under .godmode-wrap so the plugin cannot leak styling
 * into the rest of wp-admin. Colours are WordPress's own admin palette, so the
 * screen looks designed rather than foreign.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_UI {

	public const SLUG = 'ai-godmode';

	/** Tab slug => label. Order is the order they appear. */
	public static function tabs(): array {
		return array(
			'abilities'   => __( 'Abilities', 'ai-godmode' ),
			'drawer'      => __( 'Tool Drawer', 'ai-godmode' ),
			'activity'    => __( 'Activity', 'ai-godmode' ),
			'offsite'     => __( 'Off-site Log', 'ai-godmode' ),
			'maintenance' => __( 'Maintenance', 'ai-godmode' ),
			'help'        => __( 'Help', 'ai-godmode' ),
		);
	}

	public static function current_tab(): string {
		$tabs = self::tabs();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'abilities';
		return isset( $tabs[ $tab ] ) ? $tab : 'abilities';
	}

	public static function url( string $tab = '', string $anchor = '' ): string {
		$args = array( 'page' => self::SLUG );
		if ( '' !== $tab ) {
			$args['tab'] = $tab;
		}
		$url = add_query_arg( $args, admin_url( 'options-general.php' ) );
		return '' !== $anchor ? $url . '#' . $anchor : $url;
	}

	/** Link into a Help section. */
	public static function help_url( string $anchor ): string {
		return self::url( 'help', $anchor );
	}

	// -----------------------------------------------------------------------
	// Section: Components.
	// -----------------------------------------------------------------------

	/**
	 * The little circled question mark. Hovering shows the explanation, and
	 * clicking goes to the matching Help section, because a tooltip is no use
	 * to somebody who needs more than one sentence.
	 */
	public static function help_tip( string $anchor, string $tip ): string {
		return sprintf(
			'<a class="godmode-tip" href="%s" title="%s" aria-label="%s"><span aria-hidden="true">?</span></a>',
			esc_url( self::help_url( $anchor ) ),
			esc_attr( $tip ),
			esc_attr( $tip )
		);
	}

	/**
	 * What kind of ability this is, and whether it is currently in trouble.
	 *
	 * READ and WRITE are plain descriptions, not judgements. An earlier version
	 * marked every writing ability WARNING permanently, which amounted to
	 * warning people that the feature they installed was working. Sixty rows of
	 * standing alarm teaches everyone to ignore alarms.
	 *
	 * WRITE turns into WARNING only while the site is actually degraded, which
	 * is when it says something true and specific: these are the abilities
	 * being refused right now, because the off-site log cannot record them.
	 */
	public static function risk_badge( bool $is_mutation, bool $degraded = false ): string {
		if ( ! $is_mutation ) {
			return sprintf(
				'<a class="godmode-badge godmode-badge-read" href="%s">%s</a>',
				esc_url( self::help_url( 'what-read-means' ) ),
				esc_html__( 'READ', 'ai-godmode' )
			);
		}
		if ( $degraded ) {
			return sprintf(
				'<a class="godmode-badge godmode-badge-warning" href="%s" title="%s">%s</a>',
				esc_url( self::help_url( 'degraded-writes' ) ),
				esc_attr__( 'Being refused right now: the off-site log cannot record it.', 'ai-godmode' ),
				esc_html__( 'WARNING', 'ai-godmode' )
			);
		}
		return sprintf(
			'<a class="godmode-badge godmode-badge-write" href="%s">%s</a>',
			esc_url( self::help_url( 'what-write-means' ) ),
			esc_html__( 'WRITE', 'ai-godmode' )
		);
	}

	/**
	 * The four Cloudflare permissions, as a table. Used by both setup and
	 * rotation so the two can never drift apart, and so neither screen asks
	 * the operator to remember what they picked months ago.
	 *
	 * Workers KV Storage joined the list in 0.5.0. It is where the registry of
	 * sites and key hashes lives, which is what lets each site have keys of its
	 * own instead of every site in the account sharing one pair.
	 */
	public static function token_permissions(): void {
		?>
		<table class="godmode-table" style="max-width:520px;margin:10px 0 12px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Permission', 'ai-godmode' ); ?></th>
					<th style="width:90px"><?php esc_html_e( 'Access', 'ai-godmode' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr><td><code>Account</code> &rarr; <code>Account Settings</code></td><td><?php esc_html_e( 'Read', 'ai-godmode' ); ?></td></tr>
				<tr><td><code>Account</code> &rarr; <code>Workers Scripts</code></td><td><?php esc_html_e( 'Edit', 'ai-godmode' ); ?></td></tr>
				<tr><td><code>Account</code> &rarr; <code>Workers R2 Storage</code></td><td><?php esc_html_e( 'Edit', 'ai-godmode' ); ?></td></tr>
				<tr><td><code>Account</code> &rarr; <code>Workers KV Storage</code></td><td><?php esc_html_e( 'Edit', 'ai-godmode' ); ?></td></tr>
			</tbody>
		</table>
		<p class="godmode-lede" style="margin:0 0 14px">
			<strong><?php esc_html_e( 'Leave Client IP Address Filtering empty.', 'ai-godmode' ); ?></strong>
			<?php esc_html_e( 'The request comes from this web server, not the computer you are sitting at, so the "Use my IP" button makes it fail with an error that looks like a bad token.', 'ai-godmode' ); ?>
		</p>
		<?php
	}

	public static function card_open( string $title = '', string $help_anchor = '', string $tip = '' ): string {
		$out = '<section class="godmode-card">';
		if ( '' !== $title ) {
			$out .= '<h2 class="godmode-card-title">' . esc_html( $title );
			if ( '' !== $help_anchor ) {
				$out .= ' ' . self::help_tip( $help_anchor, $tip );
			}
			$out .= '</h2>';
		}
		return $out . '<div class="godmode-card-body">';
	}

	public static function card_close(): string {
		return '</div></section>';
	}

	// -----------------------------------------------------------------------
	// Section: Chrome. Header, master switch, tab bar.
	// -----------------------------------------------------------------------
	public static function header( bool $armed, string $post_url, string $action ): void {
		?>
		<div class="godmode-header">
			<div class="godmode-brand">
				<h1><?php esc_html_e( 'AI Godmode', 'ai-godmode' ); ?></h1>
				<span class="godmode-version">v<?php echo esc_html( GODMODE_VERSION ); ?></span>
			</div>
			<p class="godmode-tagline">
				<?php esc_html_e( 'Server-administrator reach for an AI agent. Everything ships switched off.', 'ai-godmode' ); ?>
			</p>
		</div>

		<section class="godmode-master <?php echo $armed ? 'is-armed' : 'is-disarmed'; ?>"
			aria-labelledby="godmode-master-heading">
			<div class="godmode-master-text">
				<h2 class="godmode-master-heading" id="godmode-master-heading">
					<?php esc_html_e( 'Arm / Disarm Master Switch', 'ai-godmode' ); ?>
					<?php echo self::help_tip( 'arming', __( 'Nothing this plugin offers runs until the master switch is armed.', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- help_tip() escapes its URL and attributes and returns fixed markup. ?>
				</h2>
				<p class="godmode-master-state">
					<span class="godmode-master-lamp" aria-hidden="true"></span>
					<?php echo $armed ? esc_html__( 'ARMED', 'ai-godmode' ) : esc_html__( 'DISARMED', 'ai-godmode' ); ?>
				</p>
				<p class="godmode-master-say">
					<?php
					echo $armed
						? esc_html__( 'Every ability switched on is live right now, to any administrator and to any AI agent holding administrator credentials.', 'ai-godmode' )
						: esc_html__( 'Nothing works until you arm this. Every ability stays dead no matter how many switches you turn on, so if the plugin seems to be doing nothing at all, this is why.', 'ai-godmode' );
					?>
				</p>
			</div>
			<form class="godmode-master-action" method="post" action="<?php echo esc_url( $post_url ); ?>">
				<?php wp_nonce_field( $action ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
				<?php if ( $armed ) : ?>
					<button class="godmode-btn godmode-btn-xl godmode-btn-light" name="godmode_command" value="disarm">
						<?php esc_html_e( 'Disarm Everything', 'ai-godmode' ); ?>
					</button>
					<span class="godmode-master-hint"><?php esc_html_e( 'Stops every ability at once.', 'ai-godmode' ); ?></span>
				<?php else : ?>
					<button class="godmode-btn godmode-btn-xl godmode-btn-danger" name="godmode_command" value="arm"
						onclick="return confirm('<?php echo esc_js( __( 'Arm AI Godmode? Abilities switched on become live immediately.', 'ai-godmode' ) ); ?>')">
						<?php esc_html_e( 'Arm The Master Switch', 'ai-godmode' ); ?>
					</button>
					<span class="godmode-master-hint"><?php esc_html_e( 'Required. Nothing runs without it.', 'ai-godmode' ); ?></span>
				<?php endif; ?>
			</form>
		</section>
		<?php
	}

	public static function tab_bar( string $current, int $warning_count = 0 ): void {
		echo '<nav class="godmode-tabs">';
		foreach ( self::tabs() as $slug => $label ) {
			$classes = 'godmode-tab' . ( $slug === $current ? ' is-active' : '' );
			printf(
				'<a class="%s" href="%s">%s',
				esc_attr( $classes ),
				esc_url( self::url( $slug ) ),
				esc_html( $label )
			);
			if ( 'offsite' === $slug && $warning_count > 0 ) {
				echo '<span class="godmode-tab-alert" aria-label="' . esc_attr__( 'needs attention', 'ai-godmode' ) . '">!</span>';
			}
			echo '</a>';
		}
		echo '</nav>';
	}

	// -----------------------------------------------------------------------
	// Section: Stylesheet.
	// -----------------------------------------------------------------------
	public static function styles(): void {
		?>
<style id="godmode-admin-css">
.godmode-wrap{--gm-bg:#f6f7f7;--gm-card:#fff;--gm-line:#dcdcde;--gm-line-soft:#f0f0f1;
 --gm-ink:#1d2327;--gm-muted:#646970;--gm-accent:#2271b1;--gm-accent-dark:#135e96;
 --gm-danger:#d63638;--gm-danger-bg:#fcf0f1;--gm-ok:#007017;--gm-ok-bg:#edfaef;
 --gm-warn:#8a6616;--gm-warn-bg:#fcf9e8;--gm-radius:8px;
 max-width:1180px;margin-right:20px;color:var(--gm-ink);}
.godmode-wrap *{box-sizing:border-box}

/* Header */
.godmode-header{padding:22px 0 14px}
.godmode-brand{display:flex;align-items:baseline;gap:10px}
.godmode-brand h1{margin:0;font-size:23px;font-weight:600;line-height:1.2;padding:0}
.godmode-version{font-size:12px;color:var(--gm-muted);background:var(--gm-line-soft);
 border:1px solid var(--gm-line);border-radius:99px;padding:2px 9px;font-weight:500}
.godmode-tagline{margin:6px 0 0;color:var(--gm-muted);font-size:13.5px}

/* Master switch. Deliberately the loudest thing on the page: the single most
   common support question for a plugin like this is "I turned everything on
   and nothing happens", and the answer is always that this is off. */
.godmode-master{display:flex;align-items:center;justify-content:space-between;
 gap:26px;flex-wrap:wrap;padding:24px 28px;border-radius:var(--gm-radius);
 margin-bottom:22px;border:2px solid var(--gm-line);background:var(--gm-card)}
.godmode-master.is-armed{border-color:var(--gm-danger);background:var(--gm-danger-bg);
 box-shadow:inset 7px 0 0 var(--gm-danger)}
.godmode-master.is-disarmed{border-color:#c3c4c7;box-shadow:inset 7px 0 0 #8c8f94}
.godmode-master-text{flex:1 1 380px;min-width:0}
.godmode-master-heading{margin:0;padding:0;font-size:27px;line-height:1.15;
 font-weight:800;letter-spacing:-.015em;color:var(--gm-ink)}
.godmode-master-state{display:flex;align-items:center;gap:9px;margin:10px 0 0;
 font-size:17px;font-weight:800;letter-spacing:.1em;text-transform:uppercase}
.godmode-master.is-armed .godmode-master-state{color:var(--gm-danger)}
.godmode-master.is-disarmed .godmode-master-state{color:var(--gm-muted)}
.godmode-master-lamp{width:13px;height:13px;border-radius:50%;flex:0 0 13px}
.godmode-master.is-armed .godmode-master-lamp{background:var(--gm-danger);
 box-shadow:0 0 0 4px rgba(214,54,56,.18)}
.godmode-master.is-disarmed .godmode-master-lamp{background:#8c8f94;
 box-shadow:0 0 0 4px rgba(140,143,148,.16)}
.godmode-master-say{margin:9px 0 0;font-size:14.5px;line-height:1.6;
 color:var(--gm-ink);max-width:72ch}
.godmode-master-action{display:flex;flex-direction:column;align-items:stretch;
 gap:7px;flex:0 0 auto}
.godmode-master-hint{font-size:12.5px;color:var(--gm-muted);text-align:center}
.godmode-master-heading .godmode-tip{width:21px;height:21px;font-size:13px;
 vertical-align:5px;margin-left:5px}

/* Buttons */
.godmode-btn{display:inline-block;border:1px solid transparent;border-radius:5px;
 padding:7px 16px;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;line-height:1.6}
.godmode-btn-danger{background:var(--gm-danger);color:#fff}
.godmode-btn-danger:hover{background:#b32d2e;color:#fff}
.godmode-btn-light{background:#fff;color:var(--gm-ink);border-color:var(--gm-line)}
.godmode-btn-light:hover{background:var(--gm-line-soft)}
.godmode-btn-primary{background:var(--gm-accent);color:#fff}
.godmode-btn-primary:hover{background:var(--gm-accent-dark);color:#fff}
.godmode-btn-xl{padding:16px 38px;font-size:17px;font-weight:700;border-radius:7px;
 letter-spacing:.01em;box-shadow:0 1px 2px rgba(0,0,0,.12)}
.godmode-btn-xl.godmode-btn-danger{box-shadow:0 2px 6px rgba(214,54,56,.32)}
.godmode-btn-xl.godmode-btn-light{border-width:2px}
@media (max-width:600px){
 .godmode-master{padding:20px}
 .godmode-master-heading{font-size:23px}
 .godmode-master-action{width:100%}
 .godmode-btn-xl{width:100%;text-align:center}
}

/* Tabs */
.godmode-tabs{display:flex;gap:2px;border-bottom:1px solid var(--gm-line);margin-bottom:22px;flex-wrap:wrap}
.godmode-tab{position:relative;padding:11px 18px;font-size:14px;font-weight:500;
 text-decoration:none;color:var(--gm-muted);border:1px solid transparent;border-bottom:none;
 border-radius:var(--gm-radius) var(--gm-radius) 0 0;margin-bottom:-1px}
.godmode-tab:hover{color:var(--gm-ink);background:var(--gm-line-soft)}
.godmode-tab.is-active{color:var(--gm-ink);font-weight:600;background:var(--gm-card);
 border-color:var(--gm-line);border-bottom:1px solid var(--gm-card)}
.godmode-tab-alert{display:inline-flex;align-items:center;justify-content:center;
 width:17px;height:17px;margin-left:7px;border-radius:50%;background:var(--gm-danger);
 color:#fff;font-size:11px;font-weight:700;vertical-align:1px}

/* Cards */
.godmode-card{background:var(--gm-card);border:1px solid var(--gm-line);
 border-radius:var(--gm-radius);margin-bottom:18px;overflow:hidden}
.godmode-card-title{margin:0;padding:14px 20px;font-size:14px;font-weight:600;
 border-bottom:1px solid var(--gm-line-soft);background:#fcfcfc}
.godmode-card-body{padding:18px 20px}
.godmode-card-body>p:first-child{margin-top:0}
.godmode-card-body>p:last-child{margin-bottom:0}
.godmode-lede{color:var(--gm-muted);font-size:13.5px;line-height:1.65}

/* Bulleted lists. wp-admin strips list markers, so say it here. */
.godmode-wrap ul.godmode-bullets{list-style:disc outside;margin:0 0 14px;padding-left:22px}
.godmode-wrap ul.godmode-bullets li{display:list-item;list-style:disc outside;margin:0 0 5px}

/* A card that is switched off because something it depends on is not set up. */
.godmode-off-state{padding:14px 16px;border:1px solid var(--gm-line);border-radius:var(--gm-radius);
 background:var(--gm-line-soft);margin-bottom:16px;font-size:13.5px;line-height:1.65;color:var(--gm-muted)}
.godmode-off-state strong{color:var(--gm-ink)}
.godmode-disabled{opacity:.5;pointer-events:none;filter:grayscale(100%)}

/* Help tips */
.godmode-tip{display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;
 border-radius:50%;background:var(--gm-line-soft);border:1px solid var(--gm-line);
 color:var(--gm-muted);font-size:11px;font-weight:700;text-decoration:none;vertical-align:1px}
.godmode-tip:hover{background:var(--gm-accent);border-color:var(--gm-accent);color:#fff}

/* Badges */
.godmode-badge{display:inline-block;padding:2px 9px;border-radius:99px;font-size:11px;
 font-weight:700;letter-spacing:.05em;text-decoration:none;white-space:nowrap}
.godmode-badge-read{background:#f2f3f4;color:#646970;border:1px solid var(--gm-line)}
.godmode-badge-write{background:#eef3f8;color:#2c3f56;border:1px solid #cbd8e6}
.godmode-badge-warning{background:var(--gm-danger-bg);color:var(--gm-danger);border:1px solid #f0b3b4}
.godmode-badge:hover{filter:brightness(.96)}
.godmode-badge-live{background:var(--gm-ok-bg);color:var(--gm-ok);border:1px solid #b8e6c1;
 padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700}
.godmode-badge-off{background:var(--gm-line-soft);color:var(--gm-muted);border:1px solid var(--gm-line);
 padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600}

/* Status panel */
.godmode-status{display:flex;gap:16px;align-items:flex-start;padding:16px 20px;border-radius:var(--gm-radius)}
.godmode-status.ok{background:var(--gm-ok-bg);border:1px solid #b8e6c1}
.godmode-status.bad{background:var(--gm-danger-bg);border:1px solid #f0b3b4}
.godmode-status.idle{background:var(--gm-warn-bg);border:1px solid #f0e0a8}
.godmode-status-icon{flex:0 0 34px;height:34px;border-radius:50%;display:flex;align-items:center;
 justify-content:center;font-size:18px;font-weight:700;color:#fff}
.godmode-status.ok .godmode-status-icon{background:var(--gm-ok)}
.godmode-status.bad .godmode-status-icon{background:var(--gm-danger)}
.godmode-status.idle .godmode-status-icon{background:#dba617}
.godmode-status-body h3{margin:2px 0 4px;font-size:15px}
.godmode-status.ok h3{color:var(--gm-ok)}
.godmode-status.bad h3{color:var(--gm-danger)}
.godmode-status.idle h3{color:var(--gm-warn)}
.godmode-status-body p{margin:0 0 6px;font-size:13.5px;line-height:1.6}
.godmode-status-body p:last-child{margin-bottom:0}

/* Definition table */
.godmode-dl{width:100%;border-collapse:collapse;font-size:13.5px}
.godmode-dl th{text-align:left;font-weight:600;color:var(--gm-muted);width:190px;
 padding:10px 14px 10px 0;vertical-align:top;border-bottom:1px solid var(--gm-line-soft)}
.godmode-dl td{padding:10px 0;vertical-align:top;border-bottom:1px solid var(--gm-line-soft)}
.godmode-dl tr:last-child th,.godmode-dl tr:last-child td{border-bottom:none}
.godmode-dl code{background:var(--gm-line-soft);padding:2px 6px;border-radius:4px;font-size:12.5px}

/* Ability table */
.godmode-table{width:100%;border-collapse:collapse;font-size:13.5px}
.godmode-table thead th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;
 color:var(--gm-muted);font-weight:600;padding:9px 12px;border-bottom:1px solid var(--gm-line);background:#fcfcfc}
.godmode-table td{padding:10px 12px;border-bottom:1px solid var(--gm-line-soft);vertical-align:top}
.godmode-table tbody tr:hover{background:#fafafa}
.godmode-table .col-on{width:44px;text-align:center}
.godmode-table .col-kind{width:96px}
.godmode-table .col-live{width:74px}
.godmode-table code{font-size:12.5px;color:var(--gm-ink);background:var(--gm-line-soft);
 padding:2px 6px;border-radius:4px;display:inline-block}
.godmode-drawer-section{border:1px solid var(--gm-line);border-radius:8px;margin:0 0 10px;background:#fff}
.godmode-drawer-section>summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;padding:10px 14px;font-weight:600;font-size:13.5px}
.godmode-drawer-section>summary::-webkit-details-marker{display:none}
.godmode-drawer-section>summary::before{content:'\25B8';font-size:12px;color:#646970;width:10px}
.godmode-drawer-section[open]>summary::before{content:'\25BE'}
.godmode-drawer-section .godmode-master:indeterminate{background:#2271b1;border-color:#2271b1}
.godmode-drawer-section .godmode-master:indeterminate::before{content:'';display:block;width:8px;height:2px;background:#fff;margin:5px auto 0}
.godmode-drawer-section[open]>summary{border-bottom:1px solid var(--gm-line)}
.godmode-drawer-section .godmode-drawer-count{margin-left:auto;font-weight:400;font-size:12px;color:#646970}
.godmode-drawer-section[data-kind=foreign]>summary .godmode-drawer-title{color:#1d2327}
.godmode-drawer-table{margin:0}
.godmode-drawer-table td{padding:7px 14px}
.godmode-drawer-table thead th{padding:6px 14px;background:#f6f7f7}
.godmode-drawer-table .col-where{width:118px;white-space:nowrap}
.godmode-drawer-where{display:inline-block;font-size:11.5px;font-weight:600;line-height:1;padding:4px 8px;border-radius:10px}
.godmode-drawer-where.is-listed{color:#0a4b78;background:#e5f0fa}
.godmode-drawer-where.is-drawer{color:#50575e;background:#f0f0f1}
.godmode-drawer-legend{border:1px solid var(--gm-line);border-left:4px solid #2271b1;border-radius:6px;background:#f6f7f7;padding:8px 14px;margin:0 0 12px}
.godmode-drawer-legend p{margin:6px 0;font-size:13px;line-height:1.5}
.godmode-drawer-key{display:inline-block;width:14px;height:14px;border-radius:3px;vertical-align:-2px;margin-right:2px;border:1px solid #8c8f94;background:#fff}
.godmode-drawer-key.is-listed{background:#2271b1;border-color:#2271b1;position:relative}
.godmode-drawer-key.is-listed::after{content:'';position:absolute;left:4px;top:1px;width:4px;height:8px;border:solid #fff;border-width:0 2px 2px 0;transform:rotate(45deg)}
.godmode-family-row td{background:#f6f7f7;font-weight:600;font-size:12px;text-transform:uppercase;
 letter-spacing:.05em;color:var(--gm-muted);padding:8px 12px;border-bottom:1px solid var(--gm-line)}
.godmode-desc{color:var(--gm-muted);font-size:12.5px;line-height:1.55}

/* Forms */
.godmode-field{margin-bottom:16px}
.godmode-field label{display:block;font-weight:600;font-size:13px;margin-bottom:5px}
.godmode-field .godmode-hint{display:block;margin-top:5px;color:var(--gm-muted);font-size:12.5px;
 line-height:1.55}
.godmode-wrap input[type=text],.godmode-wrap input[type=password],.godmode-wrap input[type=number]{
 border:1px solid var(--gm-line);border-radius:5px;padding:7px 10px;font-size:13.5px;max-width:520px;width:100%}
.godmode-inline{display:flex;gap:10px;align-items:flex-start;flex-wrap:wrap}
.godmode-inline input{flex:1 1 320px}
.godmode-danger-zone{border-color:#f0b3b4}
.godmode-danger-zone .godmode-card-title{background:var(--gm-danger-bg);color:var(--gm-danger);
 border-bottom-color:#f0b3b4}

/* Help */
.godmode-help-layout{display:flex;gap:26px;align-items:flex-start}
.godmode-help-nav{flex:0 0 232px;position:sticky;top:46px}
.godmode-help-nav ul{margin:0;padding:0;list-style:none;border:1px solid var(--gm-line);
 border-radius:var(--gm-radius);background:var(--gm-card);overflow:hidden}
.godmode-help-nav li{border-bottom:1px solid var(--gm-line-soft)}
.godmode-help-nav li:last-child{border-bottom:none}
.godmode-help-nav a{display:block;padding:9px 14px;font-size:13px;text-decoration:none;color:var(--gm-ink)}
.godmode-help-nav a:hover{background:var(--gm-line-soft);color:var(--gm-accent)}
.godmode-help-body{flex:1 1 auto;min-width:0}
.godmode-help-body h3{scroll-margin-top:46px;font-size:16px;margin:0 0 10px}
.godmode-help-body h4{font-size:13.5px;margin:18px 0 6px}
.godmode-help-body p,.godmode-help-body li{font-size:13.5px;line-height:1.7}
.godmode-help-body ul{list-style:disc outside;margin:0 0 12px;padding-left:22px}
.godmode-help-body ol{list-style:decimal outside;margin:0 0 12px;padding-left:22px}
.godmode-help-body li{display:list-item}
.godmode-help-body li{margin-bottom:6px}
.godmode-callout{border-left:3px solid var(--gm-accent);background:#f0f6fc;padding:12px 16px;
 border-radius:0 var(--gm-radius) var(--gm-radius) 0;margin:14px 0}
.godmode-callout.warn{border-left-color:var(--gm-danger);background:var(--gm-danger-bg)}
.godmode-callout p{margin:0 0 8px}
.godmode-callout p:last-child{margin-bottom:0}

@media (max-width:960px){
 .godmode-help-layout{flex-direction:column}
 .godmode-help-nav{position:static;flex:1 1 auto;width:100%}
 .godmode-dl th{width:auto;display:block;padding-bottom:2px;border:none}
 .godmode-dl td{display:block;padding-top:0}
}
</style>
		<?php
	}
}
