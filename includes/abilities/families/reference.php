<?php
/**
 * godmode/get-reference: the prose documentation as a read tool, so ability
 * schemas stay small (the ~2 KB budget) and the vocabulary lives in one place
 * an agent can pull on demand.
 *
 * @package AIGodmode
 */

namespace AIGodmode\Abilities;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Get_Reference extends Base {
	public static function name(): string {
		return 'godmode/get-reference';
	}

	public static function definition(): array {
		return self::make(
			'core',
			'Get Reference',
			'How AI Godmode works: the switch model, the audit log and acknowledge_unlogged, the secrets denylist, filesystem path rules, and the list of abilities by family. Read this first when unsure how a family behaves.',
			self::obj(
				array(
					'topic' => array( 'type' => 'string', 'enum' => array( 'all', 'switches', 'audit', 'denylist', 'filesystem', 'families' ), 'default' => 'all' ),
				)
			),
			self::obj(
				array( 'topic' => self::str(), 'text' => self::str() ),
				array( 'topic', 'text' )
			),
			false
		);
	}

	public function execute( $input ) {
		$topic    = (string) ( $input['topic'] ?? 'all' );
		$sections = self::sections();
		if ( 'all' === $topic ) {
			$text = '';
			foreach ( $sections as $body ) {
				$text .= $body . "\n\n";
			}
			return array( 'topic' => 'all', 'text' => trim( $text ) );
		}
		return array( 'topic' => $topic, 'text' => (string) ( $sections[ $topic ] ?? 'Unknown topic.' ) );
	}

	private static function sections(): array {
		return array(
			'switches'   => "SWITCHES. Two gates. A master switch (armed or disarmed) and one switch per ability. An ability runs only when the master switch is armed AND its own switch is on. Everything ships off. Settings screen: Settings then AI Godmode. Turning an ability on marks it public so an MCP bridge exposes it; turning it off hides it and makes it refuse. Every switch change is written to the audit log.",
			'audit'      => "AUDIT. Before any ability runs, an intent record is written with the caller, the ability, and the redacted input. After it finishes, a completed record. Records carry a sequence number and a SHA-256 hash chain so a gap or edit shows. Mutations refuse to run when the audit log cannot record the intent, returning godmode_audit_unavailable; pass acknowledge_unlogged true to proceed anyway, which writes a gap marker. Reads log locally only; mutations also go to any configured remote sink. The off-site Cloudflare sink is the tamper-evident record; the local buffer alone is not tamper-proof.",
			'denylist'   => "DENYLIST. Option, transient and meta names, and config or query values, are refused or scrubbed when the name looks like a secret: the core salts and keys, and anything containing password, secret, token, api_key, license, credential, nonce, auth_, oauth, bearer, and similar. read-option and write-option refuse denied names; db-query-read and fs-read scrub denied values in results; user_pass hashes never leave the site. Widen or narrow with the godmode_option_denylist and godmode_option_denylist_patterns filters.",
			'filesystem' => "FILESYSTEM. Every path is relative to the WordPress root; '.' is the root. Absolute paths and '..' are refused, and a path that resolves (through symlinks) outside the root is refused. Protected directories (the root, wp-admin, wp-includes, wp-content, the plugins folder, and AI Godmode's own folder) cannot be moved or deleted. Writes are atomic (temp file then rename) and back up an existing file beside it as name.godmode-bak-TIMESTAMP unless backup is false. fs-read caps at 1 MB per call with an offset for more and scrubs secrets from text.",
			'families'   => "FAMILIES. options: read-option, write-option, list-options, delete-option, transient-get, transient-set, transient-delete. database: db-list-tables, db-describe-table, db-query-read (SELECT and friends, row-capped, scrubbed), db-query-write (one non-SELECT), db-export (SQL dump to a protected uploads folder). filesystem: fs-list, fs-read, fs-search, fs-write, fs-patch (exact-match replace), fs-mkdir, fs-copy, fs-move, fs-delete, fs-chmod, fs-zip, fs-unzip. plugins: plugin-list, plugin-install, plugin-activate, plugin-deactivate, plugin-update, plugin-delete. themes: theme-list, theme-install, theme-switch, theme-update, theme-delete. users: user-list, user-get, user-create, user-update, user-delete, role-list, role-create, role-delete, role-add-cap, role-remove-cap, app-password-list, app-password-create, app-password-delete. cron: cron-list, cron-run, cron-schedule, cron-unschedule. diagnostics: get-environment, site-health, php-info, constants, hooks-inspect, error-log-tail. site: http-fetch (this site's own URLs, from the server), cache-purge (object cache plus every page cache found), permalinks-flush. drawer: find, call, wp-find, wp-call (the doors to tools not listed by the connector). run-php: total control, read the code before running it. siteground-purge-cache: present only when Speed Optimizer is active. get-reference: this document.",
		);
	}
}
