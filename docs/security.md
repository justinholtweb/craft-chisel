---
title: Security model
slug: security
order: 20
summary: Who can run what, what can never run, and what happens to output and secrets.
---

> **Craft 6 only, and still in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is
> still in beta, and so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites
> first, and **use caution before running it on a production site** — keep backups, and expect
> things to change between betas. It does not run on Craft 5.

Running commands from a browser is remote code execution by design. Chisel layers its defences so a
single mistake doesn't open everything.

## Who

- **Admins only** by default. With `requireAdmin => false` in `config/craft/chisel.php`, non-admins
  with **Access Chisel**, **Run Artisan commands** and the nested **Run `<command>`** permission can run
  that command; **View Chisel run history** shows everyone's runs. Settings are always admin-only.
- **Never while impersonating** another user.
- **Password re-entry** before running or saving settings, separate from signing in. It lasts 5 minutes
  (`elevationSeconds`), and five wrong passwords lock it for a minute.

## Where

Commands don't run when `APP_ENV` is `production` or `allowAdminChanges` is off, unless
`enableInProduction` is set in the config file. `enabled => false` switches Chisel off everywhere.
The history stays readable.

## What

Every command is classified by its canonical name **and every alias**; the strictest class wins, and
only exact canonical names run (an alias or an abbreviation such as `tin` is not a command here).

| Class | Examples | Can run? |
| --- | --- | --- |
| Forbidden | `tinker`, `db`, `queue:work`, `db:wipe`, `migrate:fresh`, `key:generate`, `craft:db:restore`, `craft:users:impersonate`, `craft:graphql:create-token`, `make:*` | Never |
| Sensitive | `about`, `config:show`, `craft:env:*`, `craft:users:create`, `craft:plugin:*`, `vendor:publish` | Only if listed in `sensitiveCommands` (config file) |
| Confirm | `down`, `migrate`, `craft:up`, `craft:resave:*`, `queue:flush`, anything that deletes, prunes or purges | After typing the command's name |
| Normal | `craft:clear-caches:all`, `migrate:status`, `route:list` | When allowlisted |

## Inputs

- Only the command's own arguments and options, passed as an array to Artisan: no shell, and `--force`
  typed into an argument is just text. Global options (`--env`, `-n`…) are never accepted and every run
  is non-interactive.
- **Value options are allowlisted**: sites, sections, types, ids, uids, limits, offsets, batch sizes,
  queues, days/hours and similar. Anything else needs `allowedOptions` in the config file for that command.
- **Never** offered or accepted, whatever the config says: options that evaluate code or templates
  (`craft:resave:* --set/--to`, `down --render`), take paths or file names, write files, reach the network
  (`--url`, `--host`), pick classes or seeders, or switch database/environment; arguments named `path`,
  `code`, `class`, `password`, `value`…. A command that *requires* such an argument can't be run.
- `--site` must be one of your sites.

## When the job runs

Everything is checked again: the environment, the allowlist, that the person still may run it, that the
name still resolves to the same command with the same aliases, and that the stored inputs still pass the
policy. Runs are never retried.

## Output and secrets

Output is captured undecorated, stored line by line, and masked: the app key (and previous keys), every
env and config value under a secret-looking name, passwords in URLs, bearer tokens and common API key
formats (Stripe, GitHub, Slack, AWS, JWT). It's best effort: a command that computes and prints a new
secret can't be recognised, which is why such commands are forbidden. Run parameters are stored
encrypted and wiped when the run ends; a masked copy is kept for the history.
