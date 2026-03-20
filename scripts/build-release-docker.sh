#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if ! command -v docker >/dev/null 2>&1; then
    echo "docker is required." >&2
    exit 1
fi

cd "$ROOT_DIR"

COMMAND=("bash" "scripts/build-release.sh" "--composer-install")

for arg in "$@"; do
    COMMAND+=("$arg")
done

if command -v id >/dev/null 2>&1; then
    USER_ARGS=(--user "$(id -u):$(id -g)")
else
    USER_ARGS=()
fi

docker compose run --rm "${USER_ARGS[@]}" php "${COMMAND[@]}"
