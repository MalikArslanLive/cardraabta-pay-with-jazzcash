#!/usr/bin/env bash
set -euo pipefail
: "${WP_PATH:?Set WP_PATH to a disposable WordPress installation}"
: "${CRJ_TESTING:?Set CRJ_TESTING=1 only for a disposable database}"
[[ "$CRJ_TESTING" == 1 ]]
repo_dir="$(cd "$(dirname "$0")/.." && pwd)"
cd "$WP_PATH"
wp core is-installed
wp plugin is-active woocommerce
wp plugin is-active cardraabta-pay-with-jazzcash
wp eval-file "$repo_dir/tests/integration.php"
