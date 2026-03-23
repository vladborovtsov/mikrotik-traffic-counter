# Mikrotik Traffic Counter v2

PHP 8.3 application for collecting MikroTik interface counters and visualizing traffic per device and per interface.

## Highlights

- SQLite or MySQL via PDO
- per-device and per-interface traffic history
- async UI with interface-aware detail and breakdown views
- CSV export for detail and breakdown pages
- global `light` / `dark` / `auto` theme setting
- optional collector token auth
- optional collector source-IP allowlist
- legacy migration tooling for old SQLite data

## Quick Start

Requirements:

- PHP 8.3+
- `pdo_sqlite` for SQLite deployments
- `pdo_mysql` for MySQL deployments
- Composer

Create a local config:

```bash
cp .env.example .env
composer install
php -S 127.0.0.1:8080 -t public
```

Then open:

```text
http://127.0.0.1:8080
```

The active web root is `public/`. Subfolder deployments are supported as long as the web server points that subfolder at `public/`.

## Collector

Collector ingestion is exposed at:

```text
/collect/
```

Required query params:

- `sn`
- `interface`
- `tx`
- `rx`

Optional query params:

- `delta=true`
- `auth=<token>` when collector auth is enabled

Example:

```text
http://<server>/collect/?sn=ABC123456789&interface=ether1&tx=1000&rx=2000&delta=true
```

Fallback direct API form:

```text
http://<server>/api.php?action=collect&sn=ABC123456789&interface=ether1&tx=1000&rx=2000&delta=true
```

Delta behavior:

- increased counters contribute the difference from the previous sample
- decreased counters are treated as reset/wrap and contribute `0` delta while establishing a new baseline
- the first-ever sample on a brand new interface intentionally counts in full

## Configuration

Runtime config is centralized in [configuration.php](/workspace/mikrotik-traffic-counter/configuration.php) and loaded from:

1. real environment variables
2. fallback env file, defaulting to `.env`

To use a different fallback file, set `APP_ENV_FILE`, for example:

```bash
APP_ENV_FILE=.env.production php scripts/import-legacy-json.php ./export.json --mapping=./mapping.json
```

Important settings:

- `DB_DRIVER=sqlite|mysql`
- `DB_SQLITE_PATH=var/database/tikstats.sqlite`
- `DB_MYSQL_HOST`, `DB_MYSQL_PORT`, `DB_MYSQL_DATABASE`, `DB_MYSQL_USERNAME`, `DB_MYSQL_PASSWORD`
- `AUTH_ENABLED`, `AUTH_TOKEN`
- `SOURCE_IP_ENABLED`, `SOURCE_IP_ALLOWLIST`
- `APP_TIMEZONE`
- `DEFAULT_WINDOW_HOURS`

The app also stores instance-wide UI preferences in the database via `global_settings`. Current setting:

- `theme_mode=light|dark|auto`

## Documentation

- Deployment and runtime: [docs/DEPLOYMENT.md](/workspace/mikrotik-traffic-counter/docs/DEPLOYMENT.md)
- API reference: [docs/API.md](/workspace/mikrotik-traffic-counter/docs/API.md)
- CSV export: [docs/EXPORT.md](/workspace/mikrotik-traffic-counter/docs/EXPORT.md)
- Legacy migration: [scripts/MIGRATION.md](/workspace/mikrotik-traffic-counter/scripts/MIGRATION.md)

## Validation

Recommended before production use:

1. run the full stack end-to-end in Docker
2. verify the UI in a real browser
3. send real MikroTik collector requests to `/collect/`
4. if using MySQL, run the MySQL-backed PHPUnit path in an environment with MySQL available

Run the standard test suite with:

```bash
composer test
```

## Inspired By

- https://github.com/muhannad0/mikrotik-traffic-counter
- [tikstat](https://github.com/mrkrasser/tikstat) project
- [Mikrotik: WAN Data Monitoring via Scripting](https://aacable.wordpress.com/2015/03/09/5386/) by Syed Janazaib
