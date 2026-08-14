#!/usr/bin/env bash
#
# Render-diff harness for the legacy-model retirement.
#
# Snapshots the rendered HTML (and the RDF output) of every page type, normalised for volatile
# bits (CSRF tokens, timings, dates), so a change to the in-memory model or the XSLT can be
# proven output-neutral — or its diff inspected deliberately. The HTML is deterministic, so a
# byte-identical normalised render means the change preserved behaviour.
#
#   test/render_diff.sh capture   # save baselines into test/render-baseline/
#   test/render_diff.sh check     # re-render and diff against the baselines
#
# The baselines are committed, so CI compares against a fixed reference rather than against a
# capture from its own run (which would only prove the render is stable within one boot, not
# that a change is output-neutral). That works because the normalisation below also covers the
# bits that vary by environment, and because the render is independent of the read path — the
# SQL path CI uses (SPARQL_READS=0) and the triplestore path dev uses render byte-identically.
# Re-capture with `make render-capture` whenever a diff is inspected and accepted.
#
# BASELINE_DIR points the capture somewhere else, which is how CI saves what it actually
# rendered when a check fails, for inspection without reproducing the stack locally.
set -u
BASE="${BASE:-http://localhost:8080}"
DB="${DB_CONTAINER:-luna-db-1}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@luna.local}"
ADMIN_PASS="${ADMIN_PASS:-luna}"
DIR="${BASELINE_DIR:-test/render-baseline}"
MODE="${1:-check}"

sql(){ docker exec "$DB" mysql -uroot -proot lunadb -N -e "$1" 2>/dev/null; }
tok(){ grep -oE 'csrf_token"[^>]*value="[^"]*"' "$1" | grep -oE 'value="[^"]*"' | head -1 | sed 's/value="//;s/"//'; }

# Strip the bits that legitimately change between two identical requests — CSRF synchroniser
# tokens, render timings, wall-clock dates/times — and the bits that change between two
# environments, so the baseline can be committed and compared in CI rather than only against
# itself on one machine: the client IP (the online-users table renders REMOTE_ADDR, which is the
# Docker bridge gateway and shifts whenever the network is recreated — 172.18.0.1 here,
# 172.19.0.1 after a `down -v`, something else again on a CI runner).
norm(){ sed -E \
  -e 's/(csrf_token[^>]*value=")[^"]*"/\1CSRF"/g' \
  -e 's/[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}/IP/g' \
  -e 's|<td>[0-9a-fA-F]*::[0-9a-fA-F:]*</td>|<td>IP</td>|g' \
  -e 's/(value=")[0-9a-f]{32,}"/\1HASH"/g' \
  -e 's/[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}(:[0-9]{2})?/DATE/g' \
  -e 's/[0-9]+\.[0-9]+ ?(s|ms|sec|seconds)/TIME/g' \
  -e 's/(generated|rendered)[^<]*/\1 TIME/Ig' \
  -e 's/[0-9]+ ?(seconde|minute|heure|jour|semaine|mois|second|minute|hour|day|week|month|year|an)s?/AGO/Ig' \
  -e 's/(log_id=|[Mm]essage |id="|aria-label="[Mm]essage )[0-9]+/\1ID/g' \
  -e 's/(PHPSESSID=|sessionid=|session=)[A-Za-z0-9]+/\1SESS/Ig' \
  -e 's|<td class="nowrap">[^<]*</td>|<td class="nowrap">SEEN</td>|g' \
  -e 's/[0-9]+\.[0-9]+\.[0-9]+-alpha/VER/g' ; }

