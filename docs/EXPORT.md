# CSV Export

## Overview

CSV export is available from:

- the device detail page
- the daily/weekly/monthly/yearly breakdown pages

The UI opens a dedicated export page that is prefilled from the current view.

## Detail Export

Detail export downloads one row per plotted point from the current detail chart.

Typical context:

- selected device
- selected interface or `all interfaces`
- current detail window
- current detail offset

Columns:

- `device_serial`
- `device_name`
- `interface`
- `window_start`
- `window_end`
- `sample_timestamp`
- `tx_bytes`
- `rx_bytes`

## Breakdown Export

Breakdown export supports two modes.

### Series Points

One row per plotted point across the visible breakdown cards.

Columns:

- `device_serial`
- `device_name`
- `interface`
- `stats_view`
- `group_title`
- `group_subtitle`
- `point_timestamp`
- `tx_bytes`
- `rx_bytes`

### Summary Cards

One row per visible breakdown card.

Columns:

- `device_serial`
- `device_name`
- `interface`
- `stats_view`
- `group_title`
- `group_subtitle`
- `total_tx_bytes`
- `total_rx_bytes`
- `total_bytes`

## API Examples

Detail export:

```text
/api.php?action=exportCsv&export_context=detail&id=1&interface_id=2&window=48&offset=0
```

Breakdown export, points:

```text
/api.php?action=exportCsv&export_context=stats&id=1&interface_id=2&stats_view=weekly&stats_offset=0&export_mode=points
```

Breakdown export, summary:

```text
/api.php?action=exportCsv&export_context=stats&id=1&interface_id=2&stats_view=weekly&stats_offset=0&export_mode=summary
```

## Notes

- CSV values are exported as raw bytes, not formatted MB/GB/TB strings.
- Empty data ranges still return a valid CSV with headers.
- The download filename is generated automatically from device serial, export type, and timestamp.
