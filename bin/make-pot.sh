#!/usr/bin/env bash
# Generate the .pot file for Myrk.
# Run from the plugin root: bin/make-pot.sh
#
# Requires WP-CLI. Increases the PHP memory limit to handle Peast JS parsing.
# Adjust WP_CLI_PHP if your wp-cli.phar lives somewhere else.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_DIR="$(dirname "$SCRIPT_DIR")"

WP_CLI_PHP="${WP_CLI_PHP:-php}"
WP_CLI_PHAR="${WP_CLI_PHAR:-$(command -v wp 2>/dev/null || echo "wp")}"

cd "$PLUGIN_DIR"

$WP_CLI_PHP -d memory_limit=512M "$WP_CLI_PHAR" i18n make-pot . \
  languages/myrk.pot \
  --domain=myrk \
  --exclude=build,node_modules,vendor,tests,pro
