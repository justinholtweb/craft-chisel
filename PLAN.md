# Chisel — plan

Run allowlisted Artisan commands from the Craft CP — forms built from each command's signature, permission per command, full audit log.

- Package: `justinholtweb/craft-chisel` · handle `chisel` · namespace `JustinHoltWeb\Chisel`
- Editions: single edition · pricing: Free (provisional)
- Laravel integration: Artisan — Runs allowlisted Artisan commands through the console kernel.
- Craft 6 only, native Laravel (no `craftcms/yii2-adapter`, ever). Follow
  `~/Sites/plugin-testing-craft6/porting/COOKBOOK.md` for Craft 6 idioms.

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
