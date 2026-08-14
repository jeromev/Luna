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
# SCREENS COVERED: all six — admin_groups, admin_users, admin_levels, admin_mods, admin_pages,
# edit_texts. The nid-free sweeps (L1b, L6b, L10b, L14b, L18b, L23b) are per-screen rather than
# one sweep over the whole admin, which is deliberate: they were written while screens were still
# being converted one at a time, and a single global sweep could not have passed until the last
# one landed, so it would have asserted nothing for five releases.
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

# L9: a lid-addressed user save round-trips. It runs against a THROWAWAY user, not the seeded
# administrator, and that is a correction rather than a preference: an earlier version modified
# the admin account and posted a hard-coded group list to put it back, which silently dropped
# group_edition — a membership the seed grants and six render baselines display. A restore that
# retypes state instead of reproducing it is not a restore. Create, probe, delete.
UJ=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_users?lang=en-US" -o "$UJ"
curl -s -b "$AJ" --data-urlencode submit=Add --data-urlencode mode=add \
  --data-urlencode add_user_email=lidprobe@test.local --data-urlencode add_user_firstname=Lid \
  --data-urlencode add_user_lastname=Probe --data-urlencode add_user_password=lidprobe-pw \
  --data-urlencode "add_user_groups[]=group_default" --data-urlencode "csrf_token=$(tok $UJ)" \
  "$BASE/admin/admin_users?lang=en-US" -o /dev/null
[ -n "$(sql "SELECT nid FROM luna_nodes WHERE lid='lidprobe@test.local';")" ] \
  && pass "L9 a lid-addressed user was created (the add form resolves group lids)" \
  || fail "L9 could not create the probe user"

UE=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_users?user_lid=lidprobe@test.local&lang=en-US" -o "$UE"
grep -q 'name="user_lid" value="lidprobe@test.local"' "$UE" \
  && pass "L9b ?user_lid= reaches that user's modify form" \
  || fail "L9b ?user_lid= did not reach the probe user's form"
curl -s -b "$AJ" --data-urlencode mode=modify --data-urlencode submit=Modify \
  --data-urlencode "user_lid=lidprobe@test.local" --data-urlencode "modify_item_lid=lidprobe@test.local" \
  --data-urlencode "modify_user_email=lidprobe@test.local" --data-urlencode "modify_user_firstname=Lid" \
  --data-urlencode "modify_user_lastname=Renamed" --data-urlencode "modify_user_groups[]=group_default" \
  --data-urlencode "csrf_token=$(tok $UE)" "$BASE/admin/admin_users?lang=en-US" -o /dev/null
[ "$(sql "SELECT lastname FROM luna_users u JOIN luna_nodes n ON u.nid = n.nid WHERE n.lid='lidprobe@test.local';")" = "Renamed" ] \
  && pass "L9c the save reached the database" \
  || fail "L9c the user save did NOT reach the database"

UE2=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_users?user_lid=lidprobe@test.local&lang=en-US" -o "$UE2"
curl -s -b "$AJ" --data-urlencode mode=modify --data-urlencode submit=Delete \
  --data-urlencode "user_lid=lidprobe@test.local" --data-urlencode "modify_item_lid=lidprobe@test.local" \
  --data-urlencode "modify_user_email=lidprobe@test.local" --data-urlencode "csrf_token=$(tok $UE2)" \
  "$BASE/admin/admin_users?lang=en-US" -o /dev/null
[ -z "$(sql "SELECT nid FROM luna_nodes WHERE lid='lidprobe@test.local';")" ] \
  && pass "L9d the probe user is gone (suite leaves no trace)" \
  || fail "L9d the probe user survived — the suite is not self-cleaning"

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

echo "== admin_mods =="
MP=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_mods?lang=en-US" -o "$MP"

grep -q 'admin_mods?mod_lid=mod_admin' "$MP" \
  && pass "L14 the modules list links by lid" \
  || fail "L14 the modules list does not link by lid"
grep -qE 'admin_mods\?mod_nid=' "$MP" \
  && fail "L14b a ?mod_nid= link survives on the modules list" \
  || pass "L14b no ?mod_nid= link survives on the modules list"
grep -q '<option label="Public level" value="level_public"' "$MP" \
  && pass "L15 the level picker is keyed by lid" \
  || fail "L15 the level picker is not keyed by lid"
grep -q '<option label="Home" value="root"' "$MP" \
  && pass "L15b the pages picker is keyed by lid" \
  || fail "L15b the pages picker is not keyed by lid"

ME=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_mods?mod_lid=mod_online_users&lang=en-US" -o "$ME"
grep -q 'name="modify_item_lid" value="mod_online_users"' "$ME" \
  && pass "L16 ?mod_lid= reaches the modify form, addressed by that lid" \
  || fail "L16 ?mod_lid= did not reach the modify form"