# id | auth(guest|admin) | url | expected HTTP code (optional, default 200)
#   — covers each html.xsl stylesheet + the RDF output formats
#
# The Markdown render path is covered by home/home_admin, which carry the seeded welcome text.
#
# The `admin_groups_edit` pair renders the MODIFY form rather than the list, and exists because
# the list alone cannot see most of what the lid conversion touches: the hidden item field, the
# XPath keys that select the group being edited, the picker's selected state, and the members
# table are all reachable only with a ?group_lid= in hand. It points at group_edition, which is
# unpopulated in the seed — delegated_admin.sh puts a user in that group and takes it out again,
# so a baseline pinned to its membership would be ordering-dependent where this one is not.
#
# sitemap/robots/data_root cover the publishing surface — the crawler-discovery endpoints and
# the /data/{slug} RDF document. They were added when the emitters moved out of the model: all
# three emitted their body and exited from inside the data layer, and nothing here rendered
# them, so a refactor of that code had no gate. /id/{slug} is deliberately absent: it 303s with
# no body by design (httpRange-14), and this harness does not follow redirects, so it would trip
# the zero-byte guard below rather than assert anything.
#
# EVERY case states its language in its own URL, and that is not decoration. The interface
# language is resolved by lunaTools::request('lang'), which falls through $_GET → $_POST →
# $_SESSION → $_COOKIE, and set_language() writes it back to $_SESSION — so language is sticky
# per session, not per request. The admin cases share one cookie jar, so a single case carrying
# ?lang=fr-FR would leave every later admin case rendering French against an English baseline.
# Spelling the language into each URL makes a case mean the same thing wherever it sits in this
# list, and it is also what keeps the DEGRADED cross-check below pairing like with like, since
# that pairs an admin case to a guest case by identical URL.
#
# The _fr cases exist because the English ones are structurally unable to see a whole class of
# defect. Two worked examples, both real and both fixed in 0.9.5-alpha: the article language
# filter emptied <main> for any visitor whose language was not the text's, which no English case
# can reach; and the vocabulary bnode collision rendered the wrong case-variant of a label, which
# is invisible in en_US precisely because en_US maps 'user' and 'User' to the same string, while
# fr_FR maps them to 'usager' and 'Usager'. A French case is the only instrument that separates
# them. The RDF/data endpoints (out_*, sitemap, robots, data_root) carry no interface
# vocabulary, so they are not duplicated.
#
# `journal` (the log LIST, as distinct from journal_analyse) sits early on purpose: it renders
# every log row, so it is only deterministic before a case that writes one. A 200 render logs
# nothing; the 404 in `notfound` logs a row. Keep both journal cases above `notfound`, or their
# baselines will shift under any change to the case list.
PAGES="
admin|admin|/admin?lang=en-US
journal|admin|/admin/journal?lang=en-US
journal_fr|admin|/admin/journal?lang=fr-FR
home|guest|/?lang=en-US
login|guest|/login?lang=en-US
notfound|guest|/no-such-page-xyz?lang=en-US|404
out_xml|guest|/?output=xml&lang=en-US
out_n3|guest|/?output=n3&lang=en-US
out_json|guest|/?output=json&lang=en-US
out_jsonld|guest|/?output=jsonld&lang=en-US
sitemap|guest|/sitemap.xml?lang=en-US
robots|guest|/robots.txt?lang=en-US
data_root|guest|/data/root?lang=en-US
admin_users|admin|/admin/admin_users?lang=en-US
admin_users_edit|admin|/admin/admin_users?user_lid=admin@luna.local&lang=en-US
admin_groups|admin|/admin/admin_groups?lang=en-US
admin_groups_edit|admin|/admin/admin_groups?group_lid=group_edition&lang=en-US
admin_levels|admin|/admin/admin_levels?lang=en-US
admin_levels_edit|admin|/admin/admin_levels?level_lid=level_edition&lang=en-US
admin_pages|admin|/admin/admin_pages?lang=en-US
admin_mods|admin|/admin/admin_mods?lang=en-US
journal_analyse|admin|/admin/journal?log_id=999&lang=en-US
edit_texts|admin|/edition/edit_texts?lang=en-US
home_admin|admin|/?lang=en-US
home_fr|guest|/?lang=fr-FR
login_fr|guest|/login?lang=fr-FR
admin_users_fr|admin|/admin/admin_users?lang=fr-FR
admin_users_edit_fr|admin|/admin/admin_users?user_lid=admin@luna.local&lang=fr-FR
admin_groups_fr|admin|/admin/admin_groups?lang=fr-FR
admin_groups_edit_fr|admin|/admin/admin_groups?group_lid=group_edition&lang=fr-FR
admin_levels_fr|admin|/admin/admin_levels?lang=fr-FR
admin_levels_edit_fr|admin|/admin/admin_levels?level_lid=level_edition&lang=fr-FR
admin_pages_fr|admin|/admin/admin_pages?lang=fr-FR
admin_mods_fr|admin|/admin/admin_mods?lang=fr-FR
journal_analyse_fr|admin|/admin/journal?log_id=999&lang=fr-FR
edit_texts_fr|admin|/edition/edit_texts?lang=fr-FR
home_admin_fr|admin|/?lang=fr-FR
admin_fr|admin|/admin?lang=fr-FR
"

