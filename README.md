# AI Godmode

## First: A Note From The Author

This plugin was created by John P. The guy who brought the world HTMLHelp.com,
Woopra, GeekBeat.TV, and OneMansBlog. The plugin is, and always will be, FREE.
As in Beer.

- There will never be a "PRO" version of this. It's already pro.
- Your usage will never be tracked.
- I will ask nothing whatsoever of you. Except maybe to pay it forward.
- The code is 100% open and reviewable on GitHub.
- I encourage and welcome improvements for all the world to use!

## What It Is

A WordPress plugin that gives an AI agent server-administrator access to your
site: the database, files, options, plugins, themes, users, scheduled tasks,
diagnostics, and PHP.

- Everything ships switched off.
- There's a SECOND Master switch as well, to make sure you mean it.
- There is off-site, Cloudflare based, logging your AI can't tamper with.

It will make your life beautiful again.

By John Pozadzides. Free, GPLv2 or later.

## What It Does

TWO Big things. (It's like a Buy One / Get One, only both are Free.)

### First: Admin Abilities

AI Godmode registers its actions as WordPress Abilities (core since 6.9),
so any MCP client connected through a bridge plugin can find and run them.
It covers the admin work content plugins don't touch:

- **Options and transients:** read, write, list, delete
- **Database:** list and describe tables, read-only queries, write queries, full export
- **Files:** list, read, search, write, patch, copy, move, delete, zip, unzip
- **Plugins and themes:** install, activate, update, delete, switch
- **Users and roles:** create, edit, delete, capabilities, application passwords
- **Cron:** list, run, schedule, unschedule
- **Diagnostics:** environment, Site Health, PHP info, constants, hooks, error log
- **Run PHP:** arbitrary code, for when nothing else fits

### Second: The Tool Drawer

It saves MASSIVE token use for all registered MCP AI abilities by any plugin
by allowing you to move all abilities into a virtual "Tool Drawer".

Normally, when an MCP advertises abilities to your AI, the AI will load every one
of them before even saying "hi". AI Godmode lets you change that so only a select
core group of abilities load right off the bat, but the rest can be pulled as needed.

This can save your token usage and speed up everything you do by 75% or more.

## Safety

- **Off by default.** An ability runs only when the master switch is armed and
  its own switch is on. Updates never switch new abilities on.
- **Admins only.** Every call checks `manage_options`, whatever the switches say.
- **Guardrails.** Files stay inside the WordPress root. wp-config.php, .htaccess,
  .env and the plugin's own files can't be written. Passwords, salts and keys are
  scrubbed from results.
- **Audit log.** Every action is recorded before it runs, in a tamper-evident
  hash chain. You can send the log to your own Cloudflare account. Once you do,
  changes refuse to run if the log can't record them.
- **Run PHP is total control.** None of the guardrails apply to it. Leave it off
  unless you need it.

## Works With AI Scratchpad

AI Godmode gives your AI the keys to the site.
[AI Scratchpad](https://github.com/johnpoz/ai-scratchpad) gives it a memory of
what it did with them.

## Requirements

- WordPress 6.9 or later
- PHP 8.0 or later
- An MCP bridge plugin, such as the WordPress MCP Adapter or Easy MCP AI

## Install

1. Download the latest zip from [Releases](../../releases).
2. In WordPress, go to Plugins, Add New, Upload Plugin.
3. Activate it, then open Settings, AI Godmode.
4. Arm the master switch and turn on only the abilities you need.
5. Allow the Tool Drawer to organize ALL of your MCP abilities.
6. Send a "Thanks John P.!" to @johnpoz for making your life better.

## License

GPLv2 or later. See [LICENSE](LICENSE).
