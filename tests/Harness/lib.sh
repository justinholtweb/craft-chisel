# Shared helpers for Chisel's harness checks (sourced by check.sh and policy-check.sh).
# Expects H, SRC, U, CFG to be set and the cwd to be the harness.
pass=0 fail=0
ok() { if [ "$2" = "$3" ]; then echo "✓ $1"; pass=$((pass+1)); else echo "✗ $1 (got '$2', want '$3')"; fail=$((fail+1)); fi; }
# Here-strings, not `printf | grep -q`: with pipefail, grep -q exiting early SIGPIPEs printf and fails the pipe.
has() { if grep -F -- "$3" <<< "$2" >/dev/null; then echo "✓ $1"; pass=$((pass+1)); else echo "✗ $1 (no '$3')"; fail=$((fail+1)); [ -n "${CHISEL_DEBUG:-}" ] && printf '=== %s\n%s\n' "$1" "$2" >> "$CHISEL_DEBUG"; fi; }
hasnt() { if grep -F -- "$3" <<< "$2" >/dev/null; then echo "✗ $1 (found '$3')"; fail=$((fail+1)); else echo "✓ $1"; pass=$((pass+1)); fi; }
tk() { ddev artisan tinker --execute "$1" 2>/dev/null | grep -v WARNING | tail -1; }
work() { ddev artisan queue:work --queue=chisel --stop-when-empty >/dev/null 2>&1; }
# config <php array>: config/craft/chisel.php = the base (probe options) + these keys. FPM opcache revalidates every 2 s.
BASECFG="['allowedOptions' => ['chisel:probe' => ['times', 'tag', 'sleep']]]"
# opcache compares mtimes: wait a second first so a rewrite never shares the previous write's mtime.
config() { sleep 1; printf '<?php\nreturn array_merge(%s, %s);\n' "$BASECFG" "${1:-[]}" > "$CFG"; sleep 3; }
resetsettings() { local r; for _ in 1 2 3 4; do r=$(tk '$p=CraftCms\Cms\Support\Facades\Plugins::getPlugin("chisel"); echo CraftCms\Cms\Support\Facades\Plugins::savePluginSettings($p, ["allowedCommands" => [], "historyRetentionDays" => 90, "maxOutputKb" => 512, "timeout" => 600, "showInCpNav" => true, "cpNavLabel" => null, "cpNavIcon" => null]) ? "ok" : "no";'); [ "$r" = ok ] && return; sleep 2; done; echo "settings reset failed" >&2; }
lastrun() { tk "echo json_encode(JustinHoltWeb\\Chisel\\Models\\Run::query()->latest('id')->first()?->only($1));"; }
runs() { tk 'echo JustinHoltWeb\Chisel\Models\Run::query()->count();'; }
login() { # login <user> <password> → cookie jar (retries 429: every agent shares one IP)
  local J X code; J=$(mktemp)
  for _ in $(seq 1 30); do
    curl -sk -c "$J" -o /dev/null "$U/admin/login"
    X=$(awk '$6=="XSRF-TOKEN"{print $7}' "$J" | python3 -I -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
    code=$(curl -sk -b "$J" -c "$J" -o /dev/null -w '%{http_code}' -H 'Accept: application/json' -H "X-XSRF-TOKEN: $X" \
      -X POST "$U/actions/users/login" --data-urlencode "loginName=$1" --data-urlencode "password=$2")
    [ "$code" = 429 ] || break; sleep 5
  done
  [ "$code" = 200 ] || { echo "login failed for $1: $code" >&2; exit 1; }
  echo "$J"
}
xsrf() { awk '$6=="XSRF-TOKEN"{print $7}' "$1" | python3 -I -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))'; }
# post <jar> <path> [curl args…] → "<code> <redirect path>"
post() { local j=$1 p=$2; shift 2; curl -sk -b "$j" -c "$j" -X POST -H "X-XSRF-TOKEN: $(xsrf "$j")" -o /dev/null -w '%{http_code} %{redirect_url}' "$U/admin/$p" "$@" | sed -E "s#$U/admin/##; s#\?site=[a-z]+##"; }
code() { curl -sk -b "$1" -o /dev/null -w '%{http_code}' "$U/admin/$2"; }
page() { curl -sk -b "$1" "$U/admin/$2" | python3 -I "$SRC/cphtml.py"; }
# savesettings <jar> [curl args…]: retries project-config races (other agents write it too)
savesettings() { local r; for _ in 1 2 3 4; do r=$(post "$@"); page "$1" chisel/settings | grep -q "Couldn’t save" || break; sleep 2; done; echo "$r"; }