# L17 is the strongest probe in this file, and it is strong because of how submit_modify() works:
# it unlinks every page and level from the module and then re-links whatever the form resolved. So
# posting the module's EXISTING set and finding it intact afterwards is not a tautology — if
# resolution dropped the list, the unlink would still have run and the module would come back
# linked to nothing at all. mod_online_users is the safe target: it is not in the protected-lid
# list, unlike the seven modules that power the admin pages.
pages_of_mod(){ sql "SELECT n2.lid FROM luna_nodes_map m
  JOIN luna_nodes n1 ON m.nid1 = n1.nid JOIN luna_nodes n2 ON m.nid2 = n2.nid
  WHERE n1.lid = '$1' AND n2.tid = (SELECT id FROM luna_types WHERE lid = 'page') ORDER BY n2.lid;"; }
MOD_PAGES_BEFORE=$(pages_of_mod mod_online_users)
curl -s -b "$AJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=mod_online_users" --data-urlencode modify_mod_lid=mod_online_users \
  --data-urlencode "modify_mod_level=level_admin" --data-urlencode "modify_mod_pages[]=admin" \
  --data-urlencode "csrf_token=$(tok $ME)" "$BASE/admin/admin_mods?lang=en-US" -o /dev/null
MOD_PAGES_AFTER=$(pages_of_mod mod_online_users)
[ "$MOD_PAGES_AFTER" = "$MOD_PAGES_BEFORE" ] && [ -n "$MOD_PAGES_AFTER" ] \
  && pass "L17 a lid-addressed module save re-linked its pages (unlink+link round-tripped)" \
  || fail "L17 the module lost its page links: '$MOD_PAGES_AFTER' (was '$MOD_PAGES_BEFORE')"
[ "$(sql "SELECT n2.lid FROM luna_nodes_map m
  JOIN luna_nodes n1 ON m.nid1 = n1.nid JOIN luna_nodes n2 ON m.nid2 = n2.nid
  WHERE n1.lid = 'mod_online_users' AND n2.tid = (SELECT id FROM luna_types WHERE lid = 'level');")" = "level_admin" ] \
  && pass "L17b the level picker's lid resolved and re-linked" \
  || fail "L17b the module lost its level link"

echo "== admin_pages =="
PP=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_pages?lang=en-US" -o "$PP"

grep -q 'admin_pages?page_lid=edition' "$PP" \
  && pass "L18 the pages list links by lid" \
  || fail "L18 the pages list does not link by lid"
grep -qE 'admin_pages\?page_nid=' "$PP" \
  && fail "L18b a ?page_nid= link survives on the pages list" \
  || pass "L18b no ?page_nid= link survives on the pages list"
grep -q 'name="add_parent_lid"' "$PP" \
  && pass "L19 the parent picker posts a lid" \
  || fail "L19 the parent picker still posts a nid"
grep -q '<option label="Home" value="root"' "$PP" \
  && pass "L19b the parent picker is keyed by lid" \
  || fail "L19b the parent picker is not keyed by lid"

PE=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_pages?page_lid=edition&lang=en-US" -o "$PE"
grep -q 'name="modify_item_lid" value="edition"' "$PE" \
  && pass "L20 ?page_lid= reaches the modify form, addressed by that lid" \
  || fail "L20 ?page_lid= did not reach the modify form"

# L21: the page write path. update() takes the parent as its fourth argument and the mods are
# unlinked and re-linked, so posting `edition`'s own parent and level back is a live exercise of
# all three resolvers. The re-parent guards are the reason to be careful what this posts: a page
# may not become its own parent, nor its own ancestor at any depth (would_create_cycle()), and
# those checks compare integers that now arrive from lids.
parent_of(){ sql "SELECT p.lid FROM luna_nodes n JOIN luna_nodes p ON n.parent_nid = p.nid WHERE n.lid = '$1';"; }
level_of_page(){ sql "SELECT n2.lid FROM luna_nodes_map m
  JOIN luna_nodes n1 ON m.nid1 = n1.nid JOIN luna_nodes n2 ON m.nid2 = n2.nid
  WHERE n1.lid = '$1' AND n2.tid = (SELECT id FROM luna_types WHERE lid = 'level');"; }
PAGE_PARENT_BEFORE=$(parent_of edition); PAGE_LEVEL_BEFORE=$(level_of_page edition)
PRESP=$(curl -s -b "$AJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=edition" --data-urlencode modify_page_lid=edition \
  --data-urlencode "modify_parent_lid=root" --data-urlencode "modify_page_level=level_edition" \
  --data-urlencode "csrf_token=$(tok $PE)" "$BASE/admin/admin_pages?lang=en-US")
# The success message is asserted FIRST and separately, and that is the whole point. An earlier
# draft of this check asserted only that the parent and level were unchanged — which is equally
# true when the save is refused outright, and it was: a missed resolution left the level check
# comparing a lid against the node table, so every modify returned false and the "nothing
# changed" assertion passed on a screen whose save never worked. Unchanged state is not evidence
# of a successful write unless you have separately established that a write happened.
echo "$PRESP" | grep -qiE 'has been modified' \
  && pass "L21 a lid-addressed page save reports success" \
  || fail "L21 the page save was refused — the form did not go through at all"
[ "$(parent_of edition)" = "$PAGE_PARENT_BEFORE" ] && [ -n "$(parent_of edition)" ] \
  && pass "L21a the page kept its parent through that save" \
  || fail "L21a the page lost its parent: '$(parent_of edition)' (was '$PAGE_PARENT_BEFORE')"
[ "$(level_of_page edition)" = "$PAGE_LEVEL_BEFORE" ] && [ -n "$(level_of_page edition)" ] \
  && pass "L21b the level picker's lid resolved and re-linked" \
  || fail "L21b the page lost its level link"

# L22: the cycle guard still fires. edition's child is edit_texts, so re-parenting edition under
# edit_texts would make a page its own descendant, which only would_create_cycle() catches — the
# self-parent test above it compares parent == item and is false here. Both hierarchy checks
# share one message ("The hierarchy is incorrect."), so this asserts that a hierarchy guard
# refused it and that only the cycle branch could have; if the lids failed to resolve,
# would_create_cycle(0, 0) is false and the refusal would come from somewhere else entirely.
PE2=$(mktemp); curl -s -b "$AJ" "$BASE/admin/admin_pages?page_lid=edition&lang=en-US" -o "$PE2"
CYC=$(curl -s -b "$AJ" --data-urlencode submit=Modify --data-urlencode mode=modify \
  --data-urlencode "modify_item_lid=edition" --data-urlencode modify_page_lid=edition \
  --data-urlencode "modify_parent_lid=edit_texts" --data-urlencode "modify_page_level=level_edition" \
  --data-urlencode "csrf_token=$(tok $PE2)" "$BASE/admin/admin_pages?lang=en-US")
echo "$CYC" | grep -qiE 'hierarchy is incorrect' \
  && pass "L22 the cycle guard refuses a lid-addressed re-parent, by its own message" \
  || fail "L22 the cycle guard did not fire on a lid-addressed re-parent"
[ "$(parent_of edition)" = "$PAGE_PARENT_BEFORE" ] \
  && pass "L22b the tree is intact after the refused re-parent" \
  || fail "L22b the re-parent went through: edition is now under '$(parent_of edition)'"

echo "== edit_texts =="
TP=$(mktemp); curl -s -b "$AJ" "$BASE/edition/edit_texts?lang=en-US" -o "$TP"

grep -q 'edit_texts?text_lid=welcome' "$TP" \
  && pass "L23 the texts list links by lid" \
  || fail "L23 the texts list does not link by lid"
grep -qE 'edit_texts\?text_nid=' "$TP" \
  && fail "L23b a ?text_nid= link survives on the texts list" \
  || pass "L23b no ?text_nid= link survives on the texts list"
grep -q '<option label="Home" value="root"' "$TP" \
  && pass "L24 the pages picker is keyed by lid" \
  || fail "L24 the pages picker is not keyed by lid"

TE=$(mktemp); curl -s -b "$AJ" "$BASE/edition/edit_texts?text_lid=welcome&lang=en-US" -o "$TE"
grep -q 'name="modify_item_lid" value="welcome"' "$TE" \
  && pass "L25 ?text_lid= reaches the modify form, addressed by that lid" \
  || fail "L25 ?text_lid= did not reach the modify form"

# L26: the text write path, against a THROWAWAY text for the same reason L9 uses a throwaway
# user. An earlier version probed the seeded `welcome` text and restored its body from a value
# read back through `mysql -N -e`, which flattens newlines — so the multi-line Markdown came
# back as a single truncated line and six baselines moved, including the home page. Content is
# the worst possible thing to round-trip through a shell variable; a fixture avoids the question.
TJ=$(mktemp); curl -s -b "$AJ" "$BASE/edition/edit_texts?lang=en-US" -o "$TJ"
curl -s -b "$AJ" --data-urlencode submit=Add --data-urlencode mode=add \
  --data-urlencode add_text_lid=lidprobe_text --data-urlencode add_text_title=LidProbe \
  --data-urlencode add_text_lang=en-US --data-urlencode add_text_content='probe body' \
  --data-urlencode "add_text_pages[]=root" --data-urlencode "csrf_token=$(tok $TJ)" \
  "$BASE/edition/edit_texts?lang=en-US" -o /dev/null
[ -n "$(sql "SELECT nid FROM luna_nodes WHERE lid='lidprobe_text';")" ] \
  && pass "L26 a lid-addressed text was created (the add form resolves page lids)" \
  || fail "L26 could not create the probe text"
[ -n "$(sql "SELECT n2.lid FROM luna_nodes_map m JOIN luna_nodes n1 ON m.nid1 = n1.nid
  JOIN luna_nodes n2 ON m.nid2 = n2.nid WHERE n1.lid = 'lidprobe_text' AND n2.lid = 'root';")" ] \
  && pass "L26b the pages picker's lid resolved and linked" \
  || fail "L26b the text was not linked to the page its lid named"

TE=$(mktemp); curl -s -b "$AJ" "$BASE/edition/edit_texts?text_lid=lidprobe_text&lang=en-US" -o "$TE"
grep -q 'name="modify_item_lid" value="lidprobe_text"' "$TE" \
  && pass "L26c ?text_lid= reaches that text's modify form" \
  || fail "L26c ?text_lid= did not reach the probe text's form"
TRESP=$(curl -s -b "$AJ" --data-urlencode mode=modify --data-urlencode submit=Modify \
  --data-urlencode "modify_item_lid=lidprobe_text" --data-urlencode modify_text_lid=lidprobe_text \
  --data-urlencode "modify_text_title=LidProbeRenamed" --data-urlencode modify_text_lang=en-US \
  --data-urlencode "modify_text_content=probe body 2" --data-urlencode "modify_text_pages[]=root" \
  --data-urlencode "csrf_token=$(tok $TE)" "$BASE/edition/edit_texts?lang=en-US")
echo "$TRESP" | grep -qiE 'has been modified' \
  && pass "L26d a lid-addressed text save reports success" \
  || fail "L26d the text save was refused"
[ "$(sql "SELECT title FROM luna_texts t JOIN luna_nodes n ON t.nid = n.nid WHERE n.lid='lidprobe_text' AND t.lang='en';")" = "LidProbeRenamed" ] \
  && pass "L26e the save reached the database" \
  || fail "L26e the text save did NOT reach the database"

TE2=$(mktemp); curl -s -b "$AJ" "$BASE/edition/edit_texts?text_lid=lidprobe_text&lang=en-US" -o "$TE2"
curl -s -b "$AJ" --data-urlencode mode=modify --data-urlencode submit=Delete \
  --data-urlencode "modify_item_lid=lidprobe_text" --data-urlencode modify_text_lid=lidprobe_text \
  --data-urlencode "csrf_token=$(tok $TE2)" "$BASE/edition/edit_texts?lang=en-US" -o /dev/null
[ -z "$(sql "SELECT nid FROM luna_nodes WHERE lid='lidprobe_text';")" ] \
  && pass "L26f the probe text is gone (suite leaves no trace)" \
  || fail "L26f the probe text survived — the suite is not self-cleaning"

echo "== the whole admin =="
# The capstone, and it could not have been written until now: one sweep across every admin
# surface for ANY remaining ?{something}_nid= parameter. While the screens were being converted
# one at a time this check would have failed on the unconverted ones, which is why the per-screen
# sweeps above exist and why this one is not a duplicate of them — they hold each screen at the
# release that converted it, this holds the whole set from here on.
NIDHITS=0; NIDWHERE=""
for u in "/admin" "/admin/admin_users" "/admin/admin_groups" "/admin/admin_levels" \
         "/admin/admin_pages" "/admin/admin_mods" "/edition/edit_texts" "/admin/journal" \
         "/admin/admin_groups?group_lid=group_admin" "/admin/admin_users?user_lid=$ADMIN_EMAIL" \
         "/admin/admin_levels?level_lid=level_edition" "/admin/admin_mods?mod_lid=mod_online_users" \
         "/admin/admin_pages?page_lid=edition" "/edition/edit_texts?text_lid=welcome"; do
  sep="?"; case "$u" in *\?*) sep="&";; esac
  n=$(curl -s -b "$AJ" "$BASE$u${sep}lang=en-US" | grep -coE '[a-z_]+_nid=' || true)
  if [ "$n" -gt 0 ]; then NIDHITS=$((NIDHITS + n)); NIDWHERE="$NIDWHERE $u($n)"; fi
done
[ "$NIDHITS" -eq 0 ] \
  && pass "L27 no admin surface emits a ?*_nid= parameter anywhere" \
  || fail "L27 $NIDHITS nid parameter(s) still rendered:$NIDWHERE"

rm -f "$AJ" "$AP" "$LP" "$GP" "$GA" "$GP2" "$GP3" "$UP" "$UJ" "$UE" "$UE2" "$VP" "$VE" "$VE2" "$MP" "$ME" "$PP" "$PE" "$PE2" "$TP" "$TJ" "$TE" "$TE2"
echo
if [ "$fails" -eq 0 ]; then echo "LID ADDRESSING HOLDS"; exit 0; else echo "$fails CHECK(S) FAILED"; exit 1; fi
