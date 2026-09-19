#!/bin/sh
# Serve the settings page on http://127.0.0.1:8765 against a throwaway fixture.
cd "$(dirname "$0")" || exit 1
ROOT=$(php -r 'require "../lib.php"; echo mm_fixture();')
echo "fixture: $ROOT"
MM_EMHTTP="$ROOT" MM_CONFIG="$ROOT/layout.json" exec php -S 127.0.0.1:8765 router.php
