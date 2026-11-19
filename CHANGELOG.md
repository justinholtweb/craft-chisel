# Release Notes for Chisel

## 6.0.0-beta.1 - Unreleased

> Craft 6 only. Craft 6 is in beta, and so is Chisel: use caution on production.

### Added
- Chisel → Commands: allowlisted Artisan commands, each with a form built from its signature (arguments, options, defaults, descriptions); `--site` is a picker of real sites.
- Runs are queued on a dedicated `chisel` queue and run in the queue worker; the run page follows the output live.
- Commands classified as forbidden (never run), sensitive (config file only) or confirm (type the name) by their canonical name and every alias, strictest wins; aliases and abbreviations never resolve to a command.
- Option policy: value options are allowlisted; options that evaluate code or templates (`craft:resave:* --set/--to`, `down --render`), take paths or files, or reach the network are never offered or accepted.
- Admin-only by default, per-command permissions, “View Chisel run history”, password re-entry with rate limiting, no use while impersonating.
- Switched off in production and where `allowAdminChanges` is off unless `enableInProduction` is set in the config file.
- Output sanitising (app key, secret env/config values, URL credentials, bearer tokens, common API key formats); encrypted parameters wiped when a run ends.
- Run history with filters, CSV export (no output bodies) and retention pruning (`craft:chisel:prune`, daily).
- `craft:chisel:refresh` and a queued refresh for the command list.
- GraphQL `chiselRuns` (read-only metadata, behind its own schema component).
- Twig and Blade `chisel.lastRun()` for admins.
- Sidebar label and icon settings.
