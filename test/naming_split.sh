#!/usr/bin/env bash
#
# Routing key vs. display name — roadmap decision #9.
#
# A page has two names and they are not the same kind of thing. The SLUG (`edit_texts`) is a
# permanent, language-independent routing key: the segment /id/{slug} is built from, and what the
# SPARQL router matches a request path against. The DISPLAY NAME ("Edit texts") is a human-readable
# label, produced per request by translating the slug through the gettext catalogue, so it differs
# by language and is not stored anywhere.
#
# Before this split, `schema:name` carried both — the slug in the triplestore, the display name in
# the published document — so the same subject answered the same predicate two different ways
# depending on which surface you asked, and neither surface could produce the other's answer. That
# is what blocked backing the published representation with a SPARQL CONSTRUCT: a CONSTRUCT over the
# store would have regressed every published page name to its slug.
#
# Asserted here:
#   N1  the store carries the slug under luna:lid
#   N2  the store does NOT carry the slug under schema:name  (the overload is gone, not duplicated)
#   N3  the site still resolves, and access control still behaves
#   N4  the published document carries BOTH, and they are different values
#   N5  every published serialisation agrees (no format-dependent description)
#   N6  a router-shaped query over luna:lid returns the page tree
#
# WHAT THIS FILE DOES NOT COVER, said plainly. Reverting *only* the SPARQL router to schema:name is
# not detectable from here, and it was verified that it is not: the read path falls back to the
# hand-written SQL joins whenever a SPARQL read comes back empty, so every page still resolves with
# the correct content and N3 passes. That fallback is deliberate (SPARQL_ENABLED=0 must serve the
# whole site), but it means the SPARQL read path has no failure mode observable over HTTP — it
# degrades silently to SQL. N6 is the closest available proxy: it proves the STORE can answer a
# router-shaped query, so a store-side regression is caught. A router-only regression would need a
# test that can see which source answered, which nothing here can. Naming the gap is worth more than
# an assertion that looks like coverage and is not.
#
#   BASE=http://localhost:8080 test/naming_split.sh
#
set -u
BASE="${BASE:-http://localhost:8080}"
APP="${APP_CONTAINER:-luna-app-1}"
DB="${DB_CONTAINER:-luna-db-1}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@luna.local}"
ADMIN_PASS="${ADMIN_PASS:-luna}"

