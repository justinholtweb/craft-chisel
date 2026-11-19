#!/bin/bash
# Live checks for Chisel against the Craft 6 harness (~/Sites/plugin-testing-craft6).
# Needs: the harness probe command (ChiselProbeCommand.php.fixture → app/Console, registered in
# AppServiceProvider — this script installs it under bin/harness-lock if missing). Chisel's jobs run on
# the `chisel` queue, worked here with `queue:work --queue=chisel --stop-when-empty`.
# Config switches go through config/craft/chisel.php (this script owns that file and removes it at the end).
set -uo pipefail
exec < /dev/null
H=/Users/jholt/Sites/plugin-testing-craft6
SRC=$(cd "$(dirname "$0")" && pwd)
U=https://plugin-testing-craft6.ddev.site
CFG=$H/config/craft/chisel.php
cd "$H" || exit 2
source "$SRC/lib.sh"

# ---- Setup ------------------------------------------------------------------------------------
if [ ! -f "$H/app/Console/ChiselProbeCommand.php" ] || ! grep -q ChiselProbeCommand "$H/app/Providers/AppServiceProvider.php"; then
  bin/harness-lock bash -c "mkdir -p '$H/app/Console' && cp '$SRC/ChiselProbeCommand.php.fixture' '$H/app/Console/ChiselProbeCommand.php' && grep -q ChiselProbeCommand '$H/app/Providers/AppServiceProvider.php' || python3 -I -c '
