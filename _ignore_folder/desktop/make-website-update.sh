#!/bin/bash
# Makes dist/website-update.zip: every website file changed since the last
# git commit (new or edited), in its correct folder — upload it to the
# folder that contains "app" and "public" on the server and Extract.
# Never includes server settings (database/mail), local test data, or the
# developer/reset tools.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
OUT="$ROOT/dist/website-update.zip"
LIST="$ROOT/dist/website-update-files.txt"
mkdir -p dist
rm -f "$OUT"
{ git ls-files -m -- app public index.php; git ls-files -o --exclude-standard -- app public index.php; } \
  | sort -u \
  | grep -vE '^app/config/(database\.php|database\.local\.php|mail\.php|setup\.lock)$' \
  | grep -vE '\.sqlite(-wal|-shm)?$|(^|/)error_log$|\.DS_Store$|\.zip$' \
  | grep -vE '^public/(reset|devs)/' \
  | while read -r f; do [ -f "$f" ] && echo "$f"; done > "$LIST"
zip -q "$OUT" -@ < "$LIST"
echo "$(wc -l < "$LIST" | tr -d ' ') files -> dist/website-update.zip  (list: dist/website-update-files.txt)"
