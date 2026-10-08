=== AI Godmode ===
Contributors: johnpoz
Tags: ai, mcp, abilities api, ai agents, administration
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Gives an AI agent real server-administrator reach over WordPress through the Abilities API. Everything ships switched off. The name is the warning.

== Description ==

AI Godmode registers server-administrator actions as native WordPress Abilities (core since 6.9), so any consumer of the Abilities API, including an MCP client through a bridge, can discover and run them.

It deliberately covers the tier nothing else covers. Content abilities are already handled by other plugins and increasingly by core. This one grants the database, the filesystem, the options table, plugins and themes, users and roles, scheduled events, diagnostics, and arbitrary PHP execution.

**The name is the safety argument.** Everything is off when you install it: a master switch, plus one switch per ability. An ability runs only when the master switch is armed AND its own switch is on. Exposure flags are never the security boundary; every ability enforces a WordPress capability check on every call, whatever the switches say.

= What it can do =

65 abilities across thirteen families, 25 read and 40 that change something, plus a SiteGround cache purge on SiteGround hosting, two Easy MCP AI drawer doors when that plugin is active, and ten Code Snippets abilities when that plugin is active. Every one ships switched off.

* **Options** read, write, list and delete options; get, set and delete transients. Secret and salt names are refused.
* **Database** list and describe tables; capped SELECT queries with secrets scrubbed from results; write statements; a full SQL dump under an unguessable name in a directory that is web-denied on Apache and LiteSpeed.
* **Filesystem** list, read, search, write, patch (exact-match replace), copy, move, delete, chmod, zip and unzip anywhere under the WordPress root. Path escape is refused, writes are atomic, wp-config.php and the plugin's own files are off limits, and replaced files are backed up under unguessable names in a directory that is web-denied on Apache and LiteSpeed.
* **Plugins and themes** list, install from wordpress.org or a zip URL, activate, deactivate, update, delete, switch theme.
* **Users and roles** full user CRUD, role and capability management, application passwords.
* **Scheduled events** list, run, schedule and unschedule cron events.
* **Diagnostics** Site Health, PHP info, defined constants with secrets redacted, a hook inspector, and an error log tail.
* **Site** fetch one of the site's own URLs from the server, purge every page cache found, flush permalinks.
* **Drawer** find and call for this plugin's abilities, wp-find and wp-call for Easy MCP AI's tools, so the connector lists a small core set and the rest are one lookup away.
* **run-php** executes supplied PHP as an administrator, capturing return value, output and thrown errors. This is the literal meaning of Godmode and it subsumes everything above.

= What it refuses to do =

The guardrails are enforced in code, not in documentation, and each one is exercised by the test suite:

* Reading authentication keys and salts. Refused outright.
* Returning database passwords or salts from a config file read. Redacted on the way out, including custom secrets the site added itself.
* Returning password hashes or activation keys from the users table. Redacted.
* Sending anything other than a SELECT to the read-only query ability. Refused.
* Reading by absolute path, or climbing out with `..`. Refused.
* Deleting the WordPress root or other protected directories. Refused.
* Deactivating or deleting AI Godmode itself. Refused.
* Deleting the active theme. Refused.
* Removing the master capability from the administrator role, or deleting the signed-in user. Refused, because both lock everyone out.

= The audit log =

Every execution writes an intent record **before** the action runs, and a completion record afterwards. Records are hash chained with per-site sequence numbers, so gaps and edits are detectable after the fact. Refusals are recorded too, as failures rather than as silence.

A log kept only on this site would be worthless against the thing it exists to record, because this plugin can erase it and `run-php` can delete the logger. So AI Godmode can build an append-only log in **your own Cloudflare account**, and mutations refuse to run when that log cannot record them.

Setting it up takes one pasted Cloudflare token. The plugin creates the storage bucket, puts an append-only endpoint in front of it, applies a retention lock, mints the keys, and then **discards the token permanently**. What stays on your site is an endpoint and a key that can do exactly one thing: append.