# Reset volatile server state so the render is deterministic across runs (dev harness):
# the audit log grows per request, and the online-users widget reflects live sessions.
sql "DELETE FROM luna_login_throttle; DELETE FROM luna_logs; DELETE FROM luna_sessions;"
# Seed one fixed log row so the journal "analyse" view (a name()/local-name()-driven
# admin tool outside the public page set) has a deterministic target — this guards its
# per-field i18n label lookup. All volatile bits (date, log_id) are normalised below.
sql "INSERT INTO luna_logs (id,logtime,ident,priority,message) VALUES (999,'2020-01-01 00:00:00','test',6,'{\"message\":\"probe\"}')"
# fresh admin session
AJ=$(mktemp); AP=$(mktemp); curl -s -c "$AJ" "$BASE/login" -o "$AP"
curl -s -b "$AJ" -c "$AJ" --data-urlencode submit=login --data-urlencode "email=$ADMIN_EMAIL" \
  --data-urlencode "password=$ADMIN_PASS" --data-urlencode "csrf_token=$(tok $AP)" "$BASE/login" -o /dev/null

# Prove the session really is an admin session before recording anything. Nothing downstream
# notices a login that didn't take: the admin URLs answer 404 with a one-line body, `/` answers
# the guest home, and every one of those is a plausible-looking capture. A wrong password, a
# tripped login throttle, or a CSRF token the tok() grep failed to find all land here, and the
# run would otherwise write nine degraded pages and report success.
probe=$(curl -s -b "$AJ" -w '\n%{http_code}' "$BASE/admin")
probe_code=$(printf '%s' "$probe" | tail -1)
if [ "$probe_code" != 200 ] || ! printf '%s' "$probe" | grep -q 'logout'; then
  printf '\033[31mADMIN LOGIN FAILED\033[0m — GET /admin returned HTTP %s with no authenticated marker.\n' "$probe_code"
  printf 'Nothing was written. Check ADMIN_EMAIL / ADMIN_PASS, and whether the login throttle is tripped.\n'
  rm -f "$AJ" "$AP"; exit 1
fi

# Renders go to a scratch dir first. A capture is committed to $DIR only if the whole run is
# valid — the fault this guards against produced a baseline in which a third of the pages were
# 404 bodies, written case by case as each one "succeeded".
RUN=$(mktemp -d)
trap 'rm -rf "$RUN"' EXIT
fails=0; diffs=0
printf '%s\n' "--- render-diff: $MODE ---"
while IFS='|' read -r id auth url expect; do
  [ -z "$id" ] && continue
  expect="${expect:-200}"
  if [ "$auth" = admin ]; then code=$(curl -s -b "$AJ" -o /tmp/rd.raw -w '%{http_code}' "$BASE$url")
  else code=$(curl -s -o /tmp/rd.raw -w '%{http_code}' "$BASE$url"); fi
  norm < /tmp/rd.raw > "$RUN/$id.norm"
  size=$(wc -c <"$RUN/$id.norm" | tr -d ' ')
  base="$DIR/$id.html"
  printf '%s|%s\n' "$id" "$url" >> "$RUN/$auth.idx"
  # An error body is a response, not content. Recording one as a baseline freezes the failure
  # and every later run then agrees with it. `notfound` is the one case that expects non-200.
  if [ "$code" != "$expect" ]; then
    printf '  \033[31mSTATUS\033[0m  %-14s HTTP %s (expected %s) — %s\n' "$id" "$code" "$expect" "$url"
    fails=$((fails+1)); continue
  fi
  # A zero-byte body is not evidence: it compares equal to every other empty render, so such a
  # case can never fail and its "SAME" line asserts nothing. Refuse it on both sides — capture
  # must not record one, and check must not trust one. The app returns 200 with an empty body
  # whenever a module die()s after its access/argument checks, which is how two such baselines
  # were once recorded, and then passed, unnoticed.
  if [ "$size" -eq 0 ]; then
    printf '  \033[31mEMPTY\033[0m   %-14s HTTP %s  0 bytes — %s renders nothing\n' "$id" "$code" "$url"
    fails=$((fails+1)); continue
  fi
  if [ "$MODE" = capture ]; then
    printf '  ok      %-14s HTTP %s  %5s bytes\n' "$id" "$code" "$size"
  else
    if [ ! -f "$base" ]; then printf '  \033[33mNOBASE\033[0m  %-14s (run capture first)\n' "$id"; fails=$((fails+1)); continue; fi
    if [ ! -s "$base" ]; then printf '  \033[31mEMPTY\033[0m   %-14s (baseline is 0 bytes — re-capture)\n' "$id"; fails=$((fails+1)); continue; fi
    if diff -q "$base" "$RUN/$id.norm" >/dev/null 2>&1; then
      printf '  \033[32mSAME\033[0m    %-14s HTTP %s\n' "$id" "$code"
    else
      printf '  \033[31mDIFF\033[0m    %-14s HTTP %s\n' "$id" "$code"; diffs=$((diffs+1))
      diff "$base" "$RUN/$id.norm" | head -12 | sed 's/^/        /'
    fi
  fi
