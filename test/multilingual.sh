#!/usr/bin/env bash
#
# Multilingual content test.
#
# A real defect, not a hypothetical: luna_texts was designed one row per (node, language), but
# nothing said so. The editor's UPDATE matched on nid alone and set the language column too, so
# saving the French text rewrote the English row to be French — silent content loss with no way
# back. The reader keyed rows by nid, so whichever row the database returned last became the page's
# text and the visitor's language was never consulted. And the RDF projection tried to prefer the
# request language by comparing a stored "fr" against the interface locale "fr-FR", which never
# matched, so the graph always got the first row and the other translations existed nowhere.
#
# Asserted here (each maps to one of those):
#   M1  two translations of one text coexist                      (the UNIQUE key admits both)
#   M2  editing one language leaves the other untouched           (the clobber is gone)
#   M3  a second save under the same language updates, not dupes  (the upsert is keyed right)
#   M4  the reader serves the language the visitor asked for      (?lang= reaches content)
#   M5  the reader falls back rather than showing nothing         (ladder, not a hole)
#   M6  the graph carries BOTH translations, language-tagged      (mirror is no longer lossy)
#
#   BASE=http://localhost:8080 test/multilingual.sh
#
set -u
BASE="${BASE:-http://localhost:8080}"
DB="${DB_CONTAINER:-luna-db-1}"
APP="${APP_CONTAINER:-luna-app-1}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@luna.local}"
ADMIN_PASS="${ADMIN_PASS:-luna}"

