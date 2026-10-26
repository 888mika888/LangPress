#!/usr/bin/env bash
# Build the installable plugin ZIP from git-tracked content.
#
#   bash bin/build-zip.sh                 # from HEAD (use this for releases)
#   bash bin/build-zip.sh <commit|tag>    # from a specific revision
#   bash bin/build-zip.sh --worktree      # from the current working tree (tracked
#                                         # + new non-ignored files), for testing
#
# Output: langpress.zip (or $OUT) with a single langpress/ root folder,
# forward-slash paths, and LF line endings, regardless of the OS it runs on.
# Earlier ZIPs were made with PowerShell Compress-Archive, which wrote "\"
# separators that Linux unzip/WordPress treat as part of the file name.
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"
ref="${1:-HEAD}"
out="${OUT:-langpress.zip}"

if [ "$ref" = "--worktree" ]; then
	# Stage the working tree into a throwaway index; the real index is untouched.
	tmp_index="$(mktemp -u)"
	trap 'rm -f "$tmp_index"' EXIT
	GIT_INDEX_FILE="$tmp_index" git add -A -- langpress
	ref="$(GIT_INDEX_FILE="$tmp_index" git write-tree)"
fi

rm -f "$out"
# core.autocrlf=false: never let a Windows checkout setting put CRLF into the package.
git -c core.autocrlf=false archive --format=zip --prefix=langpress/ -o "$out" "$ref:langpress"
echo "Built $out from $ref"
bash "$(dirname "$0")/check-package.sh" "$out" "$ref"