done <<< "$PAGES"
rm -f "$AJ" "$AP"

# An admin case that renders exactly what the guest case for the same URL rendered means the
# session degraded to guest — the tell the /admin probe above would miss if the session were
# lost partway through the run (home_admin coming out byte-identical to home is the known shape).
if [ -f "$RUN/admin.idx" ] && [ -f "$RUN/guest.idx" ]; then
  while IFS='|' read -r id url; do
    [ -z "$id" ] && continue
    gid=$(awk -F'|' -v u="$url" '$2==u {print $1; exit}' "$RUN/guest.idx")
    [ -n "$gid" ] || continue
    [ -f "$RUN/$id.norm" ] && [ -f "$RUN/$gid.norm" ] || continue
    if diff -q "$RUN/$id.norm" "$RUN/$gid.norm" >/dev/null 2>&1; then
      printf '  \033[31mDEGRADED\033[0m %-13s renders identically to guest `%s` on %s — session is not admin\n' \
        "$id" "$gid" "$url"
      fails=$((fails+1))
    fi
  done < "$RUN/admin.idx"
fi

echo
if [ "$MODE" = capture ]; then
  # All-or-nothing: a run with any invalid case writes nothing at all, so a baseline can never be
  # a mix of real renders and failures. The old path wrote each case as it went, which is how a
  # capture whose admin login had silently failed still produced a complete-looking baseline.
  if [ "$fails" -ne 0 ]; then
    printf '\033[31mcapture ABORTED — %d invalid case(s); %s left untouched\033[0m\n' "$fails" "$DIR"; exit 1
  fi
  mkdir -p "$DIR"
  # Drop baselines for cases that no longer exist, so the directory is always exactly the case
  # list. Three unreachable files (about, about_admin, journal) survived an earlier repoint of
  # PAGES and sat there looking like coverage.
  for old in "$DIR"/*.html; do
    [ -e "$old" ] || continue
    [ -f "$RUN/$(basename "$old" .html).norm" ] || { rm -f "$old"; printf '  removed %-14s (no longer in the case list)\n' "$(basename "$old" .html)"; }
  done
  for f in "$RUN"/*.norm; do cp "$f" "$DIR/$(basename "$f" .norm).html"; done
  printf '\033[32mbaselines captured in %s (%d pages)\033[0m\n' "$DIR" "$(ls "$RUN"/*.norm | wc -l | tr -d ' ')"; exit 0
fi
if [ "$diffs" -eq 0 ] && [ "$fails" -eq 0 ]; then printf '\033[32mRENDER UNCHANGED — all pages byte-identical\033[0m\n'; exit 0; fi
printf '\033[31m%d page(s) differ, %d missing baseline\033[0m\n' "$diffs" "$fails"; exit 1
