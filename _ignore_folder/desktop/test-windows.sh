#!/bin/bash
# Automatic test of the shop-computer version ON WINDOWS (GitHub Actions,
# see .github/workflows/windows-desktop.yml). Run from inside the unzipped
# ShopPOS folder after "Start POS.bat". Checks what a shop owner would do:
# set up, log in, sell, back up, open every screen.
set -uo pipefail
U=http://127.0.0.1:8080
J=$(mktemp)
pass=0; fail=0
check() { if [ "$2" = "1" ]; then echo "PASS $1"; pass=$((pass+1)); else echo "FAIL $1  (${3:-})"; fail=$((fail+1)); fi; }
# PHPBIN lets the same test run on a Mac/Linux against a local build.
php() { if [ -n "${PHPBIN:-}" ]; then command "$PHPBIN" "$@"; else ./php/php.exe -c ./php/php.ini "$@"; fi; }
sql() { php -r '$d=new PDO("sqlite:data/pos.sqlite"); echo $d->query($argv[1])->fetchColumn();' "$1"; }

# 1. PHP itself: the extensions the POS needs load on Windows.
MODS=$(php -m)
for m in pdo_sqlite sqlite3 mbstring gd curl openssl; do
  check "PHP extension $m loads on Windows" "$(grep -qix "$m" <<< "$MODS" && echo 1)" "$(echo "$MODS" | tr '\n' ' ')"
done

# 2. The server started by "Start POS.bat" answers.
for i in $(seq 1 20); do curl -s -o /dev/null "$U/api/ping.php" && break; sleep 1; done
check "Start POS.bat started the server" "$([ "$(curl -s -o /dev/null -w '%{http_code}' $U/api/ping.php)" = 200 ] && echo 1)"

# 3. First run: create the owner.
PAGE=$(curl -s -c "$J" -b "$J" "$U/setup/")
check "first run shows 'Create the owner account'" "$(grep -q 'Create the owner account' <<< "$PAGE" && echo 1)" "$(echo "$PAGE" | head -c 300)"
check "…and the 'Import my shop from the website' option" "$(grep -q 'Import my shop' <<< "$PAGE" && echo 1)"
CSRF=$(echo "$PAGE" | grep -o 'name="csrf" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
R=$(curl -s -c "$J" -b "$J" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$U/setup/" \
  --data-urlencode "csrf=$CSRF" --data-urlencode "shop_name=Test Shop" --data-urlencode "name=Test Owner" \
  --data "action=create_owner&email=owner@test.local&phone=0700&password=secret123&password2=secret123")
check "owner account created" "$([[ $R == 302*auth/login.php* ]] && [ "$(sql "SELECT COUNT(*) FROM users")" = 1 ] && echo 1)" "$R"

# 4. Log in.
R=$(curl -s -c "$J" -b "$J" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$U/auth/login.php" --data "email=owner@test.local&password=secret123")
check "owner can log in" "$([[ $R == 302*dashboard* ]] && echo 1)" "$R"

# 5. A product and a sale.
TID=$(sql "SELECT tenant_id FROM users LIMIT 1")
php -r '$d=new PDO("sqlite:data/pos.sqlite"); $d->prepare("INSERT INTO products (tenant_id,name,quantity,unit,units_per_pack,buying_price,selling_price,retail_price,status) VALUES (?,?,?,?,?,?,?,?,?)")->execute([$argv[1],"Soda 500ml",50,"piece",1,40,60,60,"active"]);' "$TID"
PID=$(sql "SELECT id FROM products LIMIT 1")
TILL=$(curl -s -c "$J" -b "$J" "$U/super/shop/")
check "the till opens and shows the product" "$(grep -q 'Soda 500ml' <<< "$TILL" && echo 1)"
R=$(curl -s -c "$J" -b "$J" -o /dev/null -w '%{http_code} %{redirect_url}' -X POST "$U/super/shop/" \
  --data-urlencode "cart=[{\"product_id\":$PID,\"quantity\":3,\"price_type\":\"retail\"}]" \
  --data "action=pay&payment_method=cash&amount_tendered=500&_idem=wintest-1")
check "a cash sale is recorded (receipt)" "$([[ $R == 302*receipt.php* ]] && echo 1)" "$R"
check "…stock went down 50 -> 47" "$([ "$(sql "SELECT CAST(quantity AS INT) FROM products WHERE id=$PID")" = 47 ] && echo 1)"
R=$(curl -s -c "$J" -b "$J" -o /dev/null -w '%{http_code}' -X POST "$U/super/shop/" \
  --data-urlencode "cart=[{\"product_id\":$PID,\"quantity\":3,\"price_type\":\"retail\"}]" \
  --data "action=pay&payment_method=cash&amount_tendered=500&_idem=wintest-1")
check "the same sale sent twice is recorded once" "$([ "$(sql "SELECT COUNT(*) FROM orders")" = 1 ] && echo 1)" "orders=$(sql "SELECT COUNT(*) FROM orders")"

# 6. Backups.
check "today's automatic backup exists" "$(ls data/backups/pos-*.sqlite >/dev/null 2>&1 && echo 1)" "$(ls data/backups)"
SZ=$(curl -s -c "$J" -b "$J" -o dl.sqlite -w '%{size_download}' "$U/super/backup/?download=now")
check "'Download current data' gives a database file" "$([ "${SZ:-0}" -gt 10000 ] && echo 1)" "$SZ bytes"
rm -f dl.sqlite

# 7. Every screen opens without an error.
bad=0; n=0
for f in $(cd public && find super staff offline -name '*.php' | sort); do
  case "$f" in */partials/*) continue ;; esac
  n=$((n+1))
  body=$(curl -s -m 60 -c "$J" -b "$J" -w '\n%{http_code}' "$U/$f")
  code=$(echo "$body" | tail -1)
  if [ "$code" -ge 500 ] || grep -qiE 'fatal error|uncaught|warning:</b>|parse error' <<< "$body"; then
    bad=$((bad+1)); echo "   problem: $code $f"
  fi
done
check "all $n screens open on Windows" "$([ $bad = 0 ] && echo 1)" "$bad with problems"
if [ -f data/logs/php-errors.log ] && grep -qiE 'fatal|uncaught' data/logs/php-errors.log; then
  check "no PHP errors in data/logs/php-errors.log" 0 "$(grep -iE 'fatal|uncaught' data/logs/php-errors.log | head -3)"
else
  check "no PHP errors in data/logs/php-errors.log" 1
fi

echo; echo "$pass passed, $fail failed"
[ $fail = 0 ]
