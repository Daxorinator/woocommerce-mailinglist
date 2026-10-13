#!/usr/bin/env bash
#
# Build the installable plugin zip from the committed files (honours .gitattributes export-ignore).
#
# Usage: bin/build-zip.sh [output-dir]
set -euo pipefail

cd "$(git rev-parse --show-toplevel)"

out_dir="${1:-dist}"
version="$(sed -n 's/^ \* Version:[[:space:]]*//p' mailinglist-plugin.php | head -n1)"
if [ -z "$version" ]; then
	echo "Could not read the Version header from mailinglist-plugin.php" >&2
	exit 1
fi

mkdir -p "$out_dir"
zip_path="$out_dir/mailinglist-plugin-${version}.zip"
rm -f "$zip_path"

git archive --format=zip --prefix=mailinglist-plugin/ --output="$zip_path" HEAD

# A release must contain the plugin only.
if unzip -l "$zip_path" | grep -E 'mailinglist-plugin/(tests|vendor|\.github|bin)/|composer\.(json|lock)|phpunit|phpcs|phpstan'; then
	echo "Development files leaked into the release zip" >&2
	exit 1
fi
unzip -l "$zip_path" | grep -q 'mailinglist-plugin/mailinglist-plugin.php' || {
	echo "Main plugin file missing from the zip" >&2
	exit 1
}

unzip -l "$zip_path" | grep -q 'mailinglist-plugin/lib/plugin-update-checker/plugin-update-checker.php' || {
	echo "Bundled update checker missing from the zip" >&2
	exit 1
}

echo "$zip_path"
