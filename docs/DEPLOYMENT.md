# Deployment

## Local Run

Install dependencies:

```bash
composer install
```

Run with PHP's built-in server:

```bash
php -S 127.0.0.1:8080 -t public
```

Open:

```text
http://127.0.0.1:8080
```

Subfolder deployments are supported as long as the deployed subfolder points to `public/`.

## Configuration

Config is loaded from:

1. real environment variables
2. fallback env file, defaulting to `.env`

Use:

```bash
cp .env.example .env
```

For production-oriented setup you can also start from:

```bash
cp .env.production.example .env
```

To use a different fallback file without replacing `.env`, set `APP_ENV_FILE`:

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

## Docker

SQLite mode:

```bash
docker compose up --build
```

MySQL mode:

```bash
docker compose --profile mysql up --build
```

If using MySQL through Compose, the app should typically use:

```dotenv
DB_DRIVER=mysql
DB_MYSQL_HOST=mysql
DB_MYSQL_PORT=3306
DB_MYSQL_DATABASE=tikstats
DB_MYSQL_USERNAME=tikstats
DB_MYSQL_PASSWORD=secret
```

MySQL data is stored in `./mysql_data`.

The nginx container serves `public/` and denies access to `.env`, `.git`, and database files.

## Release Build

Build a release directory after preparing production dependencies:

```bash
composer install --no-dev --optimize-autoloader
bash scripts/build-release.sh
```

Or do both in one step:

```bash
bash scripts/build-release.sh --composer-install
```

Docker wrapper:

```bash
bash scripts/build-release-docker.sh
```

If the PHP image was built before the Docker wrapper or release dependencies changed:

```bash
docker compose build php
```

Release output:

```text
build/release
```

The release build copies:

- `public/`
- `src/`
- `vendor/`
- `configuration.php`
- `.env.example`
- `.env.production.example`
- `var/`
- `LICENSE` if present

Typical deploy flow:

1. create a real `.env` inside `build/release`
2. upload `build/release`
3. point the web root at `public/`

## Testing

Run the normal test suite:

```bash
composer test
```

Run the MySQL-backed path with a disposable MySQL database:

```bash
TEST_MYSQL_HOST=127.0.0.1 \
TEST_MYSQL_PORT=3306 \
TEST_MYSQL_DATABASE=tikstats_test \
TEST_MYSQL_USERNAME=tikstats \
TEST_MYSQL_PASSWORD=secret \
composer test
```