import sys; p=sys.argv[1]; s=open(p).read(); a=\"    public function boot(): void\n    {\n\"
s=s.replace(a, a+\"        // Chisel: a harmless probe command for its checks (craft-chisel/tests/Harness).\n        \$this->commands([\\\\App\\\\Console\\\\ChiselProbeCommand::class]);\n\n\",1); open(p,\"w\").write(s)' '$H/app/Providers/AppServiceProvider.php'"
fi
cp "$SRC"/fixtures.php storage/chisel-fixtures.php; cp "$SRC"/cleanup.php storage/chisel-cleanup.php
cp "$SRC"/multisite-fixtures.php storage/chisel-multisite-fixtures.php; cp "$SRC"/multisite-cleanup.php storage/chisel-multisite-cleanup.php
mkdir -p resources/views/chisel && cp "$SRC"/templates/lastrun.twig "$SRC"/templates/lastrun-blade.blade.php resources/views/chisel/
config
tk "require 'storage/chisel-cleanup.php';" >/dev/null
resetsettings
IDS=$(tk "require 'storage/chisel-fixtures.php';")
RUNNER=$(echo "$IDS" | python3 -I -c 'import json,sys;print(json.load(sys.stdin)["runner"])')

ok "catalog: craft:chisel:refresh reads the commands (CLI sees craft:* commands)" "$(ddev artisan craft:chisel:refresh 2>/dev/null | grep -oE 'knows [0-9]+' | awk '{print ($2>100)?"many":"few"}')" "many"
ok "catalog: has a craft:* command and the probe" "$(tk 'echo implode(",", array_values(array_intersect(["craft:clear-caches:all","chisel:probe"], array_keys(app(JustinHoltWeb\Chisel\Commands\Catalog::class)->all()))));')" "craft:clear-caches:all,chisel:probe"

# ---- Access ---------------------------------------------------------------------------------
ok "logged out: Chisel redirects to login" "$(curl -sk -o /dev/null -w '%{http_code}' "$U/admin/chisel")" "302"
ok "logged out: POST is refused by CSRF" "$(curl -sk -X POST -o /dev/null -w '%{http_code}' -d 'args[word]=x' "$U/admin/chisel/commands/chisel:probe")" "419"
A=$(login admin claudepassword)
ok "admin: Commands page" "$(code "$A" chisel)" "200"
ok "admin: Settings page" "$(code "$A" chisel/settings)" "200"
ok "admin: History page" "$(code "$A" chisel/history)" "200"
ok "admin POST without CSRF token is refused" "$(curl -sk -b "$A" -X POST -o /dev/null -w '%{http_code}' -d 'password=claudepassword' "$U/admin/chisel/settings")" "419"
ok "Craft's generic plugin-settings save is refused (allowlist only via Chisel → Settings)" "$(post "$A" settings/plugins/chisel -H 'Accept: application/json' -d 'settings[allowedCommands][]=chisel:probe' | cut -d' ' -f1)" "403"
ok "Settings → Plugins → Chisel opens Chisel's settings" "$(curl -sk -b "$A" -o /dev/null -w '%{redirect_url}' "$U/admin/settings/plugins/chisel" | grep -o 'chisel/settings')" "chisel/settings"

# ---- Settings: elevated session + allowlist ------------------------------------------------------
ok "settings: a fresh session isn't elevated (login doesn't count)" "$(page "$A" chisel/settings | grep -c 'id="chisel-password"' | awk '{print ($1>0)?"asks":"no"}')" "asks"
post "$A" chisel/settings -d 'allowedCommands[]=chisel:probe' -d password=wrong >/dev/null
has "settings: wrong password is refused" "$(page "$A" chisel/settings)" "Enter your password to confirm it’s you."
ok "settings: …and nothing was saved" "$(tk 'echo json_encode(CraftCms\Cms\Support\Facades\Plugins::getPlugin("chisel")->getSettings()->allowedCommands);')" "[]"
post "$A" chisel/settings -d 'allowedCommands[]=chisel:probe' -d 'allowedCommands[]=tinker' -d 'allowedCommands[]=about' -d password=claudepassword >/dev/null
has "settings: forbidden/sensitive commands can't be allowlisted" "$(page "$A" chisel/settings)" "These can’t be allowlisted here: tinker, about."
savesettings "$A" chisel/settings -d 'allowedCommands[]=chisel:probe' -d 'allowedCommands[]=inspire' -d 'allowedCommands[]=cache:prune-stale-tags' -d 'allowedCommands[]=Not A Command' \
  -d historyRetentionDays=30 -d maxOutputKb=64 -d timeout=120 >/dev/null
ok "settings: saved with the password confirmed earlier in the session" "$(tk 'echo json_encode(CraftCms\Cms\Support\Facades\Plugins::getPlugin("chisel")->getSettings()->allowedCommands);')" '["chisel:probe","inspire","cache:prune-stale-tags"]'
ok "settings: other values saved" "$(tk '$s=CraftCms\Cms\Support\Facades\Plugins::getPlugin("chisel")->getSettings(); echo $s->historyRetentionDays."/".$s->maxOutputKb."/".$s->timeout;')" "30/64/120"
post "$A" chisel/settings -d timeout=1 >/dev/null
has "settings: validation (timeout ≥ 10)" "$(page "$A" chisel/settings)" "timeout"

# ---- Running ----------------------------------------------------------------------------------
B=$(login admin claudepassword)   # a second, un-elevated admin session
P=$(page "$B" chisel)
has "commands: lists allowlisted commands" "$P" 'data-command="chisel:probe"'
has "commands: confirm-tier commands are badged" "$P" 'Confirm'
F=$(page "$B" chisel/commands/chisel:probe)
has "form: value options added by allowedOptions are offered" "$F" 'name="opts[times]"'
has "form: built from the signature (array argument)" "$F" 'name="args[extra]"'
has "form: VALUE_NONE options are switches" "$F" 'name="opts[loud]"'
has "form: --site is a picker of real sites" "$F" '<option value="default">'
hasnt "form: global options are never offered" "$F" 'opts[env]'
has "form: asks for the password (not elevated)" "$F" 'id="chisel-password"'
ok "form: a command that isn't allowlisted 404s" "$(code "$B" chisel/commands/route:list)" "404"
ok "form: a forbidden command 404s even if named" "$(code "$B" chisel/commands/tinker)" "404"
ok "form: junk names 404" "$(code "$B" 'chisel/commands/x;id')" "404"

n0=$(runs)
r=$(post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=hi')
ok "run: no password → back to the form" "$r" "302 chisel/commands/chisel:probe"
r=$(post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=hi' -d password=nope)
ok "run: wrong password → back to the form" "$r" "302 chisel/commands/chisel:probe"
ok "run: …and nothing was queued" "$(runs)" "$n0"
r=$(post "$B" chisel/commands/chisel:probe -d password=claudepassword)
has "run: a missing required argument is an error" "$(page "$B" chisel/commands/chisel:probe)" "“word” is required."
r=$(post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=hi' -d 'opts[site]=nosuchsite')
has "run: an unknown site is refused" "$(page "$B" chisel/commands/chisel:probe)" "Choose one of this install’s sites."
ok "run: …nothing queued" "$(runs)" "$n0"

r=$(post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=hello; rm -rf / $(id)' --data-urlencode $'args[extra]=--force\n-v' \
  -d 'opts[site]=default' -d 'opts[loud]=1' -d 'opts[leak]=1' --data-urlencode $'opts[tag]=x\ny' -d 'opts[times]=2' -d 'opts[env]=production' -d 'opts[no-interaction]=0')
RID=$(echo "$r" | grep -oE 'runs/[0-9]+' | cut -d/ -f2)
ok "run: queued and redirected to the run" "$(echo "$r" | grep -oE '^302 chisel/runs/')" "302 chisel/runs/"
ok "run: pending until a worker runs the chisel queue" "$(lastrun "['status']")" '{"status":"pending"}'
ok "run: job payload carries only the run id (parameters encrypted on the run)" "$(tk 'echo Illuminate\Support\Facades\DB::table("jobs")->where("queue","chisel")->where("payload","like","%rm -rf%")->count();')" "0"
ok "run: the web queue runner (default queue) never gets Chisel jobs" "$(tk 'echo Illuminate\Support\Facades\DB::table("jobs")->where("queue","default")->where("payload","like","%Chisel%")->count();')" "0"
r2=$(post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=again')
ok "run: a second submit while one is waiting goes to the existing run" "$r2" "302 chisel/runs/$RID"
work
O=$(ddev artisan tinker --execute "echo JustinHoltWeb\\Chisel\\Models\\Run::find($RID)->output;" 2>/dev/null)
ok "run: succeeded with exit code 0, parameters wiped" "$(lastrun "['status','exitCode','parameters']")" '{"status":"succeeded","exitCode":0,"parameters":null}'
has "run: shell metacharacters are passed as plain text" "$O" 'word: HELLO; RM -RF / $(ID)'
has "run: an argument that looks like an option stays an argument" "$O" 'extra: --force|-v'
has "run: array option" "$O" 'tags: x|y'
has "run: site passed through" "$O" 'site: default'
has "run: always non-interactive (form can't turn it off)" "$O" 'interactive: no'
hasnt "run: --env from the form is ignored" "$(lastrun "['arguments']")" 'production'
KEY=$(grep '^APP_KEY=' .env | cut -d= -f2- | sed 's/^base64://')
hasnt "output: the app key never appears" "$O" "${KEY:0:20}"
hasnt "output: DSN password masked" "$O" 'hunter2hunter2'
hasnt "output: bearer token masked" "$O" 'abcdef0123456789abcdef'
hasnt "output: Stripe-style key masked" "$O" 'sk_live_0123'
has "output: env-backed config secret (scout key) masked" "$O" 'scout key: ••••••'
has "output: ANSI escapes stripped" "$O" 'red done'
RP=$(page "$B" chisel/runs/$RID)
has "run page: shows the command line" "$RP" 'chisel:probe &quot;hello; rm -rf / $(id)&quot;'
has "run page: shows the status" "$RP" 'chisel-status--succeeded'
hasnt "run page: no secrets" "$RP" "${KEY:0:20}"
PJ=$(curl -sk -b "$B" -H 'Accept: application/json' "$U/admin/chisel/runs/$RID/poll")
ok "poll: JSON status" "$(echo "$PJ" | python3 -I -c 'import json,sys;d=json.load(sys.stdin);print(d["status"],d["finished"],d["exitCode"])')" "succeeded True 0"
LEN=$(echo "$PJ" | python3 -I -c 'import json,sys;print(json.load(sys.stdin)["length"])')
ok "poll: output omitted when unchanged" "$(curl -sk -b "$B" -H 'Accept: application/json' "$U/admin/chisel/runs/$RID/poll?length=$LEN" | python3 -I -c 'import json,sys;print(json.load(sys.stdin)["output"])')" "None"
hasnt "poll: never sends the parameters" "$PJ" 'parameters'

post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=x' -d 'opts[fail]=1' >/dev/null; work
ok "run: non-zero exit is a failure with its code" "$(lastrun "['status','exitCode']")" '{"status":"failed","exitCode":3}'
post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=x' -d 'opts[throw]=1' >/dev/null; work
E=$(lastrun "['status','error']")
has "run: an exception is a failure with a short message" "$E" 'RuntimeException: Probe exploded'
hasnt "run: …with the app key masked in the message" "$E" "${KEY:0:20}"
post "$B" chisel/commands/chisel:probe --data-urlencode "args[word]=$(printf 'x%.0s' $(seq 1 200))" -d 'opts[times]=1000' >/dev/null; work
ok "run: output is capped (64 KB setting) and marked truncated" "$(tk 'echo JustinHoltWeb\Chisel\Models\Run::latest("id")->first()->outputTruncated ? "cut" : "full";')" "cut"

# Confirm tier
post "$B" chisel/commands/cache:prune-stale-tags >/dev/null
has "confirm: a confirm-tier command needs its name typed" "$(page "$B" chisel/commands/cache:prune-stale-tags)" 'Type “cache:prune-stale-tags” to confirm.'
ok "confirm: typed name → queued" "$(post "$B" chisel/commands/cache:prune-stale-tags -d confirm=cache:prune-stale-tags | grep -oE '^302 chisel/runs/')" "302 chisel/runs/"
work
ok "confirm: ran" "$(lastrun "['command','status']")" '{"command":"cache:prune-stale-tags","status":"succeeded"}'

# Re-checked when the job runs
post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=late' >/dev/null
config "['allowedCommands' => ['inspire']]"
ok "config file allowlist wins over the CP" "$(code "$B" chisel/commands/chisel:probe)" "404"
has "config allowlist: Settings shows it's from the config file" "$(page "$A" chisel/settings)" 'id="chisel-allowlist-config"'
work
ok "job: a command removed from the allowlist after queueing is refused" "$(lastrun "['status','parameters']")" '{"status":"refused","parameters":null}'
config "['enabled' => false]"
has "disabled: banner on every page" "$(page "$B" chisel)" 'id="chisel-disabled"'
n1=$(runs)
ok "disabled: running is refused" "$(post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=x')" "302 chisel/commands/chisel:probe"
ok "disabled: nothing queued" "$(runs)" "$n1"
ok "disabled: history still readable" "$(code "$B" chisel/history)" "200"
config
post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=queued-then-off' >/dev/null
config "['enabled' => false]"; work; config
ok "disabled: a run queued before switching off is refused by the job" "$(lastrun "['status']")" '{"status":"refused"}'
config "['sensitiveCommands' => ['about']]"
ok "sensitive: allowed only via sensitiveCommands in the config file" "$(code "$B" chisel/commands/about)" "200"
config
ok "sensitive: gone again without it" "$(code "$B" chisel/commands/about)" "404"

# ---- Option policy, aliases and abbreviations (policy-check.sh) ----
post "$B" chisel/elevate -d password=claudepassword >/dev/null
PC=$(POLICY_SESSION=$B bash "$SRC/policy-check.sh"); echo "$PC" | grep -v ' passed, '
pass=$((pass + $(echo "$PC" | tail -1 | awk '{print $1}'))); fail=$((fail + $(echo "$PC" | tail -1 | awk '{print $3}')))

# ---- History, export ----------------------------------------------------------------------------
HP=$(page "$A" 'chisel/history?status=failed')
has "history: filter by status" "$HP" 'data-status="failed"'
hasnt "history: …only that status" "$HP" 'data-status="succeeded"'
CSV=$(curl -sk -b "$A" "$U/admin/chisel/history/export")
ok "export: CSV header" "$(echo "$CSV" | head -1)" "id,uid,command,arguments,status,exitCode,userId,username,ip,queuedAt,startedAt,finishedAt,durationMs,error"
hasnt "export: no output bodies" "$CSV" 'word: HELLO'

# ---- Non-admins (requireAdmin off) and the history permission ----------------------------------
# Per-command permissions exist only for allowlisted commands, so grant them now that chisel:probe is listed.
tk "require 'storage/chisel-fixtures.php';" >/dev/null
R=$(login chisel_runner chisel-pass-1)
ok "non-admin: refused while requireAdmin is on (default)" "$(code "$R" chisel)" "403"
config "['requireAdmin' => false]"
ok "non-admin runner: Commands page" "$(code "$R" chisel)" "200"
ok "non-admin runner: may open the probe form" "$(code "$R" chisel/commands/chisel:probe)" "200"
ok "non-admin runner: no per-command permission for inspire → 403" "$(code "$R" chisel/commands/inspire)" "403"
ok "non-admin runner: no history permission → History 403" "$(code "$R" chisel/history)" "403"
ok "non-admin runner: export 403" "$(code "$R" chisel/history/export)" "403"
ok "non-admin runner: Settings 403 (admins only, always)" "$(code "$R" chisel/settings)" "403"
ok "non-admin runner: someone else's run 404s (no id guessing)" "$(code "$R" chisel/runs/$RID)" "404"
ok "non-admin runner: someone else's run poll 404s" "$(code "$R" chisel/runs/$RID/poll)" "404"
RF=$(page "$R" chisel/commands/chisel:probe)
hasnt "non-admin runner: recent runs on the form hide other people's runs" "$RF" 'data-run='
RC=$(page "$R" chisel)
hasnt "non-admin runner: Commands page hides other people's last run" "$RC" 'chisel-status--'
post "$R" chisel/commands/chisel:probe --data-urlencode 'args[word]=mine' -d password=chisel-pass-1 >/dev/null; work
MY=$(tk "echo JustinHoltWeb\\Chisel\\Models\\Run::where('userId', $RUNNER)->latest('id')->value('id');")
ok "non-admin runner: can run and see their own run" "$(code "$R" chisel/runs/$MY)" "200"
RF=$(page "$R" chisel/commands/chisel:probe)
ok "non-admin runner: recent runs show only their own" "$(echo "$RF" | grep -oE 'data-run="[0-9]+"' | tr '\n' ' ')" "data-run=\"$MY\" "
V=$(login chisel_viewer chisel-pass-1)
ok "non-admin viewer: History 200" "$(code "$V" chisel/history)" "200"
ok "non-admin viewer: any run 200" "$(code "$V" chisel/runs/$RID)" "200"
ok "non-admin viewer: can't run (no run permission)" "$(code "$V" chisel/commands/chisel:probe)" "403"
ok "non-admin viewer: can't queue by POST either" "$(post "$V" chisel/commands/chisel:probe --data-urlencode 'args[word]=x' -d password=chisel-pass-1 | cut -d' ' -f1)" "403"
config
ok "non-admin: refused again once requireAdmin is back on" "$(code "$V" chisel/history)" "403"

# Password attempts are rate-limited per user (5 a minute).
for _ in 1 2 3 4 5 6; do post "$B" chisel/elevate -d password=wrong >/dev/null; done
B2=$(login admin claudepassword)
post "$B2" chisel/elevate -d password=claudepassword >/dev/null
has "elevation: locked out after 5 wrong passwords, even with the right one" "$(page "$B2" chisel)" "Too many wrong passwords."
tk '$id=CraftCms\Cms\User\Elements\User::find()->username("admin")->one()->id; Illuminate\Support\Facades\RateLimiter::clear("chisel-elevate:$id"); echo 1;' >/dev/null

# ---- Twig / Blade helper ------------------------------------------------------------------------
tw=$(curl -sk -b "$A" "$U/chisel/lastrun"); bl=$(curl -sk -b "$A" "$U/chisel/lastrun-blade")
ok "twig: admin sees the last run (metadata only)" "$(echo "$tw" | grep -o '<p id="last">[^<]*')" "<p id=\"last\">chisel:probe/succeeded/0"
ok "twig: keys are metadata only" "$(echo "$tw" | grep -o '<p id="keys">[^<]*')" '<p id="keys">command,status,exitCode,queuedAt,finishedAt,durationMs'
ok "blade: same as twig" "$(echo "$bl" | grep -oE '<p id="(can|last|keys)">[^<]*' | tr '\n' '|')" "$(echo "$tw" | grep -oE '<p id="(can|last|keys)">[^<]*' | tr '\n' '|')"
ok "twig: visitors get nothing" "$(curl -sk "$U/chisel/lastrun" | grep -oE '<p id="(can|last)">[^<]*' | tr '\n' '|')" '<p id="can">no|<p id="last">none|'
ok "blade: visitors get nothing" "$(curl -sk "$U/chisel/lastrun-blade" | grep -oE '<p id="(can|last)">[^<]*' | tr '\n' '|')" '<p id="can">no|<p id="last">none|'
ok "twig: a non-admin runner gets nothing" "$(curl -sk -b "$R" "$U/chisel/lastrun" | grep -o '<p id="last">[^<]*')" '<p id="last">none'

# ---- GraphQL ----------------------------------------------------------------------------------
gq() { local tok; tok=$(printf 'chisel%s' "$1" | awk '{ s=$0; while (length(s) < 32) s=s "0"; print s }')
  for _ in 1 2 3 4 5; do
    out=$(curl -sk -w '\n%{http_code}' -H "Authorization: Bearer $tok" -H 'Content-Type: application/json' \
      --data "$(python3 -I -c 'import json,sys; print(json.dumps({"query": sys.argv[1]}))' "$2")" "$U/actions/graphql/api")
    [ "$(echo "$out" | tail -1)" = 429 ] || break; sleep 3
  done
  echo "$out" | sed '$d' | python3 -I -c 'import json,sys
try: r=json.load(sys.stdin)
except Exception: print("not json"); sys.exit()
if r.get("errors"): print("ERR:" + "; ".join(e.get("message","") for e in r["errors"])); sys.exit()
print(json.dumps(r.get("data")))'; }
ok "gql: with the component, newest runs (metadata)" "$(gq full '{ chiselRuns(command: "chisel:probe", status: "succeeded", limit: 1) { command status exitCode } }')" '{"chiselRuns": [{"command": "chisel:probe", "status": "succeeded", "exitCode": 0}]}'
has "gql: no output/arguments/user fields exist" "$(gq full '{ chiselRuns(limit: 1) { output } }')" 'Cannot query field "output"'
has "gql: …nor arguments" "$(gq full '{ chiselRuns(limit: 1) { arguments } }')" 'Cannot query field "arguments"'
has "gql: unknown status is an error" "$(gq full '{ chiselRuns(status: "nope") { uid } }')" 'Unknown status'
ok "gql: limit is capped at 100" "$(gq full '{ chiselRuns(limit: 100000) { uid } }' | python3 -I -c 'import json,sys;d=json.loads(sys.stdin.read());print(len(d["chiselRuns"])<=100)')" "True"
has "gql: without the component the query doesn't exist" "$(gq none '{ chiselRuns { uid } }')" 'Cannot query field "chiselRuns"'
has "gql: no mutations for commands" "$(gq full 'mutation { chiselRun(command: "inspire") { uid } }')" 'ERR:'
ok "gql: the schema editor offers the component" "$(curl -sk -b "$A" "$U/admin/graphql/schemas/new" | grep -c 'chisel.runs:read' | awk '{print ($1>0)?"yes":"no"}')" "yes"

# ---- Multi-site ---------------------------------------------------------------------------------
bin/harness-lock ddev artisan tinker --execute "require 'storage/chisel-multisite-fixtures.php';" < /dev/null >/dev/null 2>&1
has "multisite: the site picker lists the new site" "$(page "$B" chisel/commands/chisel:probe)" '<option value="chiselfr">'
post "$B" chisel/elevate -d password=claudepassword >/dev/null
post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=bonjour' -d 'opts[site]=chiselfr' >/dev/null; work
has "multisite: --site=chiselfr reaches the command" "$(ddev artisan tinker --execute 'echo JustinHoltWeb\Chisel\Models\Run::latest("id")->first()->output;' 2>/dev/null)" 'site: chiselfr'
bin/harness-lock ddev artisan tinker --execute "require 'storage/chisel-multisite-cleanup.php';" < /dev/null >/dev/null 2>&1
ok "multisite: removed site is refused afterwards" "$(post "$B" chisel/commands/chisel:probe --data-urlencode 'args[word]=x' -d 'opts[site]=chiselfr' >/dev/null; page "$B" chisel/commands/chisel:probe | grep -c 'Choose one of this install’s sites.')" "1"

# ---- Pruning, catalog refresh -------------------------------------------------------------------
tk "JustinHoltWeb\\Chisel\\Models\\Run::query()->whereKey($RID)->update(['created_at' => now()->subDays(40)]); JustinHoltWeb\\Chisel\\Models\\Run::query()->create(['command' => 'inspire', 'status' => 'running', 'startedAt' => now()->subHours(3)]); echo 1;" >/dev/null
ok "prune: deletes runs past retention (30 days) and closes abandoned ones" "$(ddev artisan craft:chisel:prune 2>/dev/null | grep -oE 'Deleted [0-9]+ old runs; closed [0-9]+')" "Deleted 1 old runs; closed 1"
ok "catalog: Refresh button queues a refresh on the chisel queue" "$(post "$A" chisel/catalog/refresh | cut -d' ' -f1)/$(tk 'echo Illuminate\Support\Facades\DB::table("jobs")->where("queue","chisel")->where("payload","like","%RefreshCatalog%")->count();')" "302/1"
work

# ---- Cleanup ----------------------------------------------------------------------------------
rm -f "$CFG"
resetsettings
tk "require 'storage/chisel-cleanup.php';" >/dev/null
rm -f storage/chisel-*.php "$A" "$B" "$B2" "$R" "$V"; rm -rf resources/views/chisel
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