* There is no delete route in the endpoint's source, so no key reaches one.
* Writes are conditional on the sequence number, so history cannot be rewritten.
* The key that can read the log is never stored on the site, because a site that can read its own audit log can be made to lie about it.
* A separate page served by the endpoint lets you read the log when the site itself is broken, which is exactly when you will want it.

What this defends against is a capable agent making a catastrophic mistake, leaving a complete and unalterable record right up to the moment it destroyed something. It is tamper evidence, not tamper proofing. Someone with `run-php` and bad intent can stop the site talking; what they cannot do is quietly rewrite what it already said. The heartbeat exists so that going quiet is itself visible.

= Before you install this =

Read this part twice.

* Take a backup you have actually restored from once, not one you assume works.
* Use strong, unique administrator passwords, and two-factor authentication.
* Set up the off-site log before you arm anything destructive.
* Understand that arming this plugin means an AI agent can delete your site, and that you chose that.

= Requires an MCP server =

AI Godmode registers abilities. It does not talk to your AI by itself. A separate MCP server publishes those abilities as tools your assistant can call. If you have no MCP server on the site, installing this will appear to do nothing at all.

== Installation ==

1. Upload the `ai-godmode` folder to `wp-content/plugins`, or install the zip through Plugins > Add New > Upload Plugin.
2. Activate it. Nothing is armed and nothing is switched on.
3. Go to Settings > AI Godmode and set up the off-site audit log first.
4. Arm the master switch, then switch on only the abilities you actually need.
5. Reconnect your MCP client so it refreshes its tool list.

== Frequently Asked Questions ==

= Is this safe? =

No. It is careful, which is a different thing. It ships inert, it makes you arm it deliberately, it refuses the handful of actions that would lock you out of your own site, and it writes an off-site record of everything it does before it does it. None of that stops a determined mistake from deleting your content. That is the trade you are making, and the plugin is named so you cannot make it by accident.

= Can the AI switch abilities on by itself? =

Not through the options abilities: the plugin's own settings are on the denylist those refuse to touch. But `run-php`, once armed, can do anything PHP can do, including changing those settings. That is what arbitrary code execution means, and pretending otherwise would be dishonest.

= Which MCP server plugin does it need? =

Any plugin that publishes WordPress Abilities as MCP tools. Easy MCP AI is the one it is tested with, and the drawer's wp-find and wp-call abilities reach Easy MCP's own tools. The WordPress MCP Adapter and other Abilities API bridges work for AI Godmode's own abilities. The tool drawer shortens the tool list of any MCP server plugin that answers through the WordPress REST layer.

= Where is the code? =

https://github.com/johnpoz/ai-godmode

= Does it phone home? =

No. The only outbound request it ever makes is to the audit endpoint in your own Cloudflare account, and only when you have set one up.

= What if the audit log goes down? =

Mutations refuse to run and return an error naming the reason. A caller that genuinely needs to proceed must pass `acknowledge_unlogged`, and that override is itself recorded, then pushed upstream as a gap marker when the log comes back. Reads are unaffected.

== Screenshots ==

1. The settings screen: the master arm switch and the Abilities tab, every ability with its own switch and a READ or WRITE badge.
2. The Tool Drawer tab: one expandable section per family and per plugin, each with a master checkbox and a count of what is listed.
3. One drawer section expanded, showing which tools are listed and which wait in the drawer.
4. Measured on live sites: the tool list sent with every request fell 77 percent, and a five-task benchmark fell 54 to 60 percent with every answer correct.

== Changelog ==

= 0.8.4 =
* Changed: new README. The plugin's home is now https://github.com/johnpoz/ai-godmode, and the plugin header and readme point there. No code, setting or ability changed.

