# Summary

This PR delivers the `v2` overhaul of the MikroTik traffic counter application.

It replaces the old script-centric layout with a more structured PHP application, adds a PDO-only database layer for SQLite and MySQL, introduces interface-aware traffic storage, consolidates the API and collector flow, adds async frontend behavior, and provides an out-of-band legacy migration path.

# Key Changes

## Architecture

- moved active web entrypoints to `public/`
- introduced application bootstrap through `configuration.php` and `src/bootstrap.php`
- added structured application layers under:
  - `src/Config`
  - `src/Database`
  - `src/Models`
  - `src/Services`
  - `src/Controllers`
  - `src/Http`
  - `src/Support`
- kept `src/index.php`, `src/api.php`, and `src/collector.php` as compatibility shims

## Configuration

- centralized all runtime configuration in `configuration.php`
- environment variables now override `.env`
- added `.env.example`
- added config validation for:
  - database driver selection
  - auth token requirements
  - source IP allowlist requirements
  - timezone validity
  - default window bounds

## Database and Schema

- replaced legacy mixed DB handling with PDO-only support
- added `Database`, `DatabaseSqlite`, and `DatabaseMysql`
- introduced v2 schema:
  - `devices`
  - `interfaces`
  - `traffic_samples`
  - `schema_meta`
- added clean-break legacy schema detection
- added SQLite and MySQL schema initialization paths

## Domain Logic

- added services for:
  - device management
  - interface management
  - traffic ingestion and aggregation
  - request guard enforcement
  - legacy import
- added models for devices, interfaces, and traffic samples
- moved delta computation and auto-create logic into services

## API and Collector

- consolidated collector behavior into `/api.php?action=collect`
- preserved `/collector.php` as a plain-text MikroTik-friendly shim
- added request/response/controller abstractions
- collector now requires `interface`
- added device and interface-aware API responses
- improved API error handling

## Frontend

- rebuilt frontend as an async no-reload client
- split frontend assets into:
  - `public/assets/app.js`
  - `public/assets/app.css`
- added interface-aware detail views
- added history state handling and async device updates
- fixed chart rendering for API hour buckets
- made daily/weekly/monthly cards relative to the selected historical window

## Security and Hardening

- prepared statements throughout runtime code
- optional auth token via query parameter
- optional source IP allowlist with CIDR support
- no-cache API responses
- nginx protections for `.env`, `.git`, and DB files
- collector endpoint rate limiting in nginx
- removed unused `sqlite3` PHP extension from the Docker image

## Migration Tooling

- added `scripts/export-legacy-sqlite.php`
- added `scripts/import-legacy-json.php`
- added legacy JSON streaming import support
- importer creates synthetic snapshots when legacy `last_tx` / `last_rx` are available
- runtime intentionally does not read the legacy schema directly

## Docker and Runtime

- updated PHP image to PHP 8.3
- enabled `pdo_sqlite` and `pdo_mysql`
- changed nginx document root to `public/`
- added optional MySQL service under a Compose profile
- MySQL data now persists under `./mysql_data`

# Breaking Changes

- this is a clean-break v2 schema
- old in-place SQLite schema is intentionally rejected by the runtime
- collector requests must now include `interface`
- intended deployment entrypoints are now under `public/`

# Migration

Legacy migration is out-of-band:

1. export legacy SQLite data to JSON
2. start with a fresh v2 database
3. import the exported JSON into v2

Example:

```bash
php scripts/export-legacy-sqlite.php src/tikstats.sqlite /tmp/legacy-export.json legacy
php scripts/import-legacy-json.php /tmp/legacy-export.json
```

Docker examples are documented in `README.md`.

# Testing

## Automated

```bash
composer test
```

Optional MySQL-backed path:

```bash
TEST_MYSQL_HOST=127.0.0.1 \
TEST_MYSQL_PORT=3306 \
TEST_MYSQL_DATABASE=tikstats_test \
TEST_MYSQL_USERNAME=tikstats \
TEST_MYSQL_PASSWORD=secret \
composer test
```

Current status:

- PHPUnit passes
- MySQL integration test skips cleanly when `TEST_MYSQL_*` is not configured

## Manual

Validated during development:

- API device listing
- collector ingestion
- interface listing
- historical device detail fetch
- imported legacy data totals and chart windows
- Docker-served public entrypoints

# Screenshots

Legacy screenshot reference remains in the repository:

- `index_screenshot.png`

# Follow-Up

- full browser-level validation across more historical windows
- full MySQL runtime validation in Docker
- real MikroTik scheduler/fetch validation in the target environment
