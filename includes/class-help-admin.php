<?php
/**
 * The Help tab.
 *
 * Every question mark elsewhere in the plugin lands on one of these anchors.
 * Written for somebody who has just installed this and does not yet know what
 * they have installed, so it explains rather than reminds.
 *
 * @package AIGodmode
 */

namespace AIGodmode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Help_Admin {

	/** Anchor => nav label. Anchors are referenced from every help tip. */
	private const SECTIONS = array(
		'getting-started'       => 'Start here',
		'what-read-means'       => 'What READ means',
		'what-write-means'      => 'What WRITE means',
		'degraded-writes'       => 'When WRITE says WARNING',
		'run-php'               => 'The one to think hardest about',
		'code-snippets'         => 'Code Snippets',
		'arming'                => 'Arming and disarming',
		'before-you-arm'        => 'Before you arm this',
		'offsite-log'           => 'Off-site audit log',
		'keys'                  => 'The two keys',
		'log-stopped'           => 'The log stopped recording',
		'acknowledge-unlogged'  => 'Running without a record',
		'reading-the-log'       => 'Reading the log',
		'mcp'                   => 'Connecting your AI',
		'tool-drawer'           => 'The tool drawer',
		'what-it-refuses'       => 'What it refuses to do',
		'uninstalling'          => 'Turning it all off',
	);

