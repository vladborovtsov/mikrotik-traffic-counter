# API Reference

## Collector

Collector ingestion:

```text
/collect/
```

Required query params:

- `sn`
- `interface`
- `tx`
- `rx`

Optional:

- `delta=true`
- `auth=<token>`

Example:

```text
http://<server>/collect/?sn=ABC123456789&interface=ether1&tx=1000&rx=2000&delta=true
```

Fallback:

```text
http://<server>/api.php?action=collect&sn=ABC123456789&interface=ether1&tx=1000&rx=2000&delta=true
```

## API Actions

- `collect`
- `getDevices`
- `getDeviceData`
- `getStatsDrilldown`
- `exportCsv`
- `listInterfaces`
- `renameDevice`
- `updateDevice`
- `deleteDevice`
- `getDeviceSettings`
- `getGlobalSettings`
- `updateGlobalSettings`
- `moveDevice`

## Common Examples

```text
/api.php?action=getDevices
/api.php?action=getDeviceData&id=1&interface_id=2&window=48&offset=0
/api.php?action=getStatsDrilldown&id=1&interface_id=2&stats_view=weekly&stats_offset=0
/api.php?action=updateDevice&id=1&name=Office%20Router&comment=WAN%20uplink
```

## Query Notes

- `window` defaults to `DEFAULT_WINDOW_HOURS`
- `window` is bounded to avoid excessively heavy queries
- `offset` pages through older detail windows of the same size
- `stats_view` is one of `daily`, `weekly`, `monthly`, `total`
- `stats_offset` pages older breakdown ranges
- collector auth is enforced only when `AUTH_ENABLED=true`
- source IP allowlisting is enforced only when `SOURCE_IP_ENABLED=true`

## UI-Driven Flows

The SPA uses:

- `getDeviceData` for the main device detail page
- `getStatsDrilldown` for daily/weekly/monthly/yearly breakdown pages
- `exportCsv` for CSV downloads from the dedicated export page
