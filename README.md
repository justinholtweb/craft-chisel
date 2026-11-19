# Chisel

Run allowlisted Artisan commands from the Craft control panel — a form built from each command's
signature, queued runs with live, sanitised output, and a full audit log.

> [!WARNING]
> **Craft 6 only, and in beta.** Chisel is a native **Craft CMS 6** plugin. Craft 6 is still in beta, and
> so is Chisel (`6.0.0-beta.1`). Try it on development and staging sites first and **use caution on
> production**: keep backups, and expect changes between betas. It does not run on Craft 5.

Running commands from a browser is remote code execution by design, so Chisel is built to say no:
nothing runs unless an admin allowlisted it, the riskiest commands can never run, and Chisel switches
itself off in production unless you opt in. Docs: [justinholt.com/plugins/craft-chisel](https://justinholt.com/plugins/craft-chisel/docs/).

## Requirements

- Craft CMS 6.0.0-beta.1 or later (native Craft 6 plugin, no Yii 2 adapter)
- PHP 8.5
- A queue worker for the `chisel` queue: `php artisan queue:work --queue=chisel` (or add `chisel` to
  your worker's `--queue` list). Commands run there, never in a web request.

## Setup

1. Install the plugin, then start a worker for the `chisel` queue.
2. `php artisan craft:chisel:refresh` (or Chisel → Commands → Refresh) — Chisel reads every command's
   signature in the worker, because Craft registers its `craft:*` commands only on the command line.
3. Chisel → Settings: tick the commands people may run (you'll be asked for your password).
4. Chisel → Commands → a command: fill in the form, enter your password, **Run on the queue**. The run
   page follows the output as it arrives.

## What keeps it safe

- **Admins only** by default, plus Chisel's permissions (“Run Artisan commands”, one per command, and
  “View Chisel run history”). Never while impersonating someone.
- **Password re-entry** before running anything or changing settings (5 minutes; 5 wrong tries a minute).
- **Allowlist.** Commands are classified by their name *and every alias*, strictest wins:
  - *Forbidden* — never run, whatever is configured: `tinker`, `db`, workers and servers, `db:wipe`,
    `migrate:fresh|reset|refresh`, `key:generate`, `env:encrypt|decrypt`, Craft's database restore and
    drop commands, anything that prints a sign-in link, password or token, `make:*`, Composer.
  - *Sensitive* — reveal configuration or manage accounts/plugins (`about`, `config:show`,
    `craft:env:*`, `craft:users:create`, `craft:plugin:*`…): only via `sensitiveCommands` in the config file.
  - *Confirm* — bulk changes or `down`: the command's name must be typed in.
- **Inputs.** Only the command's own arguments and options, bound by Symfony, never through a shell.
  Options that take a value are allowlisted (sites, sections, ids, limits…); options that evaluate code
  or templates (`craft:resave:* --set/--to`, `down --render`), take paths or files, or reach the network
  are never offered or accepted. `--site` is validated against your sites.
- **Off in production** (`APP_ENV=production` or `allowAdminChanges` off) unless `enableInProduction`
  is set in `config/craft/chisel.php`. History stays readable.
- **Re-checked when the job runs** (environment, allowlist, the person, the inputs, that the name
  still resolves to the same command). Never retried.
- **Output is sanitised**: the app key, every secret-looking env/config value, credentials in URLs,
  bearer tokens and common API key formats are masked. Parameters are stored encrypted and wiped
  when the run ends.
- **CSRF** on every form; Craft's generic plugin-settings save is refused for Chisel.

## Config file

`config/craft/chisel.php` overrides any setting, and holds the switches the control panel can't change:

```php
return [
    'enabled' => true,                 // master switch
    'enableInProduction' => false,     // run in production / with allowAdminChanges off
    'allowedCommands' => null,         // set to a list to replace the CP allowlist
    'sensitiveCommands' => [],         // e.g. ['about']
    'allowedOptions' => [],            // ['my:command' => ['some-option']] beyond the safe list
    'requireAdmin' => true,            // false: non-admins with Chisel's permissions may use it
    'elevationSeconds' => 300,         // how long a password confirmation lasts (60–3600)
    'queue' => 'chisel',               // a queue Craft's web runner does NOT process
    'connection' => null,
];
```

## Templates, GraphQL

- `chisel.lastRun('craft:clear-caches:all')` (Twig) / `$chisel->lastRun(...)` (Blade): the latest run's
  command, status, exit code and times — `null` for anyone who can't view Chisel's history.
- GraphQL `chiselRuns(command, status, limit)`: read-only run metadata (no arguments, output or
  people), only for schemas with “View Chisel run history”. Commands can't be run over GraphQL.

## Console

```bash
php artisan craft:chisel:refresh   # re-read command signatures
php artisan craft:chisel:prune     # delete runs past retention (scheduled daily), close abandoned runs
```

## Development

```bash
composer install
composer test        # Pest
composer phpstan     # Larastan level 6
composer check-cs    # Pint
tests/Harness/check.sh   # live checks in ~/Sites/plugin-testing-craft6
```

## License

Chisel is free, under the [Craft License](LICENSE.md).
