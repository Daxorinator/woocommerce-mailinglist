#!/usr/bin/env bash
#
# Exercise the public unsubscribe endpoint on a real WordPress over HTTP.
#
# Needs: php, mysql client, curl, jq, and a MySQL server reachable on 127.0.0.1 as root without a password.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORK="${E2E_DIR:-$(mktemp -d)}"
SITE="$WORK/site"
PORT="${E2E_PORT:-8765}"
BASE="http://127.0.0.1:$PORT"
DB="mlp_e2e"

wp() {
	php -d memory_limit=512M "$WORK/wp-cli.phar" --path="$SITE" --allow-root "$@"
}

cleanup() {
	if [ -n "${SERVER_PID:-}" ]; then
		kill "$SERVER_PID" 2>/dev/null || true
	fi
}
trap cleanup EXIT

failures=0
check() {
	local label="$1" expected="$2" actual="$3"
	if [ "$expected" = "$actual" ]; then
		echo "PASS  $label"
	else
		echo "FAIL  $label (expected '$expected', got '$actual')"
		failures=$((failures + 1))
	fi
}
contains() {
	local label="$1" needle="$2" haystack="$3"
	if [[ "$haystack" == *"$needle"* ]]; then
		echo "PASS  $label"
	else
		echo "FAIL  $label (missing '$needle')"
		failures=$((failures + 1))
	fi
}

mkdir -p "$WORK" "$SITE"
curl --fail --silent --show-error --location --retry 3 -o "$WORK/wp-cli.phar" \
	https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar

mysql --host=127.0.0.1 --user=root --execute="DROP DATABASE IF EXISTS $DB; CREATE DATABASE $DB"

wp core download --quiet
wp config create --dbname="$DB" --dbuser=root --dbpass= --dbhost=127.0.0.1 --skip-check --quiet
wp core install --url="$BASE" --title="E2E" --admin_user=admin --admin_password=admin \
	--admin_email=admin@example.com --skip-email --quiet

mkdir -p "$SITE/wp-content/mu-plugins"
cp "$ROOT/tests/stubs/wpforms.php" "$SITE/wp-content/mu-plugins/mlp-wpforms-stub.php"
ln -s "$ROOT" "$SITE/wp-content/plugins/mailinglist-plugin"
wp plugin activate mailinglist-plugin

SEED="$(wp eval-file "$ROOT/tests/e2e/setup.php" | tail -n1)"
echo "Seeded: $SEED"

ANN_URL="$(jq -r .ann_url <<<"$SEED")"
BEN_URL="$(jq -r .ben_url <<<"$SEED")"
PRODUCT_URL="$(jq -r .product_url <<<"$SEED")"

php -S "127.0.0.1:$PORT" -t "$SITE" >"$WORK/server.log" 2>&1 &
SERVER_PID=$!
for _ in $(seq 1 30); do
	curl --silent --output /dev/null "$BASE/" && break
	sleep 1
done

entries() {
	wp db query "SELECT COUNT(*) FROM $(wp db prefix)wpforms_entries WHERE fields LIKE '%$1%'" --skip-column-names
}
status() { curl --silent --output /dev/null --write-out '%{http_code}' "$@"; }

check "ann starts with two entries" 2 "$(entries ann@example.com)"

body="$(curl --silent "$ANN_URL")"
contains "GET shows a confirmation" "Stop sending marketing emails to ann@example.com?" "$body"
check "GET returns 200" 200 "$(status "$ANN_URL")"
check "GET does not unsubscribe" 2 "$(entries ann@example.com)"

check "tampered token is rejected" 400 "$(status "${ANN_URL}x")"
check "product lists cannot be unsubscribed from" 400 "$(status --request POST "$PRODUCT_URL")"
check "nothing changed so far" 2 "$(entries ann@example.com)"

body="$(curl --silent --data 'mlp_confirm=1' "$ANN_URL")"
contains "POST confirms the unsubscribe" "ann@example.com has been unsubscribed" "$body"
check "POST removed every entry for ann" 0 "$(entries ann@example.com)"
check "other people are untouched" 1 "$(entries ben@example.com)"

body="$(curl --silent --data 'List-Unsubscribe=One-Click' "$BEN_URL")"
contains "one-click POST unsubscribes" "ben@example.com has been unsubscribed" "$body"
check "one-click removed ben's entry" 0 "$(entries ben@example.com)"

tables="$(wp db query "SHOW TABLES LIKE '%mlp_unsubscribes%'" --skip-column-names)"
check "no unsubscribe log table exists" "" "$tables"

if [ "$failures" -gt 0 ]; then
	echo "$failures check(s) failed"
	echo "--- server log ---"
	cat "$WORK/server.log"
	exit 1
fi
echo "All end-to-end checks passed"