= 0.8.3 =
* Changed: the code passes WordPress Plugin Check (the directory's review tool) with no errors and no warnings. Translators comments on every placeholder string; the master-switch help tip annotated as escaped; every form field in the off-site log admin sanitized with sanitize_text_field() and its nonce check named; file deletion, renaming and permission changes go through WP_Filesystem_Direct (direct on purpose: an FTP or SSH transport cannot be used inside a REST request), directory creation through wp_mkdir_p(), temporary download files through wp_delete_file(), and the database export is appended in pieces with file_put_contents() instead of a held-open handle.
* Changed: db-query-write's restore of the plugin's own rows now passes the option ids through $wpdb->prepare() placeholders; transient-delete's count of expired transients is a prepared statement.
* Note: the three eval() calls (run-php, snippets-run, the snippet syntax check), the direct $wpdb use in the database and options families and the cache plugins' own hooks are what the plugin is for; each site carries a comment saying so and why it is safe behind the switches and the audit log. Nothing was removed.
* Harness: check-hardening runs the real WP_Filesystem_Direct and now proves fs-mkdir, fs-chmod, fs-move, fs-delete (including a symlink inside the tree), atomic fs-write and a complete db-export; tools/check-plugin-review.sh mirrors Plugin Check's PHPCS checks.

= 0.8.2 =
* Changed: the Tool Drawer tab now says what a tick means. A legend above the sections reads "Ticked = always listed" (sent to the AI with every message) and "Unticked = in the drawer" (fetched on demand through find and call). Every row carries an "Always listed" or "In the drawer" label that follows its checkbox live, every section counts both sides, and the Help tab says the same. A tick on this tab never switches a tool on or off; that stays on the Abilities tab.

= 0.8.1 =
* Fixed: the Tool Drawer tab was blank in a real admin request. The tab named a drawer ability class before the ability files were loaded (an MCP request had already loaded them, which is why testing through the connector missed it). The harness now renders that tab first on a fresh process, which reproduces the fault.

= 0.8.0 =
* Added: fs-patch, an exact-match replace inside a file. The find text must occur exactly the expected number of times (default once) or nothing is written; same protections, backup and atomic write as fs-write. A one-line change to a large file no longer costs reading and rewriting the whole file.
* Added: plugin-install can now overwrite an installed plugin in place (an upgrade or downgrade) when overwrite is true. Overwrite requires sha256, the digest of the zip; the download is checked before anything is unpacked and the install aborts on a mismatch. It will not overwrite AI Godmode itself.
* Added: http-fetch, a GET or HEAD of one of this site's own URLs made from the server, returning status, headers, timing and a scrubbed body excerpt. Same host only, no cookies, no Authorization header.
* Added: cache-purge, which flushes the object cache and every page cache it recognises and finds active (SiteGround, LiteSpeed, WP Rocket, W3 Total Cache, WP Super Cache, WP Fastest Cache, Breeze, Cache Enabler, Hummingbird, Autoptimize, Kinsta, WP Engine, Elementor CSS), optionally the PHP opcode cache, and reports what was purged and what was absent.
* Added: permalinks-flush, a soft or hard rewrite-rules flush.
* Tool Drawer tab rebuilt: every family and every plugin is an expandable section with a master checkbox that ticks or unticks the whole section, and a count of what is listed. New switch "Put other plugins' abilities in the drawer too": abilities published by other plugins stay listed by default and go in the drawer when it is on; a tick on an individual tool always wins either way.
* The four new abilities ship switched off like every other. fs-patch is in the default always-loaded set; the other three start in the drawer.
* Fixed: plugin-install returned an author field its output schema did not declare, so the Abilities API reported every successful install as invalid output (the install itself went through). The field is now declared.

= 0.7.0 =
* Added: the tool drawer, a compact tool surface. An AI client sends every tool definition it holds back to the model with every request; on a site running Easy MCP AI plus this plugin that was 243 tools and about 70,000 tokens per request, measured 2026-10-07. The connector now lists a small core set (operator-configurable on the new Tool Drawer tab) and the rest sit in a drawer: still registered, switched and audited as before, reachable through four new abilities. godmode/find searches this plugin's abilities and returns the input schema; godmode/call runs one by name through all of its own gates; godmode/wp-find and godmode/wp-call do the same for Easy MCP AI's own tools, through Easy MCP AI's complete call pipeline. The find descriptions carry the names of everything in the drawer so the model knows what it can ask for.
* No MCP server plugin is modified. The listing is shortened on the way out through WordPress's own rest_post_dispatch filter, keyed on the JSON-RPC tools/list shape, so it applies to Easy MCP AI, the WordPress MCP Adapter and any other server that answers through the REST layer. Their call paths never consult the listing, so a drawer tool they run gets every one of their checks.
* The drawer switch ships on, because it removes exposure rather than granting power. The four new abilities ship off like everything else. Reconnect the AI client after upgrading so it fetches the shorter list.
* Added tools/check-surface.php (55 checks) and tools/fake-easy-mcp.php.

= 0.6.3 =
* Fixed: the secret scrubber gave up on any quoted value longer than about 10 KB (a data URI, a minified bundle, a long JSON string) and returned an empty result. With 0.6.2 scrubbing whole files, one such string anywhere made fs-read return nothing for the entire file and fs-search skip it, silently. The patterns now use possessive runs and handle multi-megabyte values; redaction is unchanged on 300,000 generated cases. If scrubbing ever fails, fs-read now refuses with an error and fs-search lists the file under unscrubbable, instead of returning empty text.
* Security: fs-copy could copy wp-config.php or .env to a plain file under uploads, where the web server hands it out raw, which undid every scrubbing rule. fs-copy and fs-move now refuse sources that are or contain whole-secret files.
* Security: a .pem is treated as secret only when it holds a private key, so public CA bundles such as cacert.pem no longer make fs-zip drop files from ordinary plugins or block copying them.
* Fixed: masked values written back replaced real secrets with the text "[redacted]". read-option followed by write-option, the natural way to change one field of a settings array, would have destroyed a payment gateway's keys. write-option and transient-set now put the stored secret back wherever the mask was left in place and refuse a mask with nothing behind it; write-option no longer echoes the unmasked value. db-query-write refuses statements carrying a marker from a scrubbed read, and fs-write refuses content that adds scrub markers to a file.
* Added tools/check-hardening.php, which executes every 0.6.2 and 0.6.3 rule. It fails 19 checks against 0.6.1 and 16 against 0.6.2.

= 0.6.2 =
* Security: fs-read scrubbed only the bytes it returned, so a base64 read, or a text read starting mid-line, handed back config secrets unscrubbed. The whole file is scrubbed first and offsets now count positions in the scrubbed text. fs-search scrubs the whole file rather than the 300-character fragment for the same reason, and fs-zip leaves wp-config.php, .env, .htpasswd, *.pem and *.key out of archives, since an archive cannot be scrubbed.
* Security: the master switch (godmode_armed) and the per-ability switches (godmode_enabled_abilities) were missing from the option denylist, so an armed assistant could arm more of itself through write-option. Every godmode_ option is now denied to the option and transient abilities, db-query-write refuses any statement naming one, and if a statement reaches those rows by other means (by id, whole-table update) they are restored and the call is reported as refused.
* Security: an option whose name is not on the denylist could still hold secrets inside its value (a payment gateway's api_login_id and api_transaction_key, a client_secret, an access_token). read-option, delete-option, write-option's previous value and transient-get now mask entries under secret-looking keys. db-query-read does the same inside serialized option and meta values, and masks any cell shaped like a password hash or a long bare token whatever its column is called, so an alias cannot dodge the column scrub. Best effort by key name and shape.
* Security: the backup and export directories rely on .htaccess, which nginx and IIS ignore. Backups and exports now carry a 32-character random name, the directories get an index.php beside the .htaccess, and on a server that is not Apache or LiteSpeed the response carries a warning saying so. The ability descriptions no longer promise web denial unconditionally.
* Security: fs-mkdir and the fs-unzip target only checked for ".." and a leading slash, so a symlinked parent could lead outside the root. Both, and fs-write with create_dirs, now resolve through the same realpath check as every other path. fs-unzip refuses symlink entries and entries that resolve outside the target through anything already on disk; fs-write, fs-copy, fs-move and fs-zip refuse to write through a symlink.
* Security: wp-config.php, the root .htaccess, .env files, .git trees and AI Godmode's own directory can no longer be written, moved, deleted, chmodded or unzipped over. Reading them stays allowed, scrubbed.
* Security: db-query-read strips comments and string literals before its checks, refuses executable comments, and refuses EXPLAIN ANALYZE, FOR UPDATE, LOCK IN SHARE MODE, SLEEP, BENCHMARK, LOAD_FILE and the information_schema, performance_schema and mysql schemas. The row cap is enforced by SQL: a missing LIMIT is appended and a LIMIT above the cap is refused, instead of fetching every row and slicing.

= 0.6.1 =
* Fixed: activating a PHP snippet that declares a function was refused as a duplicate of itself. The pre-activation test runs the code, which declares the function, and Code Snippets' own activate then validated again and found it. Found on the first live test. The validator already runs inside the test, so activation now flips the switch directly after it passes.

= 0.6.0 =
* New: ten Code Snippets abilities (list, get, create, update, replace, activate, deactivate, trash and restore, run once, revert). They appear only when the Code Snippets plugin is active, work through its own functions, and ship switched off like everything else.
* New snippets are always created switched off. The code of an active snippet changes only by an exact search and replace, and only after the new code parses and passes Code Snippets' own test. A failed check writes nothing, where Code Snippets' own save would switch the snippet off without saying so.
* The previous code is saved as a numbered version before every change, up to twenty per snippet, so any change can be reverted.
* Switching a snippet off now works even when that snippet crashes every page: for that one request, Code Snippets is told to skip that one snippet. Nothing else is skipped, and only while AI Godmode is armed and that ability is on.
* If a Code Snippets update removes a function these abilities depend on, the group hides itself and the Abilities tab names what is missing.

= 0.5.1 =
* Refuse to connect a site to an endpoint running older code than the plugin. Previously the site would store a key the old endpoint could not recognise and then fail closed on every action, with nothing explaining why.
* Add an Update endpoint software button. The routine existed and nothing called it, so there was no supported way to move an account onto a newer endpoint.
* Add a screen for attaching an archived bucket, and bind it on the endpoint. The setting was read and honoured but nothing could write it, so archived logs could not be reached at all.
* An append that reuses a sequence number now answers 409 instead of 500. The write was always refused and no record was ever altered, but a flat 500 could not be told apart from a broken endpoint, and telling those apart is the point of the alarm.
* Correct the setup and rotation screens, which asked for three token permissions and listed four.

= 0.5.0 =
* Fixed, and this is the reason for the release: every site connected to one Cloudflare account shared a single bucket and a single pair of keys. Setting up a second site minted a fresh pair and overwrote both, which silently cut off every site already connected and invalidated the operator's only copy of the viewer key. Nobody was told. The records were never at risk, but a site could stop recording without anybody noticing, and a viewer key could be destroyed with no way back except owning the Cloudflare account.
* Every site now gets its own R2 bucket, its own ingest key and its own viewer key. One Worker still serves the whole account, because the code is identical for every site and one copy is one thing to upgrade.
* Connecting a site now only ever adds: a bucket, one binding alongside the existing ones, and rows in a key registry. Nothing already deployed is rewritten, so nothing already working can break.
* The key registry is a Workers KV namespace holding hashes, never keys. Setup therefore needs one more token permission than before: Workers KV Storage, Edit.
* The endpoint resolves the site from the key that was presented, so a site cannot write into another site's log even by putting that site's name on the record. It is refused with a 403.
* Rotation is per site. It mints a new pair for the site you are on, leaves every other site alone, and no longer re-uploads the Worker. Updating the endpoint software is now its own action, because replacing the code affects every site in the account and deserves to be chosen deliberately.
* Fixed: the local log and the off-site log shared one sequence counter while only mutations were sent off-site, so every read consumed a number that never arrived and the off-site chain checker reported missing records on a perfectly healthy site. Each log now numbers and chains its own records, and a sequence number is consumed only when the record lands. A gap in the off-site log once again means exactly one thing.
* The bucket lock now covers the records prefix rather than the whole bucket. Records stay immutable; the bucket itself stays manageable by its owner.
* Existing installs: nothing on your current endpoint is touched by upgrading. Moving a site onto its own bucket is a deliberate act, and its old records stay readable through the same viewer key as an archive.

= 0.4.0 =
* Renamed: the plugin is AI Godmode, one word. The plugin folder, main file and text domain are now `ai-godmode` and `ai-godmode.php`, the code namespace is `AIGodmode`, and every label, heading and message uses the new name.
* Nothing you configured changes. Ability names (`godmode/...`), stored settings, the arm switch state, the per-ability switches, the local audit buffer and the off-site log connection all keep their existing names, so a site moving from the old folder picks them up as they are.
* The Cloudflare resources the off-site log creates keep their existing names (`ai-god-mode-audit`), because logs already recording under those names on live accounts cannot be renamed and must not be split.
* New: if the old `ai-god-mode` folder is still present, every admin screen says so and explains how to remove it safely. Do not remove it with the Delete link on the Plugins screen: that runs the old copy's uninstall routine, which erases the settings this copy is using, including the off-site log keys.
* Upgrading from 0.3.x: deactivate the old AI God Mode first (both copies cannot run at once), install and activate AI Godmode, arm it again (deactivating always disarms), then remove the old folder with a file manager, SFTP, or `godmode/fs-delete`.

= 0.3.7 =
* Fixed: `run-php` failed every time in some MCP clients, including Claude Desktop, while working normally in others. The site ran the code correctly and wrote its audit record every time; the result was rejected on the way back, so nothing appeared in any server log and the cause looked like a firewall or a transport fault. It was neither.
* The cause: this plugin declared nullable output fields the correct JSON Schema way, as `{"type": ["string", "null"]}`. Easy MCP AI rewrites that, before advertising the tool, into `{"type": "string", "nullable": true}`. `nullable` is an OpenAPI 3.0 keyword and has no meaning in JSON Schema, which is what MCP output schemas are, so a validating client ignores it and reads the field as a plain string. `run-php` returns `error: null` on every success, so every successful call violated its own advertised schema and was thrown away. Abilities whose nullable field usually holds a value failed only occasionally, which is why this looked specific to `run-php`.
* Nullable output fields are now declared with `anyOf`, which passes through that rewrite untouched and validates correctly in both WordPress core and strict MCP clients. Verified against the live transform: the old shape produces the OpenAPI keyword, the new shape stays as `anyOf` with a real null member, and the returned value is no longer altered in transit. Affects `run-php`, `write-option`, `user-create`, `transient-get`, `transient-delete`, `cron-schedule` and `fs-write`.
* The schema checker now fails the build on any `type` array anywhere in a definition, so this cannot come back by accident.
* No ability, guardrail, default or interface behaviour changed, and no returned value changed. Only the way the types are written down.

= 0.3.6 =
* Fixed: the 0.3.4 package was built without `worker/audit-sink.js`, so setting up the off-site audit log failed on any site that installed it. The plugin reads that file at run time and uploads it to Cloudflare as the Worker, which makes it part of the software rather than documentation, but the packaging rule in use treated the whole `worker/` folder as development material and left it out. The result installed, activated and ran normally, and only broke at the one moment it mattered. Nothing was wrong with anybody's Cloudflare account or token.
* Packaging is now a script with a manifest (`tools/package.sh`) instead of a remembered list of folders to exclude. It refuses to write a zip that is missing any file the running plugin reads, including the Worker source and every class the autoloader resolves, and refuses to write one that ships the development tools.
* The error you see if you ever do install an incomplete copy now says what happened and what to do about it, instead of naming a file path and leaving you to work out whether your Cloudflare token was at fault.

= 0.3.5 =
* The master switch at the top of the settings screen is now impossible to miss. It has a real heading, "Arm / Disarm Master Switch", a state line reading ARMED or DISARMED next to a coloured lamp, and a button roughly three times its old size. The reason is the most predictable support question this plugin could ever generate: somebody switches on every ability, nothing happens, and they never work out that one switch above the tabs governs all of them. While it reads DISARMED the panel now says so in plain words, and the button says what it does rather than just "Arm".
* The heading links into the Help section on arming, for anyone who wants the longer version.
* The render harness now renders the disarmed state, which it never did before, and asserts the heading, the lamp, the state word, the oversized button and the button copy in both states.

= 0.3.4 =
* Tabs reordered to Abilities, Activity, Off-site Log, Maintenance, Help.
* The Heartbeat card now explains, above the checkbox, that the heartbeat pings your Cloudflare endpoint once an hour while the off-site log is active, and when no off-site log is set up the card greys itself out, says so, and links to the screen that sets one up.
* The "Rotate when" items render as real bullets instead of unmarked indented lines, as do the lists in Help.
* Text now runs the full width of the card it sits in instead of wrapping short.

= 0.3.3 =
* The Kind column now says READ or WRITE instead of SAFE or WARNING. WARNING was doing the wrong job: it was a permanent label on every ability that can change something, which is a description of the ability rather than a warning about anything, and a label that is always on teaches people to ignore it. READ and WRITE state the fact. Both link to an explanation.
* WARNING is now a live readout rather than a category. A WRITE ability shows WARNING only while the off-site log has stopped recording, which is exactly when that ability is being refused. The badge tells you what is happening right now, not what is theoretically possible.
* New Help section explaining what a WRITE badge showing WARNING means and what to do about it.
* Fixed: the Help tab was fatal after the section rename. Caught by the render harness, which now also counts badges in both states.

= 0.3.2 =
* Fixed: rotating the keys could leave the site permanently unable to log. The new key was written to Cloudflare first and only saved locally after a verification round trip, so a slow confirmation made the plugin discard a key that Cloudflare had already accepted. The old key was dead by then, so there was nothing to fall back to and the only escape was rotating again. The key is now saved the moment Cloudflare accepts it, and confirmation is advisory.
* Fixed: the alarm could not clear itself. Once a failure was recorded, the status check returned it without ever testing the endpoint again, and only a successful change could retire it. Changes were exactly what the alarm was blocking, so fixing the log left the warning on forever. A successful test now clears the recorded failure, and the recorded failure is used to explain a problem rather than to decide there is one.
* Fixed: the verification wait could run for nearly three minutes inside a single admin request, long enough to exceed PHP's execution limit and kill the request part way through. It is now bounded well under that.
* Rotation no longer asks you to remember which permissions you chose during setup. Both screens show the same three-row list, generated from one place so they cannot drift apart, and both warn about the Use my IP button.

= 0.3.1 =
* The alarm now comes in two parts, because a banner nobody can close becomes furniture and a banner everybody closes is no alarm at all. The banner can be dismissed, which hides it for twelve hours and brings it straight back if the failure changes. A red mark on the admin menu, next to both Settings and AI Godmode, cannot be dismissed and stays for exactly as long as the problem does.
* Tabs reordered to Abilities, Maintenance, Activity, Off-site Log, Help.

= 0.3.0 =
* Security fix, and the reason for this release. The settings screen reported the off-site log as "Live" while every single change was in fact being refused. It decided this by calling an endpoint route that needs no key and always answers, so it was structurally incapable of noticing a rejected key. It now sends a test that validates the key and writes nothing, and it also trusts what the log itself reports, because a change that was actually refused is stronger evidence than any probe. A status light that cannot go red is worse than no status light.
* A site-wide alarm. When the off-site log stops recording, a banner appears on every admin screen and cannot be dismissed. The way to clear it is to fix the log. It tells you to read the log before fixing anything, because breaking the recording is the first thing anyone would do in order to work unobserved.
* The admin screen is now organised into tabs: Abilities, Off-site Log, Maintenance, Activity and Help, with the master arm switch kept above them.
* The Kind column no longer says "mutation", which meant nothing to anybody who had not read the source. Abilities are now labelled SAFE or WARNING, and both badges link to an explanation.
* A Help tab with fourteen sections, covering what the plugin is, what SAFE and WARNING mean, run-php, arming, what to do before arming, the off-site log and what it is actually for, the two keys, what to do when the log stops, running without a record, reading the log, connecting an AI, what the plugin refuses to do, and how to turn it all off. Question marks throughout the interface link into it.
* Rotating keys now also updates the endpoint software, so a fix shipped in a plugin update reaches an endpoint that was set up months ago. Rotation is the only moment the plugin is handed a token with the rights to do it.
* Rotation and setup now link straight to the Cloudflare page that creates a token, and the setup steps warn against the "Use my IP" button, which makes setup fail with an error that looks like a bad token.
* Fixed: the server-side receipt time always came back empty, because R2 omits custom metadata from listings unless it is explicitly requested.
* Added an ability filter for switching on only the ones that cannot change anything.
* No ability, guardrail or default changed. Everything still ships switched off.

= 0.2.3 =
* The off-site audit log, which was the last unbuilt part of the safety design, is now real. A Cloudflare Worker in front of an R2 bucket, with append and query routes and no delete route in the source at all, deployed onto the operator's own account.
* Provisioning from a single pasted Cloudflare token: creates the bucket, uploads the Worker, applies the retention lock, mints an ingest key and a viewer key, verifies the endpoint answers, then discards the token permanently. It is never written to the database.
* Retention is selectable: indefinite by default, or a fixed number of days, enforced by an R2 bucket lock that refuses deletion even to the account's own API credentials.
* Key rotation from the settings screen. Both keys at once; the old pair stops working immediately.
* An audit viewer in wp-admin that fetches the off-site log and re-verifies the whole hash chain with this site's own PHP. The viewer key is used for one request and never stored.
* Hourly heartbeat so that a site going quiet is distinguishable from a site whose logger was deleted.
* A standalone HTML view served by the Worker, so the log stays readable when WordPress is not.
* readme corrected: the previous release shipped with a stale stable tag and no changelog entries for 0.2.1 or 0.2.2.
* No changes to any ability, guardrail, schema or default. Every ability still ships off.

= 0.2.2 =
* Security: fs-write backups were written beside the original as `name.godmode-bak-TIMESTAMP`. That extension is not `.php`, so the web server served them as plain text; a backup of `wp-config.php` would have exposed the database password and every salt to anyone who guessed the name. Backups now go to a web-denied directory under uploads. Proven by fetching a backup over HTTP and getting 403 while a control file returned 200.

= 0.2.1 =
* Fixed: fields that can hold any JSON value were declared with a union type, which MCP bridges rewrite to the first member, silently turning "any value" into "text only" and rejecting every list or object result. They now use `anyOf`, which both WordPress and the bridges handle correctly.

= 0.2.0 =
* The full server-admin catalog on the 0.1.0 framework: 59 abilities across options, database, filesystem, plugins, themes, users and roles, cron, diagnostics and run-php, plus a conditional SiteGround cache purge and a reference tool. Every ability default-off, capability-checked, and audit-gated for mutations.

= 0.1.0 =
* Initial draft. Plugin scaffold, ability registrar with switch, capability and audit gates, settings screen with master arm and disarm plus per-ability switches, audit manager with hash-chained local ring buffer and stubbed Cloudflare sink, three abilities.
