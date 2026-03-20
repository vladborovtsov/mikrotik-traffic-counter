# Legacy Migration

v2 does not reuse the legacy schema in place.

The migration flow is:

1. export the legacy SQLite file to JSON
2. optionally prepare a mapping file
3. import into a fresh v2 database

## Export

```bash
php scripts/export-legacy-sqlite.php src/tikstats.sqlite /tmp/legacy-export.json legacy
```

Arguments:

1. legacy SQLite path
2. output JSON path
3. optional interface name for imported legacy samples, defaults to `legacy`

The export contains:

- `devices`
- `interfaces`
- `traffic_samples`

Legacy traffic rows are exported as delta-only samples because the old schema does not retain raw counters.

## Import

Import the exported JSON into a fresh v2 database with your normal `.env` or environment configuration:

```bash
php scripts/import-legacy-json.php /tmp/legacy-export.json
```

## Mapping File Workflow

If the legacy export represents one physical device as multiple pseudo-devices or fake serial numbers, prepare a mapping file first:

```bash
php scripts/prepare-legacy-mapping.php /tmp/legacy-export.json var/legacy-mapping.json
```

The helper script:

- inspects the exported legacy data
- inspects the currently configured target database
- lets you skip unwanted legacy devices entirely
- prompts interactively for target device serials or existing device IDs
- shows existing interfaces on the selected target device and lets you pick by number or by name
- writes a reusable mapping JSON file

Then import with the mapping:

```bash
php scripts/import-legacy-json.php /tmp/legacy-export.json --mapping=var/legacy-mapping.json
```

This is the recommended path when legacy pseudo-devices need to be merged into one real device with multiple interfaces.

## Rollback Manifest

The importer now writes a rollback manifest file for imported `traffic_samples` IDs, for example:

```text
var/legacy-import-rollback-20260320-120000.json
```

That manifest can be used to delete exactly the imported traffic sample rows later:

```bash
php scripts/rollback-legacy-import.php var/legacy-import-rollback-20260320-120000.json
```

Notes:

- rollback currently applies only to imported `traffic_samples`
- it does not attempt to restore device/interface metadata
- the rollback script deletes exactly the traffic sample IDs recorded by the import manifest

## Docker Examples

Export from inside the PHP container:

```bash
docker compose run --rm php php scripts/export-legacy-sqlite.php src/tikstats.sqlite /tmp/legacy-export.json legacy
```

Copy the export out if needed:

```bash
docker compose run --rm php sh -lc 'cp /tmp/legacy-export.json var/legacy-export.json'
```

Import into a fresh SQLite-backed v2 database in Docker:

```bash
docker compose run --rm php php scripts/import-legacy-json.php /tmp/legacy-export.json
```

Prepare an interactive mapping file in Docker:

```bash
docker compose run --rm -it php php scripts/prepare-legacy-mapping.php /tmp/legacy-export.json var/legacy-mapping.json
```

Import into a MySQL-backed v2 database in Docker:

```bash
docker compose --profile mysql run --rm \
  -e DB_DRIVER=mysql \
  -e DB_MYSQL_HOST=mysql \
  -e DB_MYSQL_PORT=3306 \
  -e DB_MYSQL_DATABASE=tikstats \
  -e DB_MYSQL_USERNAME=tikstats \
  -e DB_MYSQL_PASSWORD=secret \
  php php scripts/import-legacy-json.php /tmp/legacy-export.json
```

Import into MySQL with a mapping file:

```bash
docker compose --profile mysql run --rm -it \
  -e DB_DRIVER=mysql \
  -e DB_MYSQL_HOST=mysql \
  -e DB_MYSQL_PORT=3306 \
  -e DB_MYSQL_DATABASE=tikstats \
  -e DB_MYSQL_USERNAME=tikstats \
  -e DB_MYSQL_PASSWORD=secret \
  php php scripts/prepare-legacy-mapping.php /tmp/legacy-export.json var/legacy-mapping.json

docker compose --profile mysql run --rm \
  -e DB_DRIVER=mysql \
  -e DB_MYSQL_HOST=mysql \
  -e DB_MYSQL_PORT=3306 \
  -e DB_MYSQL_DATABASE=tikstats \
  -e DB_MYSQL_USERNAME=tikstats \
  -e DB_MYSQL_PASSWORD=secret \
  php php scripts/import-legacy-json.php /tmp/legacy-export.json --mapping=var/legacy-mapping.json
```

## Notes

- the runtime application does not read the legacy schema directly
- imported historical data may not appear in the default chart window if it falls outside `DEFAULT_WINDOW_HOURS`
- after import, use a larger `window` or `offset` when checking older data
