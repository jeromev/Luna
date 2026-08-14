#!/usr/bin/env bash
#
# Admin-lockout guardrail test.
#
# A real incident: the sole admin removed their own user from `group_admin` via the
# user editor and locked every administrator out of the running site (group_admin ->
# level_admin -> the admin pages is the only path in). This test asserts that the
# guardrails refuse every move that would leave the site with no working admin path,
# while ordinary admin operations still succeed.
#
# Vectors (all reachable by the shipped single admin, all must be BLOCKED):
#   T1  strip group_admin from your own user (mod_admin_users::submit_modify)
#   T2  delete a protected admin page        (mod_admin_pages::submit_delete)
#   T3  delete a protected admin module      (mod_admin_mods::submit_delete)
#   T4  delete the last/own admin user       (mod_admin_users::submit_delete)
# Controls (must SUCCEED — proves the guards don't over-block and the delete path works):
#   P1  create an ordinary user
#   P2  delete that ordinary user       (mode=modify + submit=Delete)
#   P4  create and delete another       (mode=delete — the switch's other delete arm)
#   P3  the admin can still reach /admin after every attempt
#
#   BASE=http://localhost:8080 test/admin_lockout.sh
#
set -u
BASE="${BASE:-http://localhost:8080}"
DB="${DB_CONTAINER:-lunarsystem-db-1}"
APP="${APP_CONTAINER:-lunarsystem-app-1}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@lunarsystem.local}"
ADMIN_PASS="${ADMIN_PASS:-luna}"
TPASS='ThrowAway12345!'

sql(){ docker exec "$DB" mysql -uroot -proot lunadb -N -e "$1" 2>/dev/null; }
rdf_purge(){ docker exec "$APP" sh -c 'curl -s -u "$SPARQL_AUTH_USER:$SPARQL_AUTH_PASS" -X POST \
  http://sparql-proxy:7878/update --data-urlencode \
  "update=DELETE WHERE { <'"$1"'> ?p ?o } ; DELETE WHERE { ?s ?p <'"$1"'> }"' >/dev/null 2>&1; }
fails=0
pass(){ printf '  \033[32mPASS\033[0m %s\n' "$1"; }
fail(){ printf '  \033[31mFAIL\033[0m %s\n' "$1"; fails=$((fails + 1)); }
tok(){ grep -oE 'csrf_token"[^>]*value="[^"]*"' "$1" | grep -oE 'value="[^"]*"' | head -1 | sed 's/value="//;s/"//'; }

# --- resolve structural nids ---
PT=$(sql "SELECT id FROM luna_types WHERE lid='page';")
UADMIN=$(sql "SELECT nid FROM luna_nodes WHERE lid='$ADMIN_EMAIL';")
GADMIN=$(sql "SELECT nid FROM luna_nodes WHERE lid='group_admin';")
GDEF=$(sql "SELECT nid FROM luna_nodes WHERE lid='group_default';")
AUPAGE=$(sql "SELECT nid FROM luna_nodes WHERE lid='admin_users' AND tid=$PT;")
MAU=$(sql "SELECT nid FROM luna_nodes WHERE lid='mod_admin_users';")
[ -n "$UADMIN" ] && [ -n "$GADMIN" ] || { echo "cannot resolve admin/group nids; is the stack up?"; exit 2; }

edge(){ sql "SELECT COUNT(*) FROM luna_nodes_map WHERE (nid1=$1 AND nid2=$2) OR (nid1=$2 AND nid2=$1);"; }
exists(){ sql "SELECT COUNT(*) FROM luna_nodes WHERE nid=$1;"; }

teardown(){
  for who in throwaway@test.local throwaway2@test.local; do
    sql "SET @u:=(SELECT nid FROM luna_nodes WHERE lid='$who');
         DELETE FROM luna_nodes_map WHERE nid1=@u OR nid2=@u;
         DELETE FROM luna_users WHERE nid=@u;
         DELETE FROM luna_nodes WHERE nid=@u;" 2>/dev/null
  done
  rdf_purge "${BASE%/}/id/throwaway%40test.local"
  rdf_purge "${BASE%/}/id/throwaway2%40test.local"
  # safety net: if a guard regressed and a test actually locked the admin out, put it back.
  if [ "$(edge "$UADMIN" "$GADMIN")" -lt 2 ]; then
    sql "INSERT INTO luna_nodes_map (nid1,nid2) VALUES ($UADMIN,$GADMIN),($GADMIN,$UADMIN);"
    docker-compose exec -T app php bin/resync-triplestore.php >/dev/null 2>&1
    echo "  (teardown restored admin<->group_admin membership)"
  fi
}
trap teardown EXIT

# --- log in as the admin ---
sql "DELETE FROM luna_login_throttle;"
AJ=$(mktemp); AP=$(mktemp); curl -s -c "$AJ" "$BASE/login" -o "$AP"
curl -s -b "$AJ" -c "$AJ" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASS" --data-urlencode "csrf_token=$(tok $AP)" "$BASE/login" -o /dev/null
[ "$(curl -s -b "$AJ" -o /dev/null -w '%{http_code}' "$BASE/admin/admin_users")" = "200" ] \
  && pass "logged in as admin" || { fail "could not log in as admin"; exit 1; }

post(){ # $1=page  $2..=extra --data-urlencode args ; pulls a fresh CSRF token from $1
  local page="$1"; shift
  local fp; fp=$(mktemp); curl -s -b "$AJ" "$BASE/$page" -o "$fp"
  curl -s -b "$AJ" "$@" --data-urlencode "csrf_token=$(tok "$fp")" "$BASE/$page" -o /dev/null
  rm -f "$fp"
}

