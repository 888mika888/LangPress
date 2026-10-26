#!/usr/bin/env bash
# Validate a plugin ZIP against the source it should contain.
#
#   bash bin/check-package.sh [zip] [tree-ish]     (defaults: langpress.zip HEAD)
#
# Fails if the archive has non-portable paths, a wrong root folder, files that
# differ from <tree-ish>:langpress (stale build), missing or extra files,
# development files, or a PHP file with a BOM.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
zip="${1:-langpress.zip}"
ref="${2:-HEAD}"
fail=0
err() { echo "ERROR: $*" >&2; fail=1; }

[ -f "$zip" ] || { echo "ERROR: $zip not found" >&2; exit 1; }

entries="$(unzip -Z1 "$zip")"

if grep -q '\\' <<<"$entries"; then
	err "backslash path separators (not installable on Linux):"
	grep '\\' <<<"$entries" | head -5 >&2
fi
if grep -vq '^langpress/' <<<"$entries"; then
	err "entries outside the langpress/ root folder:"
	grep -v '^langpress/' <<<"$entries" | head -5 >&2
fi
if grep -Eq '(^|/)\.\.(/|$)|^/' <<<"$entries"; then
	err "absolute or parent-relative paths"
fi
# Hidden directories (.git, editor and tool settings, ...) and development files never ship.
dev_files='(^|/)\.[^/]+/|(^|/)(node_modules|tests|bin)(/|$)|wp-config\.php$|\.env$|\.(zip|log|sqlite)$|\.DS_Store$'
if grep -Eiq "$dev_files" <<<"$entries"; then
	err "development or local files in package:"
	grep -Ei "$dev_files" <<<"$entries" | head -5 >&2
fi
for required in langpress/langpress.php langpress/uninstall.php langpress/includes/class-lp-translator.php; do
	grep -qx "$required" <<<"$entries" || err "missing $required"
done

# Content comparison against the source revision.
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/expected" "$work/actual"
git -c core.autocrlf=false archive --format=tar --prefix=langpress/ "$ref:langpress" | tar -x -C "$work/expected"
unzip -q "$zip" -d "$work/actual"
if ! diff -r "$work/expected" "$work/actual" >"$work/diff.txt" 2>&1; then
	err "package content differs from $ref:langpress (stale or modified build):"
	head -20 "$work/diff.txt" >&2
fi

while IFS= read -r -d '' php; do
	if [ "$(head -c 3 "$php" | od -An -tx1 | tr -d ' \n')" = "efbbbf" ]; then
		err "BOM in ${php#"$work/actual/"}"
	fi
done < <(find "$work/actual" -name '*.php' -print0)

if [ "$fail" -ne 0 ]; then
	exit 1
fi
echo "OK: $zip matches $ref:langpress ($(wc -l <<<"$entries" | tr -d ' ') entries, portable paths)"