fails=0
pass(){ printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail(){ printf '  \033[31mFAIL\033[0m %s\n' "$1"; fails=$((fails + 1)); }
sql(){ docker exec "$DB" mysql -uroot -proot lunadb -N -e "$1" 2>/dev/null; }

# The namespace is read from the code, never hardcoded here: house rule 11 holds every other site to
# LUNA_NS, and a test that pinned its own copy would be the one place free to drift.
NS=$(grep -oE "const LUNA_NS = '[^']+'" luna/luna.classes/luna.model.class.php | grep -oE "https?://[^']+")
[ -n "$NS" ] && pass "resolved LUNA_NS from source: $NS" || { fail "could not resolve LUNA_NS"; exit 1; }

ask(){ docker exec "$APP" sh -c 'curl -s -u "$SPARQL_AUTH_USER:$SPARQL_AUTH_PASS" \
  -H "Accept: application/sparql-results+json" --data-urlencode "query='"$1"'" \
  http://sparql-proxy:7878/query' 2>/dev/null; }

# --- N1/N2: the store's side of the split ---------------------------------------------------------
R=$(ask "SELECT ?l WHERE { <$BASE/id/root> <${NS}lid> ?l }")
echo "$R" | grep -q '"root"' \
  && pass "N1 the store carries the slug under luna:lid" \
  || fail "N1 luna:lid missing from the store: $(echo "$R" | head -c 160)"

R=$(ask "SELECT ?n WHERE { <$BASE/id/root> <https://schema.org/name> ?n }")
if echo "$R" | grep -q '"root"'; then
  fail "N2 OVERLOAD: schema:name still carries the slug in the store"
else
  pass "N2 the store no longer carries the slug under schema:name"
fi

# --- N3: the site still resolves ------------------------------------------------------------------
# A plain regression guard, and deliberately NOT claimed as evidence that the router moved — see the
# header. The SQL fallback means this passes either way.
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/")
[ "$code" = "200" ] && pass "N3 the site still resolves / after the split" \
  || fail "N3 / returned $code"

# A level-gated page must still 404 for a guest (the deliberate 404-not-403), and 200 for an admin.
code=$(curl -s -o /dev/null -w '%{http_code}' "$BASE/edition/edit_texts")
[ "$code" = "404" ] && pass "N3b a gated page still 404s for a guest" \
  || fail "N3b gated page returned $code for a guest, expected 404"

AJ=$(mktemp); AP=$(mktemp)
tok(){ grep -oE 'csrf_token"[^>]*value="[^"]*"' "$1" | grep -oE 'value="[^"]*"' | head -1 | sed 's/value="//;s/"//'; }
sql "DELETE FROM luna_login_throttle;"
curl -s -c "$AJ" "$BASE/login" -o "$AP"
curl -s -b "$AJ" -c "$AJ" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASS" --data-urlencode "csrf_token=$(tok $AP)" "$BASE/login" -o /dev/null
code=$(curl -s -b "$AJ" -o /dev/null -w '%{http_code}' "$BASE/edition/edit_texts")
[ "$code" = "200" ] && pass "N3c the same page resolves for an admin" \
  || fail "N3c gated page returned $code for an admin, expected 200"
rm -f "$AJ" "$AP"

# --- N4: the published document carries both, and they differ -------------------------------------
DOC=$(curl -s "$BASE/?output=jsonld")
SLUG=$(echo "$DOC" | grep -oE '"'"${NS}"'lid"[[:space:]]*:[[:space:]]*"[^"]*"' | grep -oE '"[^"]*"$' | tr -d '"')
NAME=$(echo "$DOC" | grep -oE '"name"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | grep -oE '"[^"]*"$' | tr -d '"')
[ -n "$SLUG" ] && pass "N4 the document states the slug (luna:lid = $SLUG)" \
  || fail "N4 the document does not state the slug"
[ -n "$NAME" ] && pass "N4b the document states a display name (schema:name = $NAME)" \
  || fail "N4b the document has no schema:name"
if [ -n "$SLUG" ] && [ -n "$NAME" ] && [ "$SLUG" != "$NAME" ]; then
  pass "N4c they are different values — the predicate no longer means two things"
else
  fail "N4c slug and display name are indistinguishable ('$SLUG' vs '$NAME')"
fi

# --- N5: every serialisation describes the resource the same way ----------------------------------
missing=""
for fmt in xml n3 json jsonld; do
  curl -s "$BASE/?output=$fmt" | grep -q "lid" || missing="$missing $fmt"
done
[ -z "$missing" ] && pass "N5 all four RDF serialisations carry the slug" \
  || fail "N5 the slug is missing from:$missing (a consumer would get a different description per format)"

# --- N6: the store can answer a router-shaped query ------------------------------------------------
# The nearest thing to testing the read path that is actually observable. If the write-through
# regressed, or luna:lid were minted under the wrong namespace, this binds nothing.
R=$(ask "SELECT (COUNT(DISTINCT ?p) AS ?n) WHERE { ?p a <https://schema.org/WebPage> ; <${NS}lid> ?l ; <${NS}isActive> ?a }")
N=$(echo "$R" | grep -oE '"value"[[:space:]]*:[[:space:]]*"[0-9]+"' | grep -oE '[0-9]+' | head -1)
[ -n "$N" ] && [ "$N" -ge 5 ] \
  && pass "N6 a router-shaped query over luna:lid returns the page tree ($N pages)" \
  || fail "N6 router-shaped query bound ${N:-0} pages — the store cannot serve the router"

echo
if [ "$fails" -eq 0 ]; then printf '\033[32mROUTING KEY AND DISPLAY NAME ARE SEPARATE\033[0m\n'; exit 0
else printf '\033[31m%d CHECK(S) FAILED\033[0m\n' "$fails"; exit 1; fi
