#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUT_DIR="$ROOT_DIR/storage/exports"
PACKAGE="$OUT_DIR/sportlauf-hosting.zip"

mkdir -p "$OUT_DIR"
rm -f "$PACKAGE"

cd "$ROOT_DIR"

zip -r "$PACKAGE" \
  .htaccess \
  index.php \
  composer.json \
  README.md \
  app \
  config/database.example.php \
  config/database.hosting.php \
  database/schema.sql \
  database/seed.sql \
  docs/EXTERNER_HOSTER.md \
  public \
  storage/exports/.gitkeep \
  storage/logs/.gitkeep \
  tests \
  -x '*/.DS_Store' >/dev/null

echo "$PACKAGE"
