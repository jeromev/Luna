#!/usr/bin/env bash
#
# Delegated-admin authorization test (validates the B1 per-target authz guards).
#
# The shipped config has a single admin tier, so the guards can't be exercised on
# their DENY path by the normal stack. This test manufactures a delegated admin:
# it re-binds the admin_groups page + module down to `level_edition`, creates a
# user who holds only level_public + level_edition (NOT level_admin), and then —
# as that user — tries to grant `level_admin` to a group. The guard must refuse,
# proving a lower-tier admin cannot escalate privilege. Everything is torn down.
#
#   BASE=http://localhost:8080 test/delegated_admin.sh
#
set -u
BASE="${BASE:-http://localhost:8080}"
DB="${DB_CONTAINER:-luna-db-1}"
APP="${APP_CONTAINER:-luna-app-1}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@luna.local}"
ADMIN_PASS="${ADMIN_PASS:-luna}"
DPASS='DelTest12345!'

sql(){ docker exec "$DB" mysql -uroot -proot lunadb -N -e "$1" 2>/dev/null; }
# Drop every triple mentioning a resource URI from Oxigraph, through the authenticating
# proxy. teardown() deletes the test user with raw SQL, which bypasses the model's
# write-through — so without this its mirrored triples (e.g. `a foaf:Person`) orphan in
# the triplestore and accrue across runs. Mirrors lunaModel::rdf_delete_node(); best-effort.
rdf_purge(){ # $1 = resource URI (no angle brackets)
  docker exec "$APP" sh -c 'curl -s -u "$SPARQL_AUTH_USER:$SPARQL_AUTH_PASS" -X POST \
    http://sparql-proxy:7878/update --data-urlencode \
    "update=DELETE WHERE { <'"$1"'> ?p ?o } ; DELETE WHERE { ?s ?p <'"$1"'> }"' >/dev/null 2>&1
}
fails=0
pass(){ printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail(){ printf '  \033[31mFAIL\033[0m %s\n' "$1"; fails=$((fails + 1)); }
tok(){ grep -oE 'csrf_token"[^>]*value="[^"]*"' "$1" | grep -oE 'value="[^"]*"' | head -1 | sed 's/value="//;s/"//'; }

# --- resolve ids ---
PT=$(sql "SELECT id FROM luna_types WHERE lid='page';")
GED=$(sql "SELECT nid FROM luna_nodes WHERE lid='group_edition';")
LADMIN=$(sql "SELECT nid FROM luna_nodes WHERE lid='level_admin';")
LEDIT=$(sql "SELECT nid FROM luna_nodes WHERE lid='level_edition';")
LPUB=$(sql "SELECT nid FROM luna_nodes WHERE lid='level_public';")
AGPAGE=$(sql "SELECT nid FROM luna_nodes WHERE lid='admin_groups' AND tid=$PT;")
AGMOD=$(sql "SELECT nid FROM luna_nodes WHERE lid='mod_admin_groups';")
ADMINP=$(sql "SELECT nid FROM luna_nodes WHERE lid='admin' AND tid=$PT;")  # parent page in the path admin/admin_groups

rebind(){ # $1=from $2=to : move admin_groups page+mod level links (both directions)
  sql "UPDATE luna_nodes_map SET nid2=$2 WHERE nid1 IN ($ADMINP,$AGPAGE,$AGMOD) AND nid2=$1;"
  sql "UPDATE luna_nodes_map SET nid1=$2 WHERE nid2 IN ($ADMINP,$AGPAGE,$AGMOD) AND nid1=$1;"
  docker exec "$APP" sh -c 'rm -f /var/www/html/luna/luna.domains/luna.default/cache/* 2>/dev/null' 2>/dev/null
}
teardown(){
  rebind "$LEDIT" "$LADMIN"
  sql "DELETE FROM luna_nodes_map WHERE nid1=$GED AND nid2=$LADMIN;"   # undo any escalation link
  sql "DELETE FROM luna_nodes_map WHERE nid2=$GED AND nid1=$LADMIN;"
  sql "SET @u:=(SELECT nid FROM luna_nodes WHERE lid='delegated@test.local');
       DELETE FROM luna_nodes_map WHERE nid1=@u OR nid2=@u;
       DELETE FROM luna_users WHERE nid=@u;
       DELETE FROM luna_nodes WHERE nid=@u;"
  # the raw SQL above bypasses the model, so also evict the user's mirrored triples
  # from Oxigraph (lid 'delegated@test.local' -> /id/delegated%40test.local)
  rdf_purge "${BASE%/}/id/delegated%40test.local"
}
trap teardown EXIT
sql "DELETE FROM luna_login_throttle;"

# --- admin creates the delegated user in group_edition ---
AJ=$(mktemp); AP=$(mktemp); curl -s -c "$AJ" "$BASE/login" -o "$AP"
curl -s -b "$AJ" -c "$AJ" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASS" --data-urlencode "csrf_token=$(tok $AP)" "$BASE/login" -o /dev/null
FP=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_users" -o "$FP"
curl -s -b "$AJ" --data-urlencode submit=Add --data-urlencode mode=add \
  --data-urlencode add_user_email=delegated@test.local --data-urlencode add_user_firstname=Del \
  --data-urlencode add_user_lastname=Egate --data-urlencode add_user_password="$DPASS" \
  --data-urlencode "add_user_groups[]=group_edition" --data-urlencode "csrf_token=$(tok $FP)" "$BASE/admin/admin_users" -o /dev/null
DUID=$(sql "SELECT nid FROM luna_nodes WHERE lid='delegated@test.local';")
[ -n "$DUID" ] && pass "created delegated user (group_edition, nid $DUID)" || { fail "could not create delegated user"; exit 1; }

# --- make admin_groups reachable at level_edition ---
rebind "$LADMIN" "$LEDIT"
sql "DELETE FROM luna_login_throttle;"

# --- log in as the delegated admin ---
DJ=$(mktemp); DP=$(mktemp); curl -s -c "$DJ" "$BASE/login" -o "$DP"
curl -s -b "$DJ" -c "$DJ" --data-urlencode submit=login --data-urlencode email=delegated@test.local \
  --data-urlencode "password=$DPASS" --data-urlencode "csrf_token=$(tok $DP)" "$BASE/login" -o /dev/null
GP=$(mktemp); curl -s -b "$DJ" "$BASE/admin/admin_groups?group_lid=group_edition&sparql=0" -o "$GP"
grep -qiE "Groups administration|Accessible levels|modify_group" "$GP" \
  && pass "delegated admin reaches admin_groups (re-bound to level_edition)" \
  || fail "delegated admin could not reach admin_groups (setup issue)"

# --- escalation attempt: grant level_admin to a group ---
# The form addresses the group and the levels by lid since 0.9.10-alpha. The escalation guard
# still has to FIRE here rather than the request merely failing to resolve: load_nodes('level')
# applies no access filter to its own type (only to a joined one), so level_admin resolves for
# this delegated admin exactly as its nid did, and user_can_access_level() is what refuses it.
# If a future change scopes that load, this attempt would be dropped at resolution instead and
# the "Access denied" assertion below would fail — which is the point of asserting the message
# and not only the absence of the grant.
RESP=$(curl -s -b "$DJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=group_edition" --data-urlencode modify_group_lid=group_edition \
  --data-urlencode "modify_group_levels[]=level_public" --data-urlencode "modify_group_levels[]=level_edition" \
  --data-urlencode "modify_group_levels[]=level_admin" --data-urlencode "csrf_token=$(tok $GP)" "$BASE/admin/admin_groups?sparql=0")
echo "$RESP" | grep -qiE "Access denied|above your own" \
  && pass "escalation DENIED: a delegated admin cannot grant level_admin" \
  || fail "escalation was NOT denied — the guard failed!"
GRANTED=$(sql "SELECT COUNT(*) FROM luna_nodes_map WHERE nid1=$GED AND nid2=$LADMIN;")
[ "$GRANTED" = 0 ] && pass "group_edition was NOT granted level_admin" || fail "PRIVESC: group_edition got level_admin"

rm -f "$AJ" "$AP" "$FP" "$DJ" "$DP" "$GP"
echo
if [ "$fails" -eq 0 ]; then echo "DELEGATED-ADMIN GUARDS HOLD"; exit 0; else echo "$fails CHECK(S) FAILED"; exit 1; fi
