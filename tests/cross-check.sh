#!/usr/bin/env bash
#
# Engine cross-check: prove the PHP and browser engines agree.
#
#   ./tests/cross-check.sh
#
# There are two implementations of one algorithm — lib/rating.php plus
# lib/matchmaker.php on the server, docs/js/engine.js in the browser. Two
# implementations drift. This runs the same generated scenarios through both and
# compares every field: match selection, pairing, quality, bracket, expected
# score, gainIndex, evidence, standings order and walk-in credit.
#
# It is not decorative. It caught phpRound disagreeing with PHP on 3 of 40
# scenarios, moving a team average by 0.01 — from Math.pow scaling error and
# from PHP's pre-round to 15 significant digits.
#
# Run it whenever you touch a weight in config/config.php or CFG in engine.js.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DIR="$ROOT/tests/cross-check"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

command -v php  >/dev/null 2>&1 || { echo "PHP is required." >&2; exit 1; }
command -v node >/dev/null 2>&1 || { echo "Node is required to run the browser engine." >&2; exit 1; }

# Exact check first: every shared constant must hold the same value. The
# scenario comparison below cannot be relied on for this — a weight only shows
# up there if it happens to decide an outcome.
php "$DIR/constants.php"

php "$DIR/generate.php" "$WORK/scenarios.json" >/dev/null
php "$DIR/php-side.php" "$WORK/scenarios.json" > "$WORK/php.json"
node "$DIR/js-side.mjs" "$WORK/scenarios.json" "$WORK/js.json" >/dev/null

python3 - "$WORK/php.json" "$WORK/js.json" <<'PY'
import json, sys

php = json.load(open(sys.argv[1]))
js  = json.load(open(sys.argv[2]))

def close(a, b, eps=1e-9):
    if isinstance(a, (int, float)) and isinstance(b, (int, float)):
        return abs(a - b) < eps
    return a == b

diffs, fields = [], 0
for i, (p, j) in enumerate(zip(php, js)):
    if (p['match'] is None) != (j['match'] is None):
        diffs.append(f"scenario {i}: one engine found a match and the other did not")
    elif p['match']:
        for k in ('t1', 't2', 'q', 'b', 'btb'):
            fields += 1
            if p['match'][k] != j['match'][k]:
                diffs.append(f"scenario {i}: match.{k}  php={p['match'][k]}  js={j['match'][k]}")
        for k in ('a1', 'a2', 'e'):
            fields += 1
            if not close(p['match'][k], j['match'][k]):
                diffs.append(f"scenario {i}: match.{k}  php={p['match'][k]}  js={j['match'][k]}")
    for pid, pg in p['gain'].items():
        fields += 2
        jg = j['gain'].get(pid)
        if jg is None:
            diffs.append(f"scenario {i}: gain missing for {pid}")
        elif pg['evidence'] != jg['evidence'] or not close(pg['gain_index'], jg['gain_index']):
            diffs.append(f"scenario {i}: gain {pid}  php={pg}  js={jg}")
    fields += 2
    if p['standings'] != j['standings']:
        diffs.append(f"scenario {i}: standings differ")
    if p['credit'] != j['credit']:
        diffs.append(f"scenario {i}: walk-in credit  php={p['credit']}  js={j['credit']}")

print(f"  {len(php)} scenarios, {fields} compared fields")
if diffs:
    print(f"  \033[31m{len(diffs)} DIFFERENCE(S)\033[0m")
    for d in diffs[:20]:
        print("   ", d)
    sys.exit(1)
print("  \033[32mPHP and browser engines agree on every field\033[0m")
PY
