#!/bin/bash
# Refreshes desktop/files/schema.sql (the empty database the shop-computer
# version starts with) from the local development database. Run after adding
# tables/columns, then commit the result.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
{
  echo "-- Empty database for the shop-computer (offline) version. Generated from the"
  echo "-- local development database; rebuild with: bash desktop/make-schema.sh"
  sqlite3 "$ROOT/app/databases/local_pos.sqlite" .schema | grep -v sqlite_sequence
  sqlite3 "$ROOT/app/databases/local_pos.sqlite" ".mode insert roles" "SELECT * FROM roles"
} > "$ROOT/desktop/files/schema.sql"
echo "Updated desktop/files/schema.sql"