	public function render(): void {
		?>
		<div class="godmode-help-layout">
			<nav class="godmode-help-nav" aria-label="<?php esc_attr_e( 'Help sections', 'ai-godmode' ); ?>">
				<ul>
					<?php foreach ( self::SECTIONS as $anchor => $label ) : ?>
						<li><a href="#<?php echo esc_attr( $anchor ); ?>"><?php echo esc_html( $label ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			</nav>
			<div class="godmode-help-body">
				<?php
				$this->getting_started();
				$this->read();
				$this->write();
				$this->degraded_writes();
				$this->run_php();
				$this->code_snippets();
				$this->arming();
				$this->before_you_arm();
				$this->offsite_log();
				$this->keys();
				$this->log_stopped();
				$this->ack_unlogged();
				$this->reading_the_log();
				$this->mcp();
				$this->tool_drawer();
				$this->refuses();
				$this->uninstalling();
				?>
			</div>
		</div>
		<?php
	}

	// -----------------------------------------------------------------------
	private function getting_started(): void {
		echo Admin_UI::card_open( __( 'Start here', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="getting-started"><?php esc_html_e( 'What you have just installed', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'AI Godmode hands an AI assistant the same reach over this WordPress site that you have through the admin screens and the server: the database, the files, the options table, plugins and themes, user accounts, scheduled jobs, and the ability to run arbitrary code.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'That is genuinely useful. It is also exactly as dangerous as it sounds, which is why the plugin is named the way it is. Nothing is switched on when you install it. You have to turn things on deliberately, twice: once with the master switch, and once per ability.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'The first five minutes', 'ai-godmode' ); ?></h4>
		<ol>
			<li><?php esc_html_e( 'Take a backup, and make sure it is one you have actually restored from before.', 'ai-godmode' ); ?></li>
			<li><?php
			/* translators: %s: link to the off-site log section */
			echo wp_kses_post( sprintf( __( 'Set up the %s. Do this before arming anything destructive.', 'ai-godmode' ), '<a href="#offsite-log">' . esc_html__( 'off-site audit log', 'ai-godmode' ) . '</a>' ) );
			?></li>
			<li><?php
			/* translators: %s: link to the MCP server section */
			echo wp_kses_post( sprintf( __( 'Install an %s, or this plugin will appear to do nothing at all.', 'ai-godmode' ), '<a href="#mcp">' . esc_html__( 'MCP server', 'ai-godmode' ) . '</a>' ) );
			?></li>
			<li><?php esc_html_e( 'Switch on the abilities you actually need, arm the master switch, and stop there.', 'ai-godmode' ); ?></li>
		</ol>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function read(): void {
		echo Admin_UI::card_open( __( 'What READ means', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="what-read-means"><span class="godmode-badge godmode-badge-read"><?php esc_html_e( 'READ', 'ai-godmode' ); ?></span> <?php esc_html_e( 'means it only looks', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'A READ ability fetches information and gives it back. It cannot change, create or delete anything. Running one a thousand times leaves your site exactly as it was.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'Examples: listing your plugins, reading a setting, describing a database table, tailing the error log, reporting the PHP version.', 'ai-godmode' ); ?></p>
		<div class="godmode-callout">
			<p><strong><?php esc_html_e( 'Harmless to run is not the same as harmless to expose.', 'ai-godmode' ); ?></strong></p>
			<p><?php esc_html_e( 'A read can still show your assistant things you would rather it did not see. The plugin strips the obvious secrets on the way out: database passwords, authentication salts, password hashes and application password values are replaced with a redaction marker, and the options that hold keys and salts are refused outright. But "this cannot break anything" and "this reveals nothing" are different promises, and only the first one is being made.', 'ai-godmode' ); ?></p>
		</div>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function write(): void {
		echo Admin_UI::card_open( __( 'What WRITE means', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="what-write-means"><span class="godmode-badge godmode-badge-write"><?php esc_html_e( 'WRITE', 'ai-godmode' ); ?></span> <?php esc_html_e( 'means it changes your site', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'A WRITE ability alters something real: it writes, replaces, installs or deletes. Once it has run, your site is different, and in many cases there is no undo button.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'This is a description, not a warning. You installed this plugin in order to have writing abilities, and a badge that treated every one of them as an emergency would just teach you to ignore badges. The label turns into WARNING only when something is actually wrong.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'The severity within this group varies enormously, and the badge does not try to tell them apart. Setting a cache value and deleting the database are both WRITE. Read the ability name and its description before you switch it on. The ones that can destroy the most are file delete, database write, user delete, plugin and theme delete, and above all run-php.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'What happens when one runs', 'ai-godmode' ); ?></h4>
		<ol>
			<li><?php esc_html_e( 'The master switch and the ability\'s own switch are both checked.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'WordPress checks the caller really is an administrator. This check happens every time, no matter what the switches say.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'A record of what is about to happen is written to the audit log, including off-site if you have set that up.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Only then does the action run. If step three failed, the action is refused.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'A second record notes how it went.', 'ai-godmode' ); ?></li>
		</ol>
		<p><?php esc_html_e( 'That ordering is the point. The record of an action always exists before the action does, so a change cannot erase the evidence that it was about to happen.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function degraded_writes(): void {
		echo Admin_UI::card_open( __( 'When WRITE says WARNING', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="degraded-writes"><?php esc_html_e( 'The badge is a live readout, not a label', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'Most of the time the Kind column just says READ or WRITE, describing what each ability does. Nothing is wrong and nothing is shouting.', 'ai-godmode' ); ?></p>
		<p>
			<?php esc_html_e( 'When every WRITE turns to', 'ai-godmode' ); ?>
			<span class="godmode-badge godmode-badge-warning"><?php esc_html_e( 'WARNING', 'ai-godmode' ); ?></span><?php esc_html_e( ', that is telling you something specific and current: those abilities are being refused right now, because the off-site audit log cannot record them. It is not a comment on how dangerous they are in general.', 'ai-godmode' ); ?>
		</p>
		<div class="godmode-callout warn">
			<p><?php esc_html_e( 'Reads carry on working normally throughout. Only writes are blocked, and they are blocked on purpose: a change that cannot be recorded is a change nobody could prove happened.', 'ai-godmode' ); ?></p>
		</div>
		<p><?php
		/* translators: %s: link to the section on the log stopping */
		echo wp_kses_post( sprintf( __( 'To clear it, fix the log. The walkthrough is in %s.', 'ai-godmode' ), '<a href="#log-stopped">' . esc_html__( 'The log stopped recording', 'ai-godmode' ) . '</a>' ) );
		?></p>
		<p><?php esc_html_e( 'If you have no off-site log set up at all, writes are never blocked and the badges never change, because there is nothing to fail. In that case the only record of what your AI did lives in a database your AI can edit.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function run_php(): void {
		echo Admin_UI::card_open( __( 'The one to think hardest about', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="run-php"><?php esc_html_e( 'run-php', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'This ability runs whatever PHP code it is given, as an administrator, on your server. It is not one capability among sixty. It is all of them at once, plus every capability this plugin does not ship.', 'ai-godmode' ); ?></p>
		<div class="godmode-callout warn">
			<p><?php esc_html_e( 'With run-php switched on, every other switch on the Abilities tab is a courtesy rather than a control. Code can do what the switched-off abilities would have done. It can read the settings this plugin refuses to read. It can change the plugin\'s own configuration. It can delete the local audit log.', 'ai-godmode' ); ?></p>
			<p><?php esc_html_e( 'What it cannot do is reach into your Cloudflare account and remove records that have already left this machine. That is the entire reason the off-site log exists.', 'ai-godmode' ); ?></p>
		</div>
		<p><?php esc_html_e( 'If you want the convenience of run-php, set up the off-site log first. If you are not going to set up the off-site log, leave run-php switched off.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function code_snippets(): void {
		echo Admin_UI::card_open( __( 'Code Snippets', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="code-snippets"><?php esc_html_e( 'Managing the snippets your site runs', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'When the Code Snippets plugin is active, ten more abilities appear in a code-snippets group on the Abilities tab. They let an AI agent list, read, create, edit, switch on and off, trash, run and revert snippets, always through Code Snippets\' own functions. Your snippets stay in Code Snippets and keep running there. AI Godmode is how the agent reaches them, not a replacement for them.', 'ai-godmode' ); ?></p>
		<ul class="godmode-bullets">
			<li><?php esc_html_e( 'A new snippet is always created switched off.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'The code of a snippet that is switched on can change only by an exact search and replace, and only after the new code parses and passes the same test Code Snippets runs when you save in its editor. If either fails, nothing is written and the snippet keeps running the old code.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Before every change, AI Godmode saves the previous code as a numbered version, up to twenty per snippet, so any change can be put back.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Switching a snippet off works even when that snippet is crashing every page. AI Godmode tells Code Snippets to skip that one snippet for that one request, so the request survives long enough to switch it off. Nothing else is skipped, and only when AI Godmode is armed and that ability is on.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Nothing is ever deleted permanently. Trash is as far as an agent can go; emptying the trash stays in the Code Snippets screen.', 'ai-godmode' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'If a Code Snippets update changes the functions these abilities depend on, the whole group switches itself off and the Abilities tab names what went missing, rather than leaving abilities that half work.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function arming(): void {
		echo Admin_UI::card_open( __( 'Arming and disarming', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="arming"><?php esc_html_e( 'Two switches, both required', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'An ability runs only when the master switch is armed AND that ability\'s own switch is on. Either one off means nothing happens.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'Disarming is the panic button. It takes effect immediately and leaves your individual choices intact, so you can disarm during a scare and arm again afterwards without setting everything up a second time. Deactivating the plugin also disarms it, so it can never wake up armed.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'A newly added ability is always off, even if you previously pressed the button that switches everything on. New power is never granted quietly by an update.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function before_you_arm(): void {
		echo Admin_UI::card_open( __( 'Before you arm this', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="before-you-arm"><?php esc_html_e( 'Four things, and none of them are optional', 'ai-godmode' ); ?></h3>
		<h4><?php esc_html_e( '1. Backups you have actually tested', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'Not a plugin you installed once and assume is working. Take a backup, restore it somewhere, confirm the restore produced a working site. An untested backup is a belief, not a safety net, and this is the wrong plugin to discover the difference under.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( '2. A security review of who can already log in', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'Every ability here requires administrator access. That means this plugin changes what an administrator account is worth. Go through your user list and remove the accounts that should not be there, demote the ones that do not need administrator, and check your application passwords for tokens you no longer recognise.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( '3. Strong, unique passwords and two-factor authentication', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'A reused administrator password was always bad. With this plugin armed, it is the difference between somebody defacing a page and somebody owning the server. Use a password manager and turn on two-factor authentication for every administrator.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( '4. The off-site log', 'ai-godmode' ); ?></h4>
		<p><?php
		/* translators: %s: link to the off-site log section */
		echo wp_kses_post( sprintf( __( 'Set up the %s before you arm anything that can destroy data. It is free and it takes about two minutes.', 'ai-godmode' ), '<a href="#offsite-log">' . esc_html__( 'off-site audit log', 'ai-godmode' ) . '</a>' ) );
		?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function offsite_log(): void {
		echo Admin_UI::card_open( __( 'Off-site audit log', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="offsite-log"><?php esc_html_e( 'Why a log on this server is not good enough', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'AI Godmode keeps a record of everything it does, in your own database. That record is useful right up until the moment you actually need it.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'Think about what this plugin can do. It can delete files. It can run arbitrary code. It can write to any table. So anything it writes down, it can also erase. A log kept inside the thing it is supposed to be watching is worth nothing against that thing, and pretending otherwise would be security theatre.', 'ai-godmode' ); ?></p>
		<p><strong><?php esc_html_e( 'So the record has to leave this machine, before each action runs, and land somewhere this site cannot reach back into.', 'ai-godmode' ); ?></strong></p>

		<h4><?php esc_html_e( 'Be clear about what this is for', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'This is paranoia infrastructure. It is not analytics, it is not a backup, and it will not make your site faster or tidier. You will almost certainly never read it.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'It exists for one scenario: an AI agent with administrator access does something catastrophic, whether through a genuine mistake, a prompt it should not have trusted, or an operator with bad intentions driving it. In that scenario you need to know what happened, in order, and you need a record that whatever did the damage could not quietly edit afterwards.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'Most of the time it is an insurance policy sitting in a drawer. That is the correct outcome.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'Yes, the Cloudflare account is free', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'Cloudflare\'s free plan covers everything this needs, and there is no credit card involved in signing up. The records are tiny pieces of text, a few hundred bytes each. A busy site running AI changes all day produces a rounding error against the free allowances, which include ten gigabytes of storage and a hundred thousand requests a day.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'It is your Cloudflare account, not ours. Nothing is sent anywhere else, and the plugin author has no access to it or to your records.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'What the setup button actually does', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'You create an API token in your Cloudflare dashboard and paste it in once. The plugin then builds the whole thing on your account:', 'ai-godmode' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'A storage bucket to hold the records.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'A small program in front of it that accepts new records and hands existing ones back. It has no ability to delete or overwrite anything, because no such instruction exists anywhere in it.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'A retention lock on the bucket, so records cannot be deleted underneath that program either.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Two separate keys, which is the important part.', 'ai-godmode' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'Then it throws your API token away. It is never written to the database. That matters: a token that can create things in your Cloudflare account, sitting in a settings row that run-php could read, would turn a break-in on this website into a break-in on your Cloudflare account. So it is used once and discarded, and rotating keys later means pasting a fresh one.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'How records are protected once written', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'Each record is numbered in sequence and carries a fingerprint of the record before it, so the whole run is chained together. Remove one and the gap shows. Edit one and the fingerprints stop matching. Records are stored under their sequence number and written only if that number is unused, so the same slot can never be overwritten with a different story.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'What it does not protect against', 'ai-godmode' ); ?></h4>
		<div class="godmode-callout warn">
			<p><?php esc_html_e( 'This is tamper evidence, not tamper proofing, and it is worth being precise about the difference.', 'ai-godmode' ); ?></p>
			<p><?php esc_html_e( 'Somebody with run-php and bad intentions can stop this site from talking to the log at all. What they cannot do is rewrite what it already said, or make the silence itself invisible, which is why an hourly heartbeat is included: with it, a site that goes quiet is a site that is telling you something.', 'ai-godmode' ); ?></p>
			<p><?php esc_html_e( 'And you, as the owner of the Cloudflare account, can always remove the lock and delete the records. That is correct. It is your account. What has been taken away is the AI\'s ability to do it.', 'ai-godmode' ); ?></p>
		</div>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function keys(): void {
		echo Admin_UI::card_open( __( 'The two keys', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="keys"><?php esc_html_e( 'One writes, one reads, and they are never the same key', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'Both keys belong to this site alone. If you run several sites through one endpoint, each one has its own pair and its own storage, so nothing you do on one site can reach, break or reveal another one\'s log.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'The ingest key', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'Lives on this site. It can add a record to this site\'s log and do nothing else. It cannot read the log back, it cannot list what is in it, it cannot remove anything, and it cannot touch any other site\'s log even by filing a record under that site\'s name. If this entire website were taken over tomorrow, the attacker would gain the ability to append to one log they still could not read.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'The viewer key', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'Reads this site\'s log, and only this site\'s. It is shown to you exactly once, when you set the log up or rotate this site\'s keys, and it is deliberately never stored on this site.', 'ai-godmode' ); ?></p>
		<div class="godmode-callout">
			<p><strong><?php esc_html_e( 'Why we refuse to store it for you.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'A site that holds the key to its own audit log can be made to lie about it. Keeping that key off this machine is what makes the log independent evidence rather than just another thing on the server. Put it in your password manager the moment it is shown.', 'ai-godmode' ); ?></p>
			<p><?php esc_html_e( 'If you lose it, nothing is damaged and no records are lost. Rotate this site\'s keys to be issued a new one. Rotation affects this site and no other.', 'ai-godmode' ); ?></p>
			<p><?php esc_html_e( 'And do not paste it into a chat window, a screenshot or a support ticket. It is read-only, so a leak cannot corrupt the log, but anyone holding it can read everything your site has ever done.', 'ai-godmode' ); ?></p>
		</div>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function log_stopped(): void {
		echo Admin_UI::card_open( __( 'The log stopped recording', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="log-stopped"><?php esc_html_e( 'You are seeing a red banner. Here is what to do.', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'While the log cannot record, every change is refused. Your site is not broken and visitors are unaffected. Reads still work. This is the plugin failing safe on purpose.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'The warning comes in two parts', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'The banner can be closed. Doing so hides it for twelve hours, and it comes straight back if the failure changes, because a different failure is new information.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'The red mark next to Settings in the admin menu cannot be closed. It stays for exactly as long as the problem does. That way the banner can stop interrupting you without the warning quietly disappearing, which is the failure mode that matters: an alarm nobody can see is the same as no alarm.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'Read the log before you fix anything', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'Open the log with your viewer key and look at the last records before it went quiet. If something was tampering with this site, that is where the evidence is, and rotating keys first only tells you what happened afterwards.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'The likely causes, in order', 'ai-godmode' ); ?></h4>
		<ol>
			<li><strong><?php esc_html_e( 'This site was unregistered or its key was changed at Cloudflare.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'You, or somebody with your Cloudflare login, removed or edited this site\'s entry in the endpoint\'s key registry. Innocent, and the most common cause. Setting up or rotating another site cannot do this: each site has its own keys, and one site\'s setup never touches another\'s.', 'ai-godmode' ); ?></li>
			<li><strong><?php esc_html_e( 'Cloudflare had a wobble, or your server lost outbound network access.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'Usually clears by itself. The banner disappears on its own once a record gets through.', 'ai-godmode' ); ?></li>
			<li><strong><?php esc_html_e( 'Somebody deliberately broke the recording.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'The least likely and the reason all of this exists. Breaking the log is exactly what you would do first if you wanted to work unobserved.', 'ai-godmode' ); ?></li>
		</ol>

		<h4><?php esc_html_e( 'How to fix it', 'ai-godmode' ); ?></h4>
		<p><?php esc_html_e( 'Go to the Maintenance tab and rotate this site\'s keys. You will need a fresh Cloudflare API token, and there is a direct link to the page that creates one. Rotation issues a new ingest key and a new viewer key for this site, leaves every other site alone, and does not touch a single existing record.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'Then press Send a heartbeat now to prove the whole path works again. The banner clears as soon as one record gets through.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function ack_unlogged(): void {
		echo Admin_UI::card_open( __( 'Running without a record', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="acknowledge-unlogged"><?php esc_html_e( 'acknowledge_unlogged', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'When the log cannot record a change, the change is refused and the error explains why. Sometimes you genuinely need to proceed anyway, often to fix the very thing that broke the logging.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'For that, an AI agent can re-run the same action with acknowledge_unlogged set. The action then runs, and the fact that it ran unobserved is written into the local log as a gap marker and pushed off-site when the connection returns.', 'ai-godmode' ); ?></p>
		<div class="godmode-callout">
			<p><?php esc_html_e( 'This is deliberately awkward. It is an option the assistant does not normally pass, so it cannot be used by accident or slipped in as a default. Every use leaves a mark saying a gap was created on purpose, and an audit trail full of those marks is itself a finding.', 'ai-godmode' ); ?></p>
		</div>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function reading_the_log(): void {
		echo Admin_UI::card_open( __( 'Reading the log', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="reading-the-log"><?php esc_html_e( 'Two ways in', 'ai-godmode' ); ?></h3>
		<p><strong><?php esc_html_e( 'From this site.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'The Off-site Log tab has a box for your viewer key. It fetches the records and rebuilds every fingerprint using this site\'s own PHP, which makes it the authoritative check. The key is used for that one page load and never stored.', 'ai-godmode' ); ?></p>
		<p><strong><?php esc_html_e( 'From anywhere else.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'The endpoint serves its own page. Open it in any browser, on any device, and enter the viewer key. This one keeps working when this site is down, hacked or gone, which is precisely when you will want it. Bookmark it somewhere that is not this server.', 'ai-godmode' ); ?></p>

		<h4><?php esc_html_e( 'What a failed check means', 'ai-godmode' ); ?></h4>
		<ul>
			<li><strong><?php esc_html_e( 'Missing numbers.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'A record that should exist does not. The loudest possible signal.', 'ai-godmode' ); ?></li>
			<li><strong><?php esc_html_e( 'Broken links.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'A record no longer points at the one before it, so something was replaced by somebody who understood the chain.', 'ai-godmode' ); ?></li>
			<li><strong><?php esc_html_e( 'Fingerprint mismatch.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'A record\'s contents were edited by somebody who did not understand the chain.', 'ai-godmode' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'A log that starts partway through is normal, not a fault. If you switched the off-site log on after running the plugin for a while, numbering begins wherever it had got to, and the check does not report everything before that as missing.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function mcp(): void {
		echo Admin_UI::card_open( __( 'Connecting your AI', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="mcp"><?php esc_html_e( 'This plugin does not talk to your AI by itself', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'AI Godmode registers its abilities with WordPress. Something else has to publish them to your assistant, and that something is an MCP server. Without one installed, this plugin will appear to do absolutely nothing, and that is the single most common reason people think it is broken.', 'ai-godmode' ); ?></p>
		<p><?php
		/* translators: %s: link to the Easy MCP AI plugin page */
		echo wp_kses_post( sprintf( __( 'Any MCP server that reads the WordPress Abilities API will work. A free one is %s.', 'ai-godmode' ), '<a href="https://wordpress.org/plugins/easy-mcp-ai/" target="_blank" rel="noopener">Easy MCP AI</a>' ) );
		?></p>
		<p><?php esc_html_e( 'After switching abilities on here, your AI client usually needs to refresh its list of tools before it can see them. In most clients that means reconnecting the site, and in some it means restarting the app.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function tool_drawer(): void {
		echo Admin_UI::card_open( __( 'The tool drawer', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="tool-drawer"><?php esc_html_e( 'Why most tools are not listed', 'ai-godmode' ); ?></h3>
		<p><?php esc_html_e( 'An AI client sends the definition of every tool it holds back to the model with every request, used or not. On a site running Easy MCP AI plus this plugin that was 243 tools and about 70,000 tokens per request, measured on 2026-10-07, and most of those tools are needed once a month. So the connector lists a small core set, and the rest sit in a drawer.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'A drawer tool is not switched off. It is registered, switched and audited exactly as before; it is just absent from the list the client fetches. Four listed abilities reach it: find searches this plugin\'s abilities and returns the input schema; call runs one by name, through all of its own gates and under its own name in the audit log; wp-find and wp-call do the same for Easy MCP AI\'s own tools, running them through Easy MCP AI\'s complete pipeline. The descriptions of find and wp-find carry the names of everything currently in the drawer, so the model knows what it can ask for.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'Easy MCP AI is not modified. It serves its connector through a normal WordPress REST route, and this plugin shortens the listing on its way out using WordPress\'s own filter. Easy MCP AI\'s call path never consults the listing, so a drawer tool it runs gets every one of its checks.', 'ai-godmode' ); ?></p>
		<div class="godmode-callout warn">
			<p><?php esc_html_e( 'What you give up: the client can no longer prompt you per tool for anything in the drawer, because to the client the drawer is one tool. The gating for drawer tools is entirely server-side: this plugin\'s switches and audit log, and Easy MCP AI\'s switches and approvals. Keep any ability you want the client to prompt you about individually in the always-loaded set.', 'ai-godmode' ); ?></p>
		</div>
		<p><?php esc_html_e( 'What a tick means on the Tool Drawer tab: ticked is always listed, which means the tool\'s definition is in the list the AI client fetches and is sent to the model with every message, used or not. Unticked is in the drawer, which means it is not sent with every message and the AI reaches it on demand through find and call. A tick on that tab never switches a tool on or off; the Abilities tab does that, and a switched-off tool refuses whether it is listed or in the drawer.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'Every family and every plugin on the Tool Drawer tab is an expandable section with a master checkbox that ticks or unticks the whole section. Abilities published by other plugins stay always listed unless "Put other plugins\' abilities in the drawer too" is on; a tick or untick on an individual tool wins either way.', 'ai-godmode' ); ?></p>
		<p><?php esc_html_e( 'After changing the drawer, reconnect the AI client so it fetches the new list. The find, call, wp-find and wp-call abilities are new power and ship switched off like everything else; switch them on here and, if you use Easy MCP AI, enable them there too.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function refuses(): void {
		echo Admin_UI::card_open( __( 'What it refuses to do', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="what-it-refuses"><?php esc_html_e( 'Guardrails that hold even when everything is armed', 'ai-godmode' ); ?></h3>
		<ul>
			<li><?php esc_html_e( 'Reading authentication keys and salts. Refused outright.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Handing back your database password or salts from a config file. Redacted, including custom secrets your site added itself.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Returning password hashes from the users table. Redacted.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Sending anything other than a SELECT through the read-only query ability.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Reading files by absolute path, or climbing out of the site folder.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Deleting the WordPress root or other protected directories.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Writing, moving, deleting or changing permissions on wp-config.php, the root .htaccess, .env files, .git folders or its own files. Reading them is allowed, scrubbed.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Promising that file backups and database exports are hidden from the web. The .htaccess it writes holds on Apache and LiteSpeed; on nginx and IIS the files are protected only by an unguessable name, and the response says so.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Deactivating or deleting AI Godmode itself.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Deleting the active theme.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Removing the master capability from the administrator role, or deleting the signed-in user. Both would lock everyone out.', 'ai-godmode' ); ?></li>
			<li><?php esc_html_e( 'Reading or writing this plugin\'s own settings through the option abilities, so the log cannot be reconfigured that way.', 'ai-godmode' ); ?></li>
		</ul>
		<div class="godmode-callout warn">
			<p><?php esc_html_e( 'Every one of these is a rule inside the individual abilities. run-php is code, not an ability with rules, so it is bound by none of them. Treat that list as protection against mistakes, not as a wall around a determined attacker.', 'ai-godmode' ); ?></p>
		</div>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	// -----------------------------------------------------------------------
	private function uninstalling(): void {
		echo Admin_UI::card_open( __( 'Turning it all off', 'ai-godmode' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		?>
		<h3 id="uninstalling"><?php esc_html_e( 'From quickest to most thorough', 'ai-godmode' ); ?></h3>
		<ul>
			<li><strong><?php esc_html_e( 'Disarm.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'Instant, reversible, keeps your choices. The right move during a scare.', 'ai-godmode' ); ?></li>
			<li><strong><?php esc_html_e( 'Deactivate.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'Disarms everything on the way out, so it cannot come back armed.', 'ai-godmode' ); ?></li>
			<li><strong><?php esc_html_e( 'Delete.', 'ai-godmode' ); ?></strong> <?php esc_html_e( 'Removes the plugin and its settings from this site.', 'ai-godmode' ); ?></li>
		</ul>
		<p><?php esc_html_e( 'None of these touch your off-site records. The bucket, the lock and every record stay exactly where they are, in your Cloudflare account, readable with your viewer key. Removing an audit trail is not something an uninstaller should ever do on your behalf. When you want it gone, delete it in the Cloudflare dashboard, where you will have to remove the retention lock first.', 'ai-godmode' ); ?></p>
		<?php
		echo Admin_UI::card_close(); // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
