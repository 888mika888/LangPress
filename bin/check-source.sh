#!/usr/bin/env bash
# Source hygiene checks that need nothing but bash and PHP.
#
#   bash bin/check-source.sh        (uses $PHP or `php` from PATH)
#
#  - every plugin PHP file starts exactly with "<?php" (a UTF-8 BOM or stray
#    whitespace is sent before HTTP headers and breaks cookies, redirects and
#    JSON responses)
#  - no BOM in JS/CSS
#  - every PHP file passes `php -l`
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
php="${PHP:-php}"
fail=0

while IFS= read -r -d '' f; do
	if [ "$(head -c 5 "$f")" != "<?php" ]; then
		echo "ERROR: $f does not start with <?php (BOM or leading output)" >&2
		fail=1
	fi
	if ! out="$("$php" -l "$f" 2>&1)"; then
		echo "$out" >&2
		fail=1
	fi
done < <(find langpress tests -name '*.php' -print0)

while IFS= read -r -d '' f; do
	if [ "$(head -c 3 "$f" | od -An -tx1 | tr -d ' \n')" = "efbbbf" ]; then
		echo "ERROR: BOM in $f" >&2
		fail=1
	fi
done < <(find langpress -name '*.js' -print0 -o -name '*.css' -print0)

if [ "$fail" -ne 0 ]; then
	exit 1
fi
echo "OK: source checks passed ($("$php" -r 'echo PHP_VERSION;'))"
