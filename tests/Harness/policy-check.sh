#!/bin/bash
# Security regressions for Chisel's command/option policy (run alone, or from check.sh with POLICY_SESSION set).
#  1. Options that evaluate code or templates, take paths, write files or reach the network are never offered or
#     accepted (craft:resave:* --set/--to above all); value options are allowlisted.
#  2. Commands are classified by their canonical name AND every alias (strictest wins); aliases and abbreviations
#     never resolve to a command, in the CP or in the queued job.
set -uo pipefail
exec < /dev/null
H=/Users/jholt/Sites/plugin-testing-craft6
SRC=$(cd "$(dirname "$0")" && pwd)
U=https://plugin-testing-craft6.ddev.site
CFG=$H/config/craft/chisel.php
cd "$H" || exit 2
source "$SRC/lib.sh"
STANDALONE=0
if [ -z "${POLICY_SESSION:-}" ]; then
  STANDALONE=1
  ddev artisan craft:chisel:refresh >/dev/null 2>&1
  POLICY_SESSION=$(login admin claudepassword)
  post "$POLICY_SESSION" chisel/elevate -d password=claudepassword >/dev/null
fi
S=$POLICY_SESSION
inject() { # inject <command> <php params array> → run id, queued as admin
  tk "\$u=CraftCms\\Cms\\User\\Elements\\User::find()->username('admin')->one(); \$r=JustinHoltWeb\\Chisel\\Models\\Run::query()->create(['userId'=>\$u->id,'username'=>'admin','command'=>'$1','arguments'=>[],'status'=>'pending','parameters'=>Illuminate\\Support\\Facades\\Crypt::encryptString(json_encode($2))]); JustinHoltWeb\\Chisel\\Jobs\\RunCommand::dispatch(\$r->id)->onQueue('chisel'); echo \$r->id;"
}
status() { tk "echo JustinHoltWeb\\Chisel\\Models\\Run::find($1)?->status;"; }

config "['allowedCommands' => ['craft:resave:entries', 'chisel:probe', 'craft:pc:set', 'craft:restore', 'craft:project-config/set', 'tin', 'insp']]"

# ---- 1. Options ----
F=$(page "$S" chisel/commands/craft:resave:entries)
has "resave: the form is offered (confirm tier)" "$F" 'name="opts[section]"'
hasnt "resave: --set is never offered" "$F" 'name="opts[set]"'
hasnt "resave: --to is never offered" "$F" 'name="opts[to]"'
n=$(runs)
post "$S" chisel/commands/craft:resave:entries -d confirm=craft:resave:entries -d 'opts[section]=nosuchsection' -d 'opts[set]=title' --data-urlencode 'opts[to]==1+1' >/dev/null
hasnt "resave: a posted --set/--to never reaches the run" "$(lastrun "['command','arguments']")" '--set'
ok "resave: …the run was queued without them" "$(tk 'echo JustinHoltWeb\Chisel\Models\Run::query()->count();')" "$((n+1))"
tk 'Illuminate\Support\Facades\DB::table("jobs")->where("queue","chisel")->delete(); JustinHoltWeb\Chisel\Models\Run::query()->unfinished()->update(["status"=>"refused"]); echo 1;' >/dev/null
id=$(inject craft:resave:entries "['--section' => 'nosuchsection', '--set' => 'title', '--to' => '=1+1']")
work
ok "job: stored parameters with --set/--to are refused at run time" "$(status "$id")" "refused"
F=$(page "$S" chisel/commands/chisel:probe)
has "options: value options outside the allowlist aren't offered unless configured (probe --times is configured)" "$F" 'name="opts[times]"'
config "['allowedCommands' => ['chisel:probe'], 'allowedOptions' => []]"
hasnt "options: …and disappear without allowedOptions" "$(page "$S" chisel/commands/chisel:probe)" 'name="opts[times]"'
id=$(inject chisel:probe "['word' => 'x', '--times' => '2']")
work
ok "job: a value option no longer allowed is refused at run time" "$(status "$id")" "refused"
config "['allowedCommands' => ['chisel:probe'], 'allowedOptions' => ['chisel:probe' => ['times', 'tag', 'sleep', 'set', 'to']]]"
hasnt "options: config can't re-enable hard-forbidden options" "$(page "$S" chisel/commands/chisel:probe)" 'name="opts[to]"'

# ---- 2. Aliases and abbreviations ----
config "['allowedCommands' => ['craft:pc:set', 'craft:restore', 'craft:project-config/set', 'tin', 'insp', 'craft:project-config:set', 'craft:db:restore']]"
ok "alias: craft:pc:set (alias of a sensitive command) isn't runnable" "$(code "$S" chisel/commands/craft:pc:set)" "404"
ok "alias: craft:restore (alias of forbidden craft:db:restore) isn't runnable" "$(code "$S" chisel/commands/craft:restore)" "404"
ok "alias: slash-form alias isn't runnable" "$(code "$S" chisel/commands/craft:project-config/set)" "404"
ok "abbreviation: tin (→ tinker) isn't runnable" "$(code "$S" chisel/commands/tin)" "404"
ok "abbreviation: insp (→ inspire) isn't runnable" "$(code "$S" chisel/commands/insp)" "404"
ok "canonical forbidden name is refused" "$(code "$S" chisel/commands/craft:db:restore)" "404"
ok "catalog stores canonical names only (no aliases)" "$(tk 'echo implode(",", array_values(array_intersect(["craft:pc:set","craft:restore","craft:resave/all","craft:project-config:set"], array_keys(app(JustinHoltWeb\Chisel\Commands\Catalog::class)->all()))));')" "craft:project-config:set"
ok "a command is as strict as its strictest alias" "$(tk 'echo app(JustinHoltWeb\Chisel\Chisel::class)->risk("craft:project-config:set");')" "sensitive"
config "['allowedCommands' => ['inspire', 'insp', 'craft:pc:set', 'craft:restore']]"
for c in insp craft:pc:set craft:restore; do
  id=$(inject "$c" "[]"); work
  ok "job: an alias/abbreviation queued directly ($c) is refused" "$(status "$id")" "refused"
done
id=$(inject inspire "[]"); work
ok "job: the canonical name still runs" "$(status "$id")" "succeeded"

config
[ "$STANDALONE" = 1 ] && rm -f "$CFG" "$S"
echo "$pass passed, $fail failed"
[ "$fail" = 0 ]
