#!/usr/bin/env bash
#
# Luna smoke + security-regression suite.
#
# Exercises the hardening from the 2026 security pass (docs/security.md) plus a
# basic render smoke test, against a RUNNING stack. Run it after `docker compose
# up -d`:
#
#   BASE=http://localhost:8080 test/regression.sh
#
# Env:
#   BASE          base URL of the running app (default http://localhost:8080)
#   ADMIN_EMAIL   admin login (default admin@luna.local)
#   ADMIN_PASS    admin password (default luna)
#   DB_CONTAINER  mysql container for an optional pre-test throttle reset
#                 (default luna-db-1; reset is best-effort/skippable)
#
# Exits non-zero if any check fails.
set -u

BASE="${BASE:-http://localhost:8080}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@luna.local}"
ADMIN_PASS="${ADMIN_PASS:-luna}"
DB_CONTAINER="${DB_CONTAINER:-luna-db-1}"

fails=0
pass() { printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m %s\n' "$1"; fails=$((fails + 1)); }
note() { printf '  \033[33mNOTE\033[0m %s\n' "$1"; }

code()  { curl -s -o /dev/null -w '%{http_code}' "$BASE$1"; }
body()  { curl -s "$BASE$1"; }
tokfrom() { grep -oE 'csrf_token"[^>]*value="[^"]*"' "$1" | grep -oE 'value="[^"]*"' | head -1 | sed 's/value="//;s/"//'; }

# best-effort: clear the per-IP login throttle so login latency is deterministic
docker exec "$DB_CONTAINER" mysql -uroot -proot lunadb \
  -e "DELETE FROM luna_login_throttle;" >/dev/null 2>&1 \
  && note "reset login throttle via $DB_CONTAINER" \
  || note "skipped throttle reset (no DB access; fine on a fresh stack)"

echo "== smoke: public pages render =="
# /node/9 (not bare /node): mod_node takes the nid from the path, so bare /node die()s on the
# missing subdir and returns 200 with an empty body — a status-only check passes vacuously there.
# Assert a non-empty body too, so "renders" means rendered.
for p in / /node/9 /login; do
  c=$(code "$p"); [ "$c" = 200 ] && pass "GET $p -> 200" || fail "GET $p -> $c (expected 200)"
  [ -n "$(body "$p")" ] && pass "GET $p -> non-empty body" || fail "GET $p -> 200 but empty body"
done
body / | grep -q "Luna" && pass "home shows the site footer" || fail "home missing expected content"

echo "== hostile request keys: a parameter NAME cannot blank the page =="
# lunaModel::load_request() walks $_REQUEST and hands every parameter NAME to
# lunaTools::prepare_var_key(), whose result is interpolated into rdf:nodeID="..." when the render
# model is serialised for the transform. Before 0.9.8-alpha '"', '<' and '&' passed through
# unaltered: a double quote closed the attribute, '<' and '&' were illegal outright, the document
# stopped being well-formed, the transform yielded nothing, and the response was 200 with a
# zero-byte body. Every XSLT-rendered page, logged in or not, no credentials, one character.
#
# Two things make this suite the right place for it and status codes the wrong check. The failure
# IS a 200 — so a status-only assertion passes vacuously, exactly as the /node case above warns.
# And the access log records the header size (200 1110) for a zero-byte body, so byte-count
# monitoring reads healthy too. Assert the footer marker instead: it only appears if the transform
# actually ran, which is the single thing the bug destroys.
#
# '>' and "'" are here as the negative half. They never broke — '>' is legal unescaped in an XML
# attribute value, and squash_to_key() already maps "'" to '-'. Keeping them pins the boundary, so
# a later "simplification" of the allowlist that also drops legitimate characters is visible.
for p in / /login; do
  for k in 'a%22b' 'a%3Cb' 'a%26b' 'x%22y%22z' 'a%3Eb' 'a%27b'; do
    c=$(code "$p?$k=1")
    if [ "$c" = 200 ] && body "$p?$k=1" | grep -q "Luna"; then
      pass "GET $p?$k=1 -> 200 and the transform ran"
    else
      fail "GET $p?$k=1 -> $c and no rendered body (page blanked)"
    fi
  done
done

echo "== source/secret disclosure: sensitive paths denied (case-insensitive) =="
for p in /.git/HEAD /.GIT/HEAD /Dockerfile /DOCKERFILE /docker-compose.yml /DOCKER-COMPOSE.YML \
         /semantic/ontop/ontop.properties /luna/luna.domains/luna.default/ini/db.ini; do
  c=$(code "$p"); [ "$c" != 200 ] && pass "GET $p -> $c (denied)" || fail "GET $p -> 200 (LEAK)"
done

echo "== security headers present on the app response =="
H=$(curl -s -D - -o /dev/null "$BASE/")
echo "$H" | grep -qi "Content-Security-Policy:" && pass "CSP header present" || fail "CSP header missing"
echo "$H" | grep -qi "X-Frame-Options: *DENY" && pass "X-Frame-Options: DENY" || fail "X-Frame-Options missing/!=DENY"
echo "$H" | grep -qi "X-Content-Type-Options: *nosniff" && pass "X-Content-Type-Options: nosniff" || fail "X-Content-Type-Options missing"
echo "$H" | grep -qi "X-Powered-By:" && fail "X-Powered-By leaks the PHP version" || pass "no X-Powered-By"

echo "== Linked Data: content negotiation, dereferenceable URIs, outbound links =="
ct() { curl -s -o /dev/null -w '%{content_type}' -H "Accept: $2" "$BASE$1"; }
ct / 'text/html'   | grep -qi 'text/html'   && pass "GET / (Accept: text/html) -> text/html"   || fail "GET / not text/html"
ct / 'text/turtle' | grep -qi 'text/turtle' && pass "GET / (Accept: text/turtle) -> text/turtle (negotiated)" || fail "GET / not negotiated to turtle"
curl -s -D - -o /dev/null "$BASE/" | grep -qi '^Vary:.*Accept' && pass "Vary: Accept present" || fail "Vary: Accept missing"
S=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: text/html' "$BASE/id/root")
L=$(curl -s -o /dev/null -D - -H 'Accept: text/html' "$BASE/id/root" | awk 'tolower($1)=="location:"{print $2}' | tr -d "\r")
{ [ "$S" = 303 ] && echo "$L" | grep -qE '/$'; } && pass "/id/root (html) -> 303 to HTML doc" || fail "/id/root -> $S loc=$L (expected 303 to /)"
Sr=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: text/turtle' "$BASE/id/root")
Lr=$(curl -s -o /dev/null -D - -H 'Accept: text/turtle' "$BASE/id/root" | awk 'tolower($1)=="location:"{print $2}' | tr -d "\r")
{ [ "$Sr" = 303 ] && echo "$Lr" | grep -qE '/data/root$'; } && pass "/id/root (turtle) -> 303 to /data/root" || fail "/id/root rdf -> $Sr loc=$Lr"
c=$(code "/data/root"); [ "$c" = 200 ] && pass "/data/root -> 200 (RDF document)" || fail "/data/root -> $c"
c=$(curl -s -o /dev/null -w '%{http_code}' -H 'Accept: text/turtle' "$BASE/data/admin"); [ "$c" = 404 ] && pass "/data/admin (guest) -> 404 (ACL preserved)" || fail "/data/admin guest -> $c (expected 404)"
curl -s -H 'Accept: text/turtle' "$BASE/data/root" | grep -qi 'sameAs' && pass "/data/root carries an outbound sameAs link" || fail "/data/root missing outbound link"
body "/?output=jsonld" | grep -qi '"sameAs"' && pass "JSON-LD carries sameAs" || fail "JSON-LD missing sameAs"

echo "== authentication =="
JAR=$(mktemp); PAGE=$(mktemp)
curl -s -c "$JAR" "$BASE/login" -o "$PAGE"; T=$(tokfrom "$PAGE")
# correct credentials + token -> authenticated
curl -s -b "$JAR" -c "$JAR" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASS" --data-urlencode "csrf_token=$T" "$BASE/login" -o /dev/null
curl -s -b "$JAR" "$BASE/admin" | grep -qi "Administration" \
  && pass "valid login reaches the admin dashboard" || fail "valid login did NOT reach admin"
# wrong password -> not authenticated
JBAD=$(mktemp); PBAD=$(mktemp); curl -s -c "$JBAD" "$BASE/login" -o "$PBAD"; TB=$(tokfrom "$PBAD")
curl -s -b "$JBAD" -c "$JBAD" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=wrong-$RANDOM" --data-urlencode "csrf_token=$TB" "$BASE/login" -o /dev/null
curl -s -b "$JBAD" "$BASE/admin" | grep -qi "Administration" \
  && fail "wrong password reached admin (auth bypass!)" || pass "wrong password rejected"
# CSRF gate: a tokenless login POST must not authenticate
JNT=$(mktemp); curl -s -c "$JNT" "$BASE/login" -o /dev/null
curl -s -b "$JNT" -c "$JNT" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASS" "$BASE/login" -o /dev/null
curl -s -b "$JNT" "$BASE/admin" | grep -qi "Administration" \
  && fail "tokenless login authenticated (CSRF gate bypass!)" || pass "tokenless login rejected (CSRF gate)"

echo "== SQL injection: start/limit are clamped (no stacked SLEEP) =="
# authenticated; a vulnerable LIMIT sink would execute SLEEP(3) and take >3s
t0=$(date +%s.%N)
curl -s -b "$JAR" --get --data-urlencode 'limit=20;SELECT SLEEP(3)' "$BASE/edition/edit_texts/" -o /dev/null
t1=$(date +%s.%N)
el=$(echo "$t1 - $t0" | bc)
awk "BEGIN{exit !($el < 2.0)}" \
  && pass "injected limit returned in ${el}s (clamped)" || fail "injected limit took ${el}s (possible SQLi)"

rm -f "$JAR" "$PAGE" "$JBAD" "$PBAD" "$JNT" "$PAGE"
echo
if [ "$fails" -eq 0 ]; then echo "ALL CHECKS PASSED"; exit 0; else echo "$fails CHECK(S) FAILED"; exit 1; fi
