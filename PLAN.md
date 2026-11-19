# Chisel — plan

Run allowlisted Artisan commands from the Craft CP — forms built from each command's signature, permission per command, full audit log.

- Package: `justinholtweb/craft-chisel` · handle `chisel` · namespace `JustinHoltWeb\Chisel`
- Editions: single edition · pricing: Free (Craft License)
- Laravel integration: Artisan — Runs allowlisted Artisan commands through the console kernel.
- Craft 6 only, native Laravel (no `craftcms/yii2-adapter`, ever). Follow
  `~/Sites/plugin-testing-craft6/porting/COOKBOOK.md` for Craft 6 idioms.

## Status
- **2026-11-18 — phases 1–4 done, plus GraphQL, Twig/Blade, multisite, translations, icon, docs, site page.**
  - Allowlist (CP, admin + password; config file `allowedCommands` wins and shows read-only), forbidden /
    sensitive (config `sensitiveCommands` only) / confirm (type the name) / normal classes, classified by
    canonical name **and every alias** (strictest wins). Only exact canonical names run — no aliases, no
    Symfony abbreviations (`tin` → `tinker`), in the CP or the job.
  - Option policy: value options allowlisted (sites, sections, ids, limits…; config `allowedOptions` per
    command adds more); options that evaluate code/templates (`craft:resave:* --set/--to`, `down --render`),
    take paths/files, write files, reach the network, pick classes/seeders or switch db/env are never offered
    or accepted, even via config. A command requiring such an argument can't run. Enforced in CommandSpec
    (`withPolicy`), InputBuilder and again in the job (`paramsFit`).
  - Forms from `InputDefinition`; `--site` picker validated against real sites.
  - Runs: queued on a dedicated `chisel` queue (Craft's web queue runner never sees them; commands need the
    CLI because Craft registers `craft:*` only when running in console). Payload = run id; parameters
    encrypted on the run and wiped at the end; never retried; everything re-checked in the job (env,
    allowlist, user, inputs, canonical name + aliases resolve to the approved command).
  - Output: undecorated, streamed per complete line, masked (app key, secret env/config values, URL
    passwords, bearer/API tokens), capped at a line boundary. Polling JS (`resources/js/chisel.js`).
  - Audit log: history with filters, CSV export without output (formula-safe), daily prune + abandoned-run
    cleanup. Non-history users only ever see their own runs (security review fix).
  - Access: admin-only by default (`requireAdmin` false → `accessPlugin-chisel` + `chisel:runCommands` +
    `chisel:run:<name>`), `chisel:viewHistory`, never while impersonating; Chisel's own password
    re-entry (`elevationSeconds`, default 300) — not Craft's elevated session, which login starts; 5 tries a
    minute. Off in production / `allowAdminChanges` false unless `enableInProduction`. Core's generic plugin
    settings save is refused (FormRequest `authorize() false`); Settings → Plugins → Chisel redirects.
  - GraphQL `chiselRuns` (metadata only) behind `chisel.runs:read`; Twig/Blade `chisel.lastRun()` for
    history viewers only.
  - Tests: 80+ Pest; harness `tests/Harness/check.sh` (includes `policy-check.sh`, which was shown failing
    11/22 on the pre-fix code). Harness probe command `chisel:probe` lives in the harness
    (`app/Console/ChiselProbeCommand.php`, registered in AppServiceProvider).
- Gotchas: `craft.cp` (not `cp`) in plugin CP Twig, and its field HTML needs `|raw`; CP pages are Inertia —
  contentHtml arrives in `<script data-page="app" type="application/json">`; Laravel's
  `auth.password_timeout` defaults to 10800 s, too long for Chisel; FPM opcache can serve a rewritten
  config file with the same mtime — write a second apart.

## Phases
1. **Allowlist + permissions.** Commands must be allowlisted (config file wins over CP); a Craft permission per
   command; admin + elevated session required to edit the allowlist.
2. **Forms.** Build a form from the command's `InputDefinition` (arguments, options, defaults, descriptions).
3. **Run + output.** Queued job with output captured to the run record and streamed to the CP (polling first,
   Walkie later); exit code, duration.
4. **Audit log.** Who ran what, when, with which arguments, outcome — retained and exportable.

## Security
Never accept a command name that isn't allowlisted; never shell out; pass arguments as an array to
`Artisan::call()`; refuse in non-interactive dangerous commands (`db:wipe`, `migrate:fresh`) even if listed.

## Definition of done (every phase)
`bin/port-check craft-chisel` green · Pest suite green · Pint + Larastan level 6 clean · installs, uninstalls and
reinstalls cleanly in the harness · CP pages smoke-tested with `bin/cp-session`.

## Questions for Justin
1. **Admin-only + permission.** Admins pass every Craft permission, so with `requireAdmin` (default true) the
   per-command permissions are inert for admins and only matter once `requireAdmin => false` lets non-admins
   in. OK, or should admins also need an explicit grant (Craft can't store permissions on admins today)?
2. **Template helper.** Chisel ships one read-only helper, `chisel.lastRun('cmd')` / `$chisel->lastRun()`,
   returning metadata only and only for people who can view history (visitors get null). Keep it, or drop
   the front-end surface entirely?
3. **GraphQL is thin on purpose.** `chiselRuns` returns command name, status, exit code and timings only,
   behind its own schema component (off until ticked). No mutations, no arguments (they can hold secrets),
   no output, no users. It's for monitoring. Enough, or would you rather it not exist?
4. **Default allowlist** is empty (safe by default); Settings marks 15 read-only/cache commands as
   “Common”. Want any pre-ticked on install?
5. **Dedicated queue.** Runs need a worker on the `chisel` queue (Craft's web-request queue runner can't run
   `craft:*` commands). Acceptable requirement, or should Chisel also offer a CLI-subprocess mode
   (`php artisan` via Symfony Process, no shell) for hosts without workers?
6. **Option allowlist.** Value options outside the built-in safe list (sites, sections, ids, limits…) need
   `allowedOptions` in the config file. Too strict for your use (e.g. `route:list --path`)?