sql(){ docker exec "$DB" mysql -uroot -proot lunadb -N -e "$1" 2>/dev/null; }
fails=0
pass(){ printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail(){ printf '  \033[31mFAIL\033[0m %s\n' "$1"; fails=$((fails + 1)); }
tok(){ grep -oE 'csrf_token"[^>]*value="[^"]*"' "$1" | grep -oE 'value="[^"]*"' | head -1 | sed 's/value="//;s/"//'; }

PT=$(sql "SELECT id FROM luna_types WHERE lid='text';")
[ -n "$PT" ] || { echo "cannot resolve the text type; is the stack up?"; exit 2; }

teardown(){
  local n
  n=$(sql "SELECT nid FROM luna_nodes WHERE lid='ml_probe';")
  if [ -n "$n" ]; then
    sql "DELETE FROM luna_texts WHERE nid=$n;
         DELETE FROM luna_nodes_map WHERE nid1=$n OR nid2=$n;
         DELETE FROM luna_nodes WHERE nid=$n;"
    docker exec "$APP" sh -c 'curl -s -u "$SPARQL_AUTH_USER:$SPARQL_AUTH_PASS" -X POST \
      http://sparql-proxy:7878/update --data-urlencode \
      "update=DELETE WHERE { <'"${BASE%/}"'/id/ml_probe> ?p ?o }"' >/dev/null 2>&1
  fi
}
trap teardown EXIT
teardown

# --- log in -------------------------------------------------------------------------------------
sql "DELETE FROM luna_login_throttle;"
AJ=$(mktemp); AP=$(mktemp); curl -s -c "$AJ" "$BASE/login" -o "$AP"
curl -s -b "$AJ" -c "$AJ" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASS" --data-urlencode "csrf_token=$(tok $AP)" "$BASE/login" -o /dev/null
[ "$(curl -s -b "$AJ" -o /dev/null -w '%{http_code}' "$BASE/admin/admin_users")" = "200" ] \
  && pass "logged in as admin" || { fail "could not log in"; exit 1; }

post(){ local page="$1"; shift; local fp; fp=$(mktemp)
  curl -s -b "$AJ" "$BASE/$page" -o "$fp"
  curl -s -b "$AJ" "$@" --data-urlencode "csrf_token=$(tok "$fp")" "$BASE/$page" -o /dev/null
  rm -f "$fp"; }

# --- fixture: one text node carrying an English text ---------------------------------------------
HOME_NID=$(sql "SELECT nid FROM luna_nodes WHERE lid='root';")
post edition/edit_texts --data-urlencode submit=Add --data-urlencode mode=add \
  --data-urlencode add_text_lid=ml_probe --data-urlencode add_text_title=Hello \
  --data-urlencode add_text_lang=en-US --data-urlencode add_text_content='English body' \
  --data-urlencode "add_text_pages[]=$HOME_NID"
NID=$(sql "SELECT nid FROM luna_nodes WHERE lid='ml_probe';")
[ -n "$NID" ] && pass "fixture: text node created" || { fail "could not create the fixture"; exit 1; }

# --- M1/M2: add a French translation; English must survive ---------------------------------------
post edition/edit_texts --data-urlencode mode=modify --data-urlencode submit=Modify \
  --data-urlencode "modify_item_nid=$NID" --data-urlencode modify_text_lid=ml_probe \
  --data-urlencode modify_text_title=Bonjour --data-urlencode modify_text_lang=fr-FR \
  --data-urlencode modify_text_content='Corps francais' \
  --data-urlencode "modify_text_pages[]=$HOME_NID"

[ "$(sql "SELECT COUNT(*) FROM luna_texts WHERE nid=$NID;")" = "2" ] \
  && pass "M1 both translations coexist (2 rows)" \
  || fail "M1 expected 2 rows, got $(sql "SELECT COUNT(*) FROM luna_texts WHERE nid=$NID;")"

[ "$(sql "SELECT title FROM luna_texts WHERE nid=$NID AND lang='en';")" = "Hello" ] \
  && pass "M2 the English row is untouched by the French save (the clobber is gone)" \
  || fail "M2 CLOBBER: English row is now '$(sql "SELECT title FROM luna_texts WHERE nid=$NID AND lang='en';")'"

[ "$(sql "SELECT title FROM luna_texts WHERE nid=$NID AND lang='fr';")" = "Bonjour" ] \
  && pass "M2b the French row was written" || fail "M2b the French row is missing"

# --- M3: saving the same language again updates in place -----------------------------------------
post edition/edit_texts --data-urlencode mode=modify --data-urlencode submit=Modify \
  --data-urlencode "modify_item_nid=$NID" --data-urlencode modify_text_lid=ml_probe \
  --data-urlencode modify_text_title=Salut --data-urlencode modify_text_lang=fr-FR \
  --data-urlencode modify_text_content='Corps francais 2' \
  --data-urlencode "modify_text_pages[]=$HOME_NID"
[ "$(sql "SELECT COUNT(*) FROM luna_texts WHERE nid=$NID;")" = "2" ] \
  && [ "$(sql "SELECT title FROM luna_texts WHERE nid=$NID AND lang='fr';")" = "Salut" ] \
  && pass "M3 re-saving a language updates that row, creating no duplicate" \
  || fail "M3 the upsert did not update in place"

# --- M4/M5: the reader honours the requested language --------------------------------------------
EN=$(curl -s -b "$AJ" "$BASE/?lang=en" | grep -c 'English body' || true)
FR=$(curl -s -b "$AJ" "$BASE/?lang=fr" | grep -c 'Corps francais 2' || true)
[ "$EN" -ge 1 ] && pass "M4 ?lang=en serves the English body" || fail "M4 English body not served for ?lang=en"
[ "$FR" -ge 1 ] && pass "M4b ?lang=fr serves the French body" || fail "M4b French body not served for ?lang=fr"

# Drop the English row: a visitor asking for English must now fall back, not get an empty page.
sql "DELETE FROM luna_texts WHERE nid=$NID AND lang='en';"
docker exec "$APP" php bin/resync-triplestore.php >/dev/null 2>&1
FB=$(curl -s -b "$AJ" "$BASE/?lang=en" | grep -c 'Corps francais 2' || true)
[ "$FB" -ge 1 ] && pass "M5 a missing translation falls back instead of rendering nothing" \
  || fail "M5 no fallback: the page rendered without any text"

# --- M6: the graph carries both translations, language-tagged ------------------------------------
sql "INSERT INTO luna_texts (nid,title,lang,content) VALUES ($NID,'Hello','en','English body')
     ON DUPLICATE KEY UPDATE title=VALUES(title), content=VALUES(content);"
docker exec "$APP" php bin/resync-triplestore.php >/dev/null 2>&1
Q='SELECT ?t ?l WHERE { <'"${BASE%/}"'/id/ml_probe> <https://schema.org/headline> ?t BIND(lang(?t) AS ?l) }'
RES=$(docker exec "$APP" sh -c 'curl -s -u "$SPARQL_AUTH_USER:$SPARQL_AUTH_PASS" \
  -H "Accept: application/sparql-results+json" --data-urlencode "query='"$Q"'" \
  http://sparql-proxy:7878/query' 2>/dev/null)
echo "$RES" | grep -q '"en"' && echo "$RES" | grep -q '"fr"' \
  && pass "M6 the graph holds BOTH headlines, tagged @en and @fr" \
  || fail "M6 the graph is still lossy: $(echo "$RES" | head -c 200)"

rm -f "$AJ" "$AP"
echo
if [ "$fails" -eq 0 ]; then printf '\033[32mMULTILINGUAL CONTENT HOLDS\033[0m\n'; exit 0
else printf '\033[31m%d CHECK(S) FAILED\033[0m\n' "$fails"; exit 1; fi
