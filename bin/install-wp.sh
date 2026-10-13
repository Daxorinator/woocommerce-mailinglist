#!/usr/bin/env bash
#
# Download WordPress (and optionally WooCommerce) for the PHPUnit suite and create its database.
#
# Usage: bin/install-wp.sh <wp-version|latest|nightly> [woocommerce: yes|no]
#
# Environment: WP_CORE_DIR (default /tmp/wordpress), WP_DB_NAME, WP_DB_USER, WP_DB_PASSWORD, WP_DB_HOST.
set -euo pipefail

WP_VERSION="${1:-latest}"
WITH_WC="${2:-yes}"

WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress}"
WP_DB_NAME="${WP_DB_NAME:-wordpress_test}"
WP_DB_USER="${WP_DB_USER:-root}"
WP_DB_PASSWORD="${WP_DB_PASSWORD-}"
WP_DB_HOST="${WP_DB_HOST:-127.0.0.1}"

download() {
	curl --fail --silent --show-error --location --retry 3 --output "$2" "$1"
}

if [ -f "$WP_CORE_DIR/wp-settings.php" ]; then
	echo "WordPress already present in $WP_CORE_DIR"
else
	mkdir -p "$WP_CORE_DIR"
	case "$WP_VERSION" in
		latest) url="https://wordpress.org/latest.tar.gz" ;;
		nightly | trunk) url="https://wordpress.org/nightly-builds/wordpress-latest.zip" ;;
		*) url="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz" ;;
	esac

	tmp="$(mktemp -d)"
	if [[ "$url" == *.zip ]]; then
		download "$url" "$tmp/wp.zip"
		unzip -q "$tmp/wp.zip" -d "$tmp"
		cp -R "$tmp/wordpress/." "$WP_CORE_DIR/"
	else
		download "$url" "$tmp/wp.tar.gz"
		tar -xzf "$tmp/wp.tar.gz" -C "$WP_CORE_DIR" --strip-components=1
	fi
	rm -rf "$tmp"
fi

if [ "$WITH_WC" = "yes" ]; then
	plugins="$WP_CORE_DIR/wp-content/plugins"
	if [ ! -f "$plugins/woocommerce/woocommerce.php" ]; then
		tmp="$(mktemp -d)"
		download "https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip" "$tmp/wc.zip"
		unzip -q "$tmp/wc.zip" -d "$plugins"
		rm -rf "$tmp"
	fi
fi

# The test library drops and recreates every table, so a throwaway database is expected.
mysql_args=(--host="$WP_DB_HOST" --user="$WP_DB_USER")
if [ -n "$WP_DB_PASSWORD" ]; then
	mysql_args+=(--password="$WP_DB_PASSWORD")
fi
mysql "${mysql_args[@]}" --execute="CREATE DATABASE IF NOT EXISTS \`$WP_DB_NAME\`"

echo "WordPress ${WP_VERSION} ready in $WP_CORE_DIR (WooCommerce: $WITH_WC)"
