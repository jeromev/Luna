#!/bin/sh
#
# Install the house-style pre-commit hook.
#
# Checks the staged PHP against docs/coding-style.md before the commit is written, which is a
# much shorter feedback loop than finding out in CI.
#
# This installer APPENDS a clearly-delimited stanza to .git/hooks/pre-commit and creates the
# file only if it is absent. It never rewrites or removes anything already there — a working
# tree may carry other pre-commit hooks that this repository knows nothing about, and they
# take precedence over a style check. Running it twice is a no-op.
#
# Uninstall: delete the block between the two LUNA_STYLE_HOOK markers.
#
#   sh tools/install-hooks.sh
#
set -eu

root=$(git rev-parse --show-toplevel 2>/dev/null) || { echo "not a git repository"; exit 1; }
hookdir=$(git rev-parse --git-path hooks)
hook="$hookdir/pre-commit"
marker='LUNA_STYLE_HOOK'

mkdir -p "$hookdir"

if [ -f "$hook" ] && grep -q "$marker" "$hook"; then
	echo "pre-commit: style stanza already installed — nothing to do"
	exit 0
fi

if [ ! -f "$hook" ]; then
	printf '#!/bin/sh\n' > "$hook"
	echo "pre-commit: created"
else
	# Refuse rather than append blindly. Appending to a hook written in another language
	# corrupts it, and appending after a hook whose every path exits produces a stanza that
	# silently never runs — which is worse than no hook, because it looks installed.
	first=$(head -n 1 "$hook")
	case "$first" in
		'#!'*sh|'#!'*sh\ *|'#!/usr/bin/env sh'*|'#!/usr/bin/env bash'*|'#!/usr/bin/env dash'*|'#!/usr/bin/env zsh'*) ;;
		'#!'*)
			echo "pre-commit: existing hook is not a shell script ($first)."
			echo "            Refusing to append. Add this to it by hand instead:"
			echo "              make fmt-check"
			exit 1 ;;
	esac
	if grep -qE '^[[:space:]]*(exit|exec)[[:space:]]*[0-9]*[[:space:]]*$|\|\|[[:space:]]*exit|\)[[:space:]]*exit' "$hook"; then
		echo "pre-commit: the existing hook exits on at least one path, so an appended stanza"
		echo "            may never run. Refusing to append silently."
		echo "            Add this line near the TOP of $hook instead:"
		echo "              make fmt-check || exit 1"
		exit 1
	fi
	echo "pre-commit: exists and composes; appending (nothing already there is touched)"
fi
chmod +x "$hook"

cat >> "$hook" <<'STANZA'

# --- BEGIN LUNA_STYLE_HOOK (tools/install-hooks.sh) -----------------------------------------
# Run the style check when the commit touches PHP. The check itself covers the whole tree,
# not only the staged files — php-cs-fixer has no staged-only mode and a partial check would
# be misleading. Skip with `git commit --no-verify`, or LUNA_SKIP_STYLE=1 for a one-off.
# See docs/coding-style.md.
if [ "${LUNA_SKIP_STYLE:-0}" != "1" ]; then
	_luna_staged=$(git diff --cached --name-only --diff-filter=ACM -- '*.php' | grep -vE '^(vendor|luna/luna\.lib|tools/vendor)/' || true)
	if [ -n "$_luna_staged" ]; then
		if [ ! -x tools/vendor/bin/php-cs-fixer ]; then
			echo "style: tools/ not installed — run 'make tools' (or LUNA_SKIP_STYLE=1 to bypass)"
			exit 1
		fi
		if ! make fmt-check >/dev/null 2>&1; then
			echo "style: the tree does not match docs/coding-style.md."
			echo "       run 'make fmt' to fix it, then stage the result."
			exit 1
		fi
	fi
fi
# --- END LUNA_STYLE_HOOK --------------------------------------------------------------------
STANZA

echo "pre-commit: style stanza installed"
