#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────
# Trackie v2 — automated regression harness (Phase 0 requirement)
# Verifies, rather than assumes, that the app is healthy after a change.
#
# Checks:
#   1. JS syntax   — node --check on every assets/js/*.js
#   2. PHP syntax  — php -l on every pages/*.php + includes/**.php
#   3. Auth        — logs the local test account in (curl), asserts success
#   4. Render      — every page returns HTTP 200, exactly one #page-main,
#                    and contains no PHP fatal/parse/undefined errors
#
# Usage:  bash scripts/regression.sh
# Requires: local XAMPP running, test account test@trackie.local / Test1234
#           (create with scripts/seed_test_account.sql if missing)
# Exit code 0 = all green, 1 = something failed.
# ─────────────────────────────────────────────────────────────────────────
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE_URL="${TRACKIE_URL:-http://localhost/Trackie}"
PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
JAR="$(mktemp)"
FAIL=0

red()   { printf "\033[31m%s\033[0m\n" "$1"; }
green() { printf "\033[32m%s\033[0m\n" "$1"; }
note()  { printf "\033[36m%s\033[0m\n" "$1"; }

note "== 1. JS syntax =="
for f in "$ROOT"/assets/js/*.js; do
  if ! "$NODE_BIN" --check "$f" 2>/tmp/jserr; then
    red "  JS FAIL: $(basename "$f") — $(cat /tmp/jserr)"; FAIL=1
  fi
done
[ "$FAIL" = 0 ] && green "  all JS clean"

note "== 2. PHP syntax =="
while IFS= read -r f; do
  out="$("$PHP_BIN" -l "$f" 2>&1)"
  echo "$out" | grep -q "No syntax errors" || { red "  PHP FAIL: $f — $out"; FAIL=1; }
done < <(find "$ROOT/pages" "$ROOT/includes" "$ROOT/api" "$ROOT/config" -name '*.php' 2>/dev/null)
[ "$FAIL" = 0 ] && green "  all PHP clean"

note "== 3. Auth =="
CSRF=$(curl -s -c "$JAR" "$BASE_URL/pages/auth.php" | grep -o 'csrf-token" content="[^"]*"' | head -1 | sed 's/.*content="//;s/"//')
LOGIN=$(curl -s -b "$JAR" -c "$JAR" -X POST "$BASE_URL/api/auth.php" \
  -H "X-Requested-With: XMLHttpRequest" \
  -F "action=login" -F "email=test@trackie.local" -F "password=Test1234" -F "csrf_token=$CSRF")
if echo "$LOGIN" | grep -q '"success":true'; then green "  login OK"; else red "  login FAIL: $LOGIN"; FAIL=1; fi

note "== 4. Render (authenticated) =="
PAGES="dashboard today todos habits goals routines calendar study_plan finance focus analytics progress
       gym projects library music gaming cooking art photography writing meditation gardening sports
       hobbies profile settings reminders"
for p in $PAGES; do
  body="$(curl -s -b "$JAR" -w '\n%{http_code}' "$BASE_URL/pages/$p.php")"
  code="$(echo "$body" | tail -1)"
  html="$(echo "$body" | sed '$d')"
  main="$(echo "$html" | grep -c 'id="page-main"')"
  err="$(echo "$html" | grep -io 'fatal error\|parse error\|call to a member\|call to undefined\|undefined variable\|undefined array key' | sort -u | tr '\n' ';')"
  if [ "$code" != "200" ] || [ "$main" != "1" ] || [ -n "$err" ]; then
    red "  RENDER FAIL: $p (http=$code main=$main) $err"; FAIL=1
  fi
done
[ "$FAIL" = 0 ] && green "  all 27 pages render clean (200, 1x #page-main, no PHP errors)"

note "== 5. Nav integrity =="
# manifest.json shortcuts are a separate nav surface from navTree() and cannot
# be generated from it without changing the manifest URL (which would break
# already-installed PWAs). Guard against drift instead: every shortcut and the
# start_url must resolve to a real file.
MANIFEST="$ROOT/manifest.json"
if [ -f "$MANIFEST" ]; then
  while IFS= read -r rel; do
    [ -z "$rel" ] && continue
    if [ ! -f "$ROOT/$rel" ]; then red "  MANIFEST FAIL: '$rel' does not exist"; FAIL=1; fi
  done < <(grep -o '"\(url\|start_url\)":[[:space:]]*"[^"]*"' "$MANIFEST" | sed 's/.*"\([^"]*\)"$/\1/')
  [ "$FAIL" = 0 ] && green "  manifest shortcuts + start_url all resolve"
fi

# Every href rendered by navTree() must point at a real page. Catches a typo in
# the nav tree before it ships as a dead link in the sidebar.
NAVBAD=""
while IFS= read -r page; do
  [ -z "$page" ] && continue
  [ -f "$ROOT/pages/$page" ] || NAVBAD="$NAVBAD $page"
done < <(grep -oE "'href'[[:space:]]*=>[[:space:]]*'[a-z_]+\.php'" "$ROOT/includes/functions.php" \
         | grep -oE "[a-z_]+\.php" | sort -u)
if [ -n "$NAVBAD" ]; then red "  NAV FAIL: navTree references missing page(s):$NAVBAD"; FAIL=1;
else green "  every navTree href resolves to a real page"; fi

rm -f "$JAR"
echo
if [ "$FAIL" = 0 ]; then green "REGRESSION: ALL GREEN"; else red "REGRESSION: FAILURES ABOVE"; fi
exit $FAIL
