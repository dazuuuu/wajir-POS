#!/bin/bash
# Builds dist/ShopPOS-Windows.zip — the shop-computer (offline) version.
#   bash desktop/build.sh
# Needs: internet (downloads PHP for Windows, the CA list and the Microsoft
# runtime the first time; cached in desktop/.cache), python3, sqlite3, zip.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CACHE="$ROOT/desktop/.cache"
OUT="$ROOT/dist/ShopPOS"
PHP_VER="8.4.26"
PHP_ZIP="php-$PHP_VER-nts-Win32-vs17-x64.zip"
mkdir -p "$CACHE" "$ROOT/dist"
rm -rf "$OUT" "$ROOT/dist/ShopPOS-Windows.zip"
mkdir -p "$OUT"

echo "1/6 Downloading PHP $PHP_VER for Windows (cached)…"
[ -s "$CACHE/$PHP_ZIP" ] || curl -fL -o "$CACHE/$PHP_ZIP" "https://downloads.php.net/~windows/releases/$PHP_ZIP" \
  || curl -fL -o "$CACHE/$PHP_ZIP" "https://downloads.php.net/~windows/releases/archives/$PHP_ZIP"
EXPECT=$(curl -fsL https://downloads.php.net/~windows/releases/releases.json | python3 -c "import json,sys;d=json.load(sys.stdin);print(next((v['nts-vs17-x64']['zip']['sha256'] for v in d.values() if v.get('version')=='$PHP_VER'),''))" || true)
if [ -n "$EXPECT" ]; then
  GOT=$(shasum -a 256 "$CACHE/$PHP_ZIP" | cut -d' ' -f1)
  [ "$GOT" = "$EXPECT" ] || { echo "PHP download checksum mismatch"; exit 1; }
fi
[ -s "$CACHE/cacert.pem" ] || curl -fL -o "$CACHE/cacert.pem" https://curl.se/ca/cacert.pem
[ -s "$CACHE/vc_redist.x64.exe" ] || curl -fL -o "$CACHE/vc_redist.x64.exe" https://aka.ms/vs/17/release/vc_redist.x64.exe

echo "2/6 PHP…"
mkdir -p "$OUT/php"
unzip -q "$CACHE/$PHP_ZIP" -d "$OUT/php"
# Not needed by the POS: language data for the intl extension (~36 MB),
# developer files, debugger, and extensions that aren't switched on.
rm -rf "$OUT/php/dev" "$OUT/php/lib" "$OUT/php/"icu*.dll "$OUT/php/php8embed.lib" "$OUT/php/"phpdbg* "$OUT/php/php.ini-"*
for f in "$OUT/php/ext/"*.dll; do
  case "$(basename "$f")" in
    php_pdo_sqlite.dll|php_sqlite3.dll|php_mbstring.dll|php_gd.dll|php_curl.dll|php_openssl.dll|php_fileinfo.dll) ;;
    *) rm -f "$f" ;;
  esac
done
cp "$ROOT/desktop/files/php.ini" "$OUT/php/php.ini"
cp "$CACHE/cacert.pem" "$OUT/php/cacert.pem"
cp "$CACHE/vc_redist.x64.exe" "$OUT/vc_redist.x64.exe"

echo "3/6 The POS…"
rsync -a \
  --exclude '.DS_Store' --exclude 'error_log' --exclude '*.zip' \
  --exclude 'app/config/database.php' --exclude 'app/config/database.local.php' \
  --exclude 'app/config/mail.php' --exclude 'app/config/setup.lock' \
  --exclude 'app/databases/*.sqlite' --exclude 'app/databases/dazutech_*.sql' --exclude 'app/databases/seed_*.sql' \
  "$ROOT/app" "$OUT/"
rsync -a \
  --exclude '.DS_Store' --exclude 'error_log' --exclude '*.zip' \
  --exclude 'devs/' --exclude 'reset/' --exclude 'support/' --exclude 'migrations/' \
  --exclude 'download/' --exclude 'downloads/' \
  --exclude 'clean_migrations.php.bak' --exclude 'migrate.php' \
  --exclude 'uploads/blogs/' --exclude 'uploads/gallery/' --exclude 'uploads/projects/' --exclude 'uploads/services/' \
  "$ROOT/public" "$OUT/"
cp "$ROOT/index.php" "$OUT/" 2>/dev/null || true
cp "$ROOT/desktop/files/database.php" "$ROOT/desktop/files/desktop.php" "$ROOT/desktop/files/mail.php" "$OUT/app/config/"

echo "4/6 Empty database…"
mkdir -p "$OUT/data/backups" "$OUT/data/sessions" "$OUT/data/logs"
# Structure + built-in roles, no data (desktop/files/schema.sql; refresh it
# with desktop/make-schema.sh after database changes).
sqlite3 "$OUT/data/pos.sqlite" < "$ROOT/desktop/files/schema.sql"
sqlite3 "$OUT/data/pos.sqlite" "PRAGMA journal_mode=WAL;" >/dev/null

echo "5/6 Launchers…"
for f in "Start POS.bat" "Stop POS.bat" "Install.bat" "README.txt"; do
  # Windows line endings
  python3 -c "import sys;d=open(sys.argv[1],'rb').read().replace(b'\r\n',b'\n').replace(b'\n',b'\r\n');open(sys.argv[2],'wb').write(d)" "$ROOT/desktop/files/$f" "$OUT/$f"
done
# The illustrated help (same page the website shows at download/help.html).
cp "$ROOT/public/download/help.html" "$OUT/HELP - Read me first.html"
# Shortcut icon: an .ico wrapping the app's PNG icon.
python3 - "$ROOT/public/assets/icons/icon-192.png" "$OUT/pos.ico" <<'PY'
import struct, sys
png = open(sys.argv[1], 'rb').read()
w, h = struct.unpack('>II', png[16:24])
hdr = struct.pack('<HHH', 0, 1, 1) + struct.pack('<BBBBHHII', w % 256, h % 256, 0, 0, 1, 32, len(png), 22)
open(sys.argv[2], 'wb').write(hdr + png)
PY

echo "6/6 Zip…"
(cd "$ROOT/dist" && zip -qr ShopPOS-Windows.zip ShopPOS)
# Also put it where the website serves it, so uploading the website is all
# that's needed (Settings -> Offline Windows app -> Download).
mkdir -p "$ROOT/public/downloads"
cp "$ROOT/dist/ShopPOS-Windows.zip" "$ROOT/public/downloads/ShopPOS-Windows.zip"
ls -lh "$ROOT/public/downloads/ShopPOS-Windows.zip"
echo "Done: public/downloads/ShopPOS-Windows.zip (upload the website as usual)"
