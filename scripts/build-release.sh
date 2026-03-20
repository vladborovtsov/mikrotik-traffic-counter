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

rsync -a \
    --exclude='.git/' \
    --exclude='.gitignore' \
    --exclude='.idea/' \
    --exclude='.phpunit.cache/' \
    --exclude='build/' \
    --exclude='docker/' \
    --exclude='mysql_data/' \
    --exclude='tests/' \
    --exclude='var/*' \
    --exclude='vendor/bin/' \
    --exclude='vendor/phpunit/' \
    --exclude='vendor/sebastian/' \
    --exclude='.DS_Store' \
    --exclude='index_screenshot.png' \
    --exclude='phpunit.xml.dist' \
    --exclude='scripts/build-release.sh' \
    --exclude='scripts/build-release-docker.sh' \
    --exclude='scripts/export-legacy-sqlite.php' \
    --exclude='scripts/import-legacy-json.php' \
    --exclude='docker-compose.yml' \
    --exclude='README.md' \
    --exclude='PR.md' \
    ./ "$RELEASE_DIR/"

cp .env.example "$RELEASE_DIR/.env.example"
cp .env.production.example "$RELEASE_DIR/.env.production.example"

cat <<EOF
Release directory created at:
  $RELEASE_DIR

Suggested next steps:
  1. Copy your real .env into the release directory.
  2. Upload the contents of the release directory.
  3. Point your web server document root to:
     public/
EOF
