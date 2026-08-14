#!/usr/bin/env bash
#
# The frontend addresses nodes by lid, not by nid — roadmap decision #4, continued.
#
# 0.9.9-alpha retired /node/{nid}, which was the only place the integer was published as an
# IDENTITY. It left the integer everywhere it was published as an ADDRESS: every admin link
# (?group_nid=4), every hidden form field (modify_item_nid), every <option value="7"> in a
# picker. Those are not identity claims, so nothing about them was wrong on the Semantic Web
# terms decision #4 was argued on — but they are the database's autoincrement counter on the
# wire, and they mean a URL cannot be written, read or kept by a human, and cannot survive a
# reseed. The slug can do all three.
#
# The conversion is a BOUNDARY conversion, and that is the thing this file is really guarding.
# The frontend speaks lids; PHP resolves each one to a nid at the request boundary and every
# write path below it is untouched — the SQL, the edge table, insert_action(), and above all
# the access checks still operate on nids exactly as before. So the risk is not that a link
# breaks (that is loud); it is that resolution silently returns nothing and a form appears to
# work while saving less than it claims, or that resolution is done at full scope and hands
# back a nid for a node the requester may not address.
#
# Resolution therefore goes through get_node_from_slug(), which scans the ACL-scoped working
# index, and never get_nid_from_lid(), which queries the node table at full scope. The
# difference is the whole security content of the change.
#
# Asserted here, per converted screen:
#   L1  the list links by lid, and carries no ?{type}_nid= anywhere
#   L2  the pickers are keyed by lid
#   L3  ?{type}_lid= reaches the modify form
#   L4  a lid-addressed save round-trips to the database  (the write path really works)
#   L5  a lid that does not resolve is refused, and changes nothing
#
# L4 is the one that matters most, and it exists because L1–L3 can all pass on a screen whose
# save silently does nothing: a resolution that drops everything renders a perfectly correct
# page. The privilege half is covered next door in delegated_admin.sh, which asserts that the
# escalation guard still FIRES (by its message) rather than the attempt merely failing to
# resolve — see the comment there.
#
# SCREENS COVERED SO FAR: admin_groups, admin_users, admin_levels. The rest still address by
# nid and are converted one release at a time; this file grows with them, and the nid-free sweeps (L1b, L6b)
# are deliberately scoped to the converted screen rather than to the whole admin, so they cannot
# pass vacuously while an unconverted screen still emits integers.
#
#   BASE=http://localhost:8080 test/lid_addressing.sh
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
tok(){ grep -oE 'csrf_token"[^>]*value="[^"]*"' "$1" | grep -oE 'value="[^"]*"' | head -1 | sed 's/value="//;s/"//'; }
# the levels a group currently holds, as lids, one per line, sorted
levels_of(){ sql "SELECT n2.lid FROM luna_nodes_map m
  JOIN luna_nodes n1 ON m.nid1 = n1.nid
  JOIN luna_nodes n2 ON m.nid2 = n2.nid
  WHERE n1.lid = '$1' AND n2.tid = (SELECT id FROM luna_types WHERE lid = 'level')
  ORDER BY n2.lid;"; }

sql "DELETE FROM luna_login_throttle;"

# --- fresh admin session ---
AJ=$(mktemp); AP=$(mktemp)
curl -s -c "$AJ" "$BASE/login" -o "$AP"
curl -s -b "$AJ" -c "$AJ" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASS" --data-urlencode "csrf_token=$(tok $AP)" "$BASE/login" -o /dev/null

echo "== admin_groups =="
LP=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_groups?lang=en-US" -o "$LP"

grep -q 'admin_groups?group_lid=group_admin' "$LP" \
  && pass "L1 the groups list links by lid" \
  || fail "L1 the groups list does not link by lid"
grep -qE 'admin_groups\?group_nid=' "$LP" \
  && fail "L1b a ?group_nid= link survives on the groups list" \
  || pass "L1b no ?group_nid= link survives on the groups list"

grep -q '<option label="Public level" value="level_public"' "$LP" \
  && pass "L2 the levels picker is keyed by lid" \
  || fail "L2 the levels picker is not keyed by lid"

# --- L3: the lid reaches the modify form ---
GP=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_groups?group_lid=group_edition&lang=en-US" -o "$GP"
grep -q 'name="modify_item_lid"' "$GP" \
  && pass "L3 ?group_lid= reaches the modify form" \
  || fail "L3 ?group_lid= did not reach the modify form"
grep -q 'name="modify_item_lid" value="group_edition"' "$GP" \
  && pass "L3b the form is addressed by the lid it was asked for" \
  || fail "L3b the form does not carry the requested lid"

# The members checkbox column, which is its own control and was NOT covered when this screen was
# converted: it renders one checkbox per member carrying that user's lid. 0.9.10-alpha shipped it
# emitting name="modify_users_list[]" value="" — luna:lid was absent from foaf:Person, so the
# lookup resolved to nothing and the batch action posted an empty identifier. Nothing caught it
# because that baseline was NEW, and a new baseline is captured rather than diffed, so no reader
# is forced past it. Assert the control carries an actual lid, not merely that it exists.
GA=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_groups?group_lid=group_admin&lang=en-US" -o "$GA"
grep -qE 'name="modify_users_list\[[^]]+\]" value="[^"]+"' "$GA" \
  && pass "L3c the members checkbox carries a member's lid" \
  || fail "L3c the members checkbox carries an empty identifier"

# --- L4: a lid-addressed save round-trips ---
# Grant level_admin, assert the database really changed, then put it back. The revert is not
# tidiness: the render baselines are captured against the seeded level set, so a suite that
# leaves this group holding an extra level makes every later baseline run disagree.
BEFORE=$(levels_of group_edition)
RESP=$(curl -s -b "$AJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=group_edition" --data-urlencode modify_group_lid=group_edition \
  --data-urlencode "modify_group_levels[]=level_public" --data-urlencode "modify_group_levels[]=level_edition" \
  --data-urlencode "modify_group_levels[]=level_admin" --data-urlencode "csrf_token=$(tok $GP)" \
  "$BASE/admin/admin_groups?lang=en-US")
echo "$RESP" | grep -qiE 'has been modified' \
  && pass "L4 a lid-addressed modify reports success" \
  || fail "L4 a lid-addressed modify did not report success"
AFTER=$(levels_of group_edition)
echo "$AFTER" | grep -q '^level_admin$' \
  && pass "L4b the save reached the database (level_admin granted by lid)" \
  || fail "L4b the save did NOT reach the database — resolution dropped the list"

# put it back and confirm the revert also went through the lid path
GP2=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_groups?group_lid=group_edition&lang=en-US" -o "$GP2"
curl -s -b "$AJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=group_edition" --data-urlencode modify_group_lid=group_edition \
  --data-urlencode "modify_group_levels[]=level_public" --data-urlencode "modify_group_levels[]=level_edition" \
  --data-urlencode "csrf_token=$(tok $GP2)" "$BASE/admin/admin_groups?lang=en-US" -o /dev/null
REVERTED=$(levels_of group_edition)
[ "$REVERTED" = "$BEFORE" ] \
  && pass "L4c the level set is back to what it was (suite is self-cleaning)" \
  || fail "L4c the level set was left changed: '$REVERTED' (was '$BEFORE')"

# --- L5: a lid that does not resolve is refused and changes nothing ---
GP3=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_groups?group_lid=group_edition&lang=en-US" -o "$GP3"
RESP2=$(curl -s -b "$AJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=no_such_group_xyz" --data-urlencode modify_group_lid=group_edition \
  --data-urlencode "modify_group_levels[]=level_public" --data-urlencode "csrf_token=$(tok $GP3)" \
  "$BASE/admin/admin_groups?lang=en-US")
echo "$RESP2" | grep -qiE 'has been modified' \
  && fail "L5 an unresolvable lid reported a successful modify" \
  || pass "L5 an unresolvable lid does not report success"
[ "$(levels_of group_edition)" = "$BEFORE" ] \
  && pass "L5b an unresolvable lid changed nothing" \
  || fail "L5b an unresolvable lid changed the stored level set"

echo "== admin_users =="
UP=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_users?lang=en-US" -o "$UP"

grep -q "admin_users?user_lid=$ADMIN_EMAIL" "$UP" \
  && pass "L6 the users list links by lid" \
  || fail "L6 the users list does not link by lid"
grep -qE 'admin_users\?user_nid=' "$UP" \
  && fail "L6b a ?user_nid= link survives on the users list" \
  || pass "L6b no ?user_nid= link survives on the users list"
grep -q '<option label="Administrators" value="group_admin"' "$UP" \
  && pass "L7 the groups picker is keyed by lid" \
  || fail "L7 the groups picker is not keyed by lid"

UE=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_users?user_lid=$ADMIN_EMAIL&lang=en-US" -o "$UE"
grep -q "name=\"user_lid\" value=\"$ADMIN_EMAIL\"" "$UE" \
  && pass "L8 ?user_lid= reaches the modify form, addressed by that lid" \
  || fail "L8 ?user_lid= did not reach the modify form"

# L9: a lid-addressed user save round-trips. The surname is the safe field to move — the email
# is the lid and update() refuses slug changes, and the group set is what the lockout guards
# watch, so neither is a field a round-trip probe should touch.
NAME_BEFORE=$(sql "SELECT lastname FROM luna_users u JOIN luna_nodes n ON u.nid = n.nid WHERE n.lid = '$ADMIN_EMAIL';")
curl -s -b "$AJ" --data-urlencode mode=modify --data-urlencode submit=Modify \
  --data-urlencode "user_lid=$ADMIN_EMAIL" --data-urlencode "modify_item_lid=$ADMIN_EMAIL" \
  --data-urlencode "modify_user_email=$ADMIN_EMAIL" --data-urlencode "modify_user_firstname=Admin" \
  --data-urlencode "modify_user_lastname=LidProbe" --data-urlencode "modify_user_groups[]=group_admin" \
  --data-urlencode "modify_user_groups[]=group_default" --data-urlencode "csrf_token=$(tok $UE)" \
  "$BASE/admin/admin_users?lang=en-US" -o /dev/null
[ "$(sql "SELECT lastname FROM luna_users u JOIN luna_nodes n ON u.nid = n.nid WHERE n.lid = '$ADMIN_EMAIL';")" = "LidProbe" ] \
  && pass "L9 a lid-addressed user save reached the database" \
  || fail "L9 the user save did NOT reach the database — resolution dropped the user"
# put the name back through the same path
UE2=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_users?user_lid=$ADMIN_EMAIL&lang=en-US" -o "$UE2"
curl -s -b "$AJ" --data-urlencode mode=modify --data-urlencode submit=Modify \
  --data-urlencode "user_lid=$ADMIN_EMAIL" --data-urlencode "modify_item_lid=$ADMIN_EMAIL" \
  --data-urlencode "modify_user_email=$ADMIN_EMAIL" --data-urlencode "modify_user_firstname=Admin" \
  --data-urlencode "modify_user_lastname=$NAME_BEFORE" --data-urlencode "modify_user_groups[]=group_admin" \
  --data-urlencode "modify_user_groups[]=group_default" --data-urlencode "csrf_token=$(tok $UE2)" \
  "$BASE/admin/admin_users?lang=en-US" -o /dev/null
[ "$(sql "SELECT lastname FROM luna_users u JOIN luna_nodes n ON u.nid = n.nid WHERE n.lid = '$ADMIN_EMAIL';")" = "$NAME_BEFORE" ] \
  && pass "L9b the surname is back to what it was (suite is self-cleaning)" \
  || fail "L9b the surname was left as the probe value"

echo "== admin_levels =="
VP=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_levels?lang=en-US" -o "$VP"

grep -q 'admin_levels?level_lid=level_admin' "$VP" \
  && pass "L10 the levels list links by lid" \
  || fail "L10 the levels list does not link by lid"
grep -qE 'admin_levels\?level_nid=' "$VP" \
  && fail "L10b a ?level_nid= link survives on the levels list" \
  || pass "L10b no ?level_nid= link survives on the levels list"
grep -q '<option label="Administrators" value="group_admin"' "$VP" \
  && pass "L11 the groups picker is keyed by lid" \
  || fail "L11 the groups picker is not keyed by lid"

VE=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_levels?level_lid=level_edition&lang=en-US" -o "$VE"
grep -q 'name="modify_item_lid" value="level_edition"' "$VE" \
  && pass "L12 ?level_lid= reaches the modify form, addressed by that lid" \
  || fail "L12 ?level_lid= did not reach the modify form"

# L13: a lid-addressed level save round-trips. level_edition is the safe target — level_admin and
# level_public are protected lids and check_if_lid_is_protected() refuses them by design.
GROUPS_BEFORE=$(sql "SELECT n2.lid FROM luna_nodes_map m
  JOIN luna_nodes n1 ON m.nid1 = n1.nid JOIN luna_nodes n2 ON m.nid2 = n2.nid
  WHERE n1.lid = 'level_edition' AND n2.tid = (SELECT id FROM luna_types WHERE lid = 'group') ORDER BY n2.lid;")
curl -s -b "$AJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=level_edition" --data-urlencode modify_level_lid=level_edition \
  --data-urlencode "modify_level_groups[]=group_admin" --data-urlencode "modify_level_groups[]=group_edition" \
  --data-urlencode "modify_level_groups[]=group_default" --data-urlencode "csrf_token=$(tok $VE)" \
  "$BASE/admin/admin_levels?lang=en-US" -o /dev/null
sql "SELECT n2.lid FROM luna_nodes_map m
  JOIN luna_nodes n1 ON m.nid1 = n1.nid JOIN luna_nodes n2 ON m.nid2 = n2.nid
  WHERE n1.lid = 'level_edition' AND n2.tid = (SELECT id FROM luna_types WHERE lid = 'group');" | grep -q '^group_default$' \
  && pass "L13 a lid-addressed level save reached the database" \
  || fail "L13 the level save did NOT reach the database — resolution dropped the group list"
# put it back through the same path
VE2=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_levels?level_lid=level_edition&lang=en-US" -o "$VE2"
RESTORE=""
for g in $GROUPS_BEFORE; do RESTORE="$RESTORE --data-urlencode modify_level_groups[]=$g"; done
# shellcheck disable=SC2086
curl -s -b "$AJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=level_edition" --data-urlencode modify_level_lid=level_edition \
  $RESTORE --data-urlencode "csrf_token=$(tok $VE2)" "$BASE/admin/admin_levels?lang=en-US" -o /dev/null
GROUPS_AFTER=$(sql "SELECT n2.lid FROM luna_nodes_map m
  JOIN luna_nodes n1 ON m.nid1 = n1.nid JOIN luna_nodes n2 ON m.nid2 = n2.nid
  WHERE n1.lid = 'level_edition' AND n2.tid = (SELECT id FROM luna_types WHERE lid = 'group') ORDER BY n2.lid;")
[ "$GROUPS_AFTER" = "$GROUPS_BEFORE" ] \
  && pass "L13b the group set is back to what it was (suite is self-cleaning)" \
  || fail "L13b the group set was left changed: '$GROUPS_AFTER' (was '$GROUPS_BEFORE')"

rm -f "$AJ" "$AP" "$LP" "$GP" "$GA" "$GP2" "$GP3" "$UP" "$UE" "$UE2" "$VP" "$VE" "$VE2"
echo
if [ "$fails" -eq 0 ]; then echo "LID ADDRESSING HOLDS"; exit 0; else echo "$fails CHECK(S) FAILED"; exit 1; fi