echo "--- lockout attempts (must be blocked) ---"

# T1: strip group_admin from your own account, keeping only group_default
post admin/admin_users --data-urlencode mode=modify --data-urlencode submit=Modify \
  --data-urlencode "user_nid=$UADMIN" --data-urlencode "modify_item_nid=$UADMIN" \
  --data-urlencode "modify_user_email=$ADMIN_EMAIL" --data-urlencode "modify_user_firstname=Admin" \
  --data-urlencode "modify_user_lastname=Luna" --data-urlencode "modify_user_groups[]=$GDEF"
[ "$(edge "$UADMIN" "$GADMIN")" -ge 2 ] \
  && pass "T1 self-removal from group_admin blocked (membership intact)" \
  || fail "T1 LOCKOUT: admin was removed from group_admin"

# T2: delete a protected admin page
post admin/admin_pages --data-urlencode mode=modify --data-urlencode submit=Delete \
  --data-urlencode "modify_item_nid=$AUPAGE"
[ "$(exists "$AUPAGE")" -ge 1 ] \
  && pass "T2 deletion of the admin_users page blocked" \
  || fail "T2 LOCKOUT: admin_users page was deleted"

# T3: delete a protected admin module
post admin/admin_mods --data-urlencode mode=modify --data-urlencode submit=Delete \
  --data-urlencode "modify_item_nid=$MAU"
[ "$(exists "$MAU")" -ge 1 ] \
  && pass "T3 deletion of mod_admin_users blocked" \
  || fail "T3 LOCKOUT: mod_admin_users was deleted"

# T4: delete the last/own admin user
post admin/admin_users --data-urlencode mode=modify --data-urlencode submit=Delete \
  --data-urlencode "user_nid=$UADMIN" --data-urlencode "modify_item_nid=$UADMIN" \
  --data-urlencode "modify_user_email=$ADMIN_EMAIL"
[ "$(exists "$UADMIN")" -ge 1 ] \
  && pass "T4 deletion of the last admin user blocked" \
  || fail "T4 LOCKOUT: the admin user was deleted"

echo "--- controls (must succeed — guards must not over-block) ---"

# P1: create an ordinary user
post admin/admin_users --data-urlencode submit=Add --data-urlencode mode=add \
  --data-urlencode add_user_email=throwaway@test.local --data-urlencode add_user_firstname=Throw \
  --data-urlencode add_user_lastname=Away --data-urlencode add_user_password="$TPASS" \
  --data-urlencode "add_user_groups[]=$GDEF"
TUID=$(sql "SELECT nid FROM luna_nodes WHERE lid='throwaway@test.local';")
[ -n "$TUID" ] && pass "P1 ordinary user creation works" || fail "P1 could not create an ordinary user"

# P2: delete that ordinary user (also proves submit=Delete really routes to submit_delete,
#     so T2/T3/T4 'survived' verdicts are genuine blocks, not mis-routed no-ops)
if [ -n "$TUID" ]; then
  post admin/admin_users --data-urlencode mode=modify --data-urlencode submit=Delete \
    --data-urlencode "user_nid=$TUID" --data-urlencode "modify_item_nid=$TUID" \
    --data-urlencode "modify_user_email=throwaway@test.local"
  [ -z "$(sql "SELECT nid FROM luna_nodes WHERE lid='throwaway@test.local';")" ] \
    && pass "P2 ordinary user deletion works (delete path intact)" \
    || fail "P2 ordinary user was NOT deleted (guards over-block)"
fi

# P4: the `mode=delete` branch of the dispatcher switch. P2 reaches submit_delete() the other
#     way (mode=modify + submit=Delete), so without this the `case 'delete':` arm — a distinct
#     branch, reachable from any mod that posts mode=delete — is exercised by nothing anywhere
#     in the repository. It is the one write path no gate covered.
post admin/admin_users --data-urlencode submit=Add --data-urlencode mode=add \
  --data-urlencode add_user_email=throwaway2@test.local --data-urlencode add_user_firstname=Throw \
  --data-urlencode add_user_lastname=Away2 --data-urlencode add_user_password="$TPASS" \
  --data-urlencode "add_user_groups[]=$GDEF"
TUID2=$(sql "SELECT nid FROM luna_nodes WHERE lid='throwaway2@test.local';")
[ -n "$TUID2" ] && pass "P4a second ordinary user created" || fail "P4a could not create the second user"

if [ -n "$TUID2" ]; then
  post admin/admin_users --data-urlencode submit=Delete --data-urlencode mode=delete \
    --data-urlencode "user_nid=$TUID2" --data-urlencode "modify_item_nid=$TUID2" \
    --data-urlencode "modify_user_email=throwaway2@test.local" \
    --data-urlencode modify_user_firstname=Throw --data-urlencode modify_user_lastname=Away2
  [ -z "$(sql "SELECT nid FROM luna_nodes WHERE lid='throwaway2@test.local';")" ] \
    && pass "P4b mode=delete reaches submit_delete and deletes" \
    || fail "P4b mode=delete did NOT delete the user"
fi

# P3: admin can still administer the site
[ "$(curl -s -b "$AJ" -o /dev/null -w '%{http_code}' "$BASE/admin/admin_users")" = "200" ] \
  && pass "P3 admin access still works after every attempt" \
  || fail "P3 admin access is broken"

rm -f "$AJ" "$AP"
echo
if [ "$fails" -eq 0 ]; then printf '\033[32mADMIN-LOCKOUT GUARDRAILS HOLD\033[0m\n'; exit 0
else printf '\033[31m%d CHECK(S) FAILED\033[0m\n' "$fails"; exit 1; fi
