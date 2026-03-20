#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUN_COMPOSER_INSTALL=0
RELEASE_DIR="$ROOT_DIR/build/release"

for arg in "$@"; do
    case "$arg" in
        --composer-install)
            RUN_COMPOSER_INSTALL=1
            ;;
        *)
            RELEASE_DIR="$arg"
            ;;
    esac
done

if ! command -v rsync >/dev/null 2>&1; then
    echo "rsync is required to build a release directory." >&2
    exit 1
fi

cd "$ROOT_DIR"

if [ "$RUN_COMPOSER_INSTALL" -eq 1 ]; then
    if ! command -v composer >/dev/null 2>&1; then
        echo "composer is required when using --composer-install." >&2
        exit 1
    fi

    composer install --no-dev --optimize-autoloader
fi

if [ ! -d vendor ]; then
    echo "vendor/ is missing. Run 'composer install --no-dev --optimize-autoloader' first, or use --composer-install." >&2
    exit 1
fi

rm -rf "$RELEASE_DIR"
mkdir -p "$RELEASE_DIR/var/database"

rsync -a public/ "$RELEASE_DIR/public/"
rsync -a src/ "$RELEASE_DIR/src/"
rsync -a vendor/ "$RELEASE_DIR/vendor/"

cp .env.example "$RELEASE_DIR/.env.example"
cp .env.production.example "$RELEASE_DIR/.env.production.example"
cp configuration.php "$RELEASE_DIR/configuration.php"

if [ -f LICENSE ]; then
    cp LICENSE "$RELEASE_DIR/LICENSE"
fi

cat <<EOF
Release directory created at:
  $RELEASE_DIR

Suggested next steps:
  1. Copy your real .env into the release directory.
  2. Upload the contents of the release directory.
  3. Point your web server document root to:
     public/
EOF
