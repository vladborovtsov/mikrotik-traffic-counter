<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mikrotik Traffic Counter</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .card { 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            padding: 15px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .card-title { 
            font-size: 18px; 
            font-weight: bold; 
            margin-bottom: 10px; 
            border-bottom: 1px solid #eee;
            padding-bottom: 5px;
        }
        .stats-row {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        select {
            padding: 5px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        label {
            margin-right: 10px;
        }
        .device-list { 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            padding: 15px; 
            margin-bottom: 20px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .device-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .device-item:last-child {
            border-bottom: none;
        }
        button {
            padding: 5px 10px;
            cursor: pointer;
        }
        .nav-buttons {
            margin-top: 10px;
            display: flex;
            gap: 10px;
        }
        #loading {
            text-align: center;
            font-size: 20px;
            margin-top: 50px;
        }
    </style>
    <script type="text/javascript" src="https://www.gstatic.com/charts/loader.js"></script>
    <script>
        google.charts.load('current', {'packages':['corechart', 'controls']});
    </script>
</head>
<body>
    <div id="app">
        <div id="loading">Loading...</div>
    </div>

    <script>
        const app = document.getElementById('app');
        
        // Configuration from URL
        function getParams() {
            const urlParams = new URLSearchParams(window.location.search);
            return {
                id: urlParams.get('id'),
                window: parseInt(urlParams.get('window')) || 48,
                offset: parseInt(urlParams.get('offset')) || 0,
                unit: urlParams.get('unit') || 'GB'
            };
        }

        function setParams(params) {
            const url = new URL(window.location);
            const current = getParams();
            const newParams = { ...current, ...params };
            
            Object.keys(newParams).forEach(key => {
                if (newParams[key] !== null && newParams[key] !== undefined) {
                    url.searchParams.set(key, newParams[key]);
                } else {
                    url.searchParams.delete(key);
                }
            });
            window.history.pushState({}, '', url);
            render();
        }

        function formatTraffic(input, unit = null) {
            if (input === null || input === undefined) input = 0;
            const mb = (input / 1024 / 1024).toFixed(2);
            const gb = (input / 1024 / 1024 / 1024).toFixed(3);
            const tb = (input / 1024 / 1024 / 1024 / 1024).toFixed(4);
            const pb = (input / 1024 / 1024 / 1024 / 1024 / 1024).toFixed(5);
            const eb = (input / 1024 / 1024 / 1024 / 1024 / 1024 / 1024).toFixed(6);

            if (unit) {
                switch (unit) {
                    case 'MB': return mb + " <b>MB</b>";
                    case 'GB': return gb + " <b>GB</b>";
                    case 'TB': return tb + " <b>TB</b>";
                    case 'PB': return pb + " <b>PB</b>";
                    case 'EB': return eb + " <b>EB</b>";
                    default: return mb + " <b>MB</b>";
                }
            }
            return eb + " <b>EB</b> &nbsp;&nbsp; " + pb + " <b>PB</b> &nbsp;&nbsp; " + tb + " <b>TB</b> &nbsp;&nbsp; " + gb + " <b>GB</b> &nbsp;&nbsp; " + mb + " <b>MB</b>";
        }

        async function render() {
            const params = getParams();
            app.innerHTML = '<div id="loading">Loading...</div>';

            if (!params.id) {
                await renderDeviceList();
            } else {
                await renderDeviceDetails(params);
            }
        }

        async function renderDeviceList() {
            try {
                const response = await fetch('api.php?action=getDevices');
                const devices = await response.json();

                if (devices.length === 0) {
                    app.innerHTML = '<div class="card">No devices found.</div>';
                    return;
                }

                let html = '<div class="device-list"><h2>Available Devices</h2>';
                devices.forEach(device => {
                    html += `
                        <div class="device-item">
                            <a href="?id=${device.id}" onclick="event.preventDefault(); setParams({id: ${device.id}})">
                                <strong>${device.sn}</strong>
                            </a> (${device.comment || ''}) Last check: ${device.last_check || 'never'}<br/>
                        </div>`;
                });
                html += '</div>';
                app.innerHTML = html;
            } catch (error) {
                app.innerHTML = `<div class="card">Error loading devices: ${error.message}</div>`;
            }
        }

        async function renderDeviceDetails(params) {
            try {
                const response = await fetch(`api.php?action=getDeviceData&id=${params.id}&window=${params.window}&offset=${params.offset}`);
                const data = await response.json();

                if (data.error) {
                    app.innerHTML = `<div class="card">Error: ${data.error}</div><button onclick="setParams({id: null, window: null, offset: null, unit: null})">Back to list</button>`;
                    return;
                }

                const device = data.device;
                const stats = data.stats;
                const windowData = data.window;

                let html = `
                    <div class="card">
                        <div class='card-title'>Device Information</div>
                        <strong>Device Serial: ${device.sn}</strong> (${device.comment || ''})<br/>
                        Last check time: ${device.last_check || 'never'} <br/>
                        
                        <div class="form-group" style="margin-top: 15px;">
                            <label for="unitSelector">Display units as: </label>
                            <select id="unitSelector">
                                ${['MB', 'GB', 'TB', 'PB', 'EB'].map(u => `<option value="${u}" ${u === params.unit ? 'selected' : ''}>${u}</option>`).join('')}
                            </select>
                        </div>
                        
                        Last results: <br/>&nbsp;&nbsp;
                        TX: ${formatTraffic(device.last_tx, params.unit)}<br/>&nbsp;&nbsp;
                        RX: ${formatTraffic(device.last_rx, params.unit)}
                    </div>

                    <div class="form-group">
                        <label for="windowSelector">Window length: </label>
                        <select id="windowSelector">
                            <optgroup label="Hours">
                                ${[1, 3, 6, 9, 12, 24, 48, 72].map(h => `<option value="${h}" ${h === params.window ? 'selected' : ''}>${h} hours</option>`).join('')}
                            </optgroup>
                            <optgroup label="Days">
                                ${[1, 2, 3, 7, 14, 30, 60, 90, 180].map(d => `<option value="${d*24}" ${d*24 === params.window ? 'selected' : ''}>${d} days</option>`).join('')}
                            </optgroup>
                        </select>

                        <div class="nav-buttons">
                            <button id="olderBtn">← Older</button>
                            ${params.offset > 0 ? `<button id="currentBtn">Current</button>` : ''}
                            ${params.offset > 0 ? `<button id="newerBtn">Newer →</button>` : ''}
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-title">Traffic Chart</div>
                        <div id="dashboard_div" style="width: 100%;">
                            <div id="chart_div" style="width: 100%; height: 400px;"></div>
                            <div id="filter_div" style="width: 100%; height: 100px;"></div>
                        </div>
                    </div>

                    <div class="stats-row">
                        <div class="card" style="flex: 1;">
                            <div class='card-title'>Daily Stats</div>
                            From: ${stats.daily.range.from} to ${stats.daily.range.to}<br/>
                            TX: ${formatTraffic(stats.daily.data.sumtx, params.unit)}<br/>
                            RX: ${formatTraffic(stats.daily.data.sumrx, params.unit)}<br/>
                            Total: ${formatTraffic(Number(stats.daily.data.sumtx || 0) + Number(stats.daily.data.sumrx || 0), params.unit)}
                        </div>
                        <div class="card" style="flex: 1;">
                            <div class='card-title'>Weekly Stats</div>
                            From: ${stats.weekly.range.from} to ${stats.weekly.range.to}<br/>
                            TX: ${formatTraffic(stats.weekly.data.sumtx, params.unit)}<br/>
                            RX: ${formatTraffic(stats.weekly.data.sumrx, params.unit)}<br/>
                            Total: ${formatTraffic(Number(stats.weekly.data.sumtx || 0) + Number(stats.weekly.data.sumrx || 0), params.unit)}
                        </div>
                        <div class="card" style="flex: 1;">
                            <div class='card-title'>Monthly Stats</div>
                            From: ${stats.monthly.range.from} to ${stats.monthly.range.to}<br/>
                            TX: ${formatTraffic(stats.monthly.data.sumtx, params.unit)}<br/>
                            RX: ${formatTraffic(stats.monthly.data.sumrx, params.unit)}<br/>
                            Total: ${formatTraffic(Number(stats.monthly.data.sumtx || 0) + Number(stats.monthly.data.sumrx || 0), params.unit)}
                        </div>
                    </div>

                    <div class="card">
                        <div class='card-title'>Total Stats</div>
                        TX: ${formatTraffic(stats.total.data.sumtx, params.unit)}<br/>
                        RX: ${formatTraffic(stats.total.data.sumrx, params.unit)}<br/>
                        Total: ${formatTraffic(Number(stats.total.data.sumtx || 0) + Number(stats.total.data.sumrx || 0), params.unit)}
                    </div>
                    
                    <button onclick="setParams({id: null, window: null, offset: null, unit: null})">Back to list</button>
                    <hr/>
                `;
                app.innerHTML = html;

                // Event Listeners
                document.getElementById('unitSelector').addEventListener('change', (e) => setParams({unit: e.target.value}));
                document.getElementById('windowSelector').addEventListener('change', (e) => setParams({window: parseInt(e.target.value), offset: 0}));
                document.getElementById('olderBtn').addEventListener('click', () => setParams({offset: params.offset + 1}));
                if (document.getElementById('currentBtn')) {
                    document.getElementById('currentBtn').addEventListener('click', () => setParams({offset: 0}));
                }
                if (document.getElementById('newerBtn')) {
                    document.getElementById('newerBtn').addEventListener('click', () => setParams({offset: params.offset - 1}));
                }

                // Draw Chart
                if (google.visualization && google.visualization.Dashboard) {
                    drawChart(data, params.unit, windowData);
                } else {
                    google.charts.setOnLoadCallback(() => drawChart(data, params.unit, windowData));
                }

            } catch (error) {
                app.innerHTML = `<div class="card">Error loading device details: ${error.message}</div><button onclick="setParams({id: null, window: null, offset: null, unit: null})">Back to list</button>`;
                console.error(error);
            }
        }

        function drawChart(apiData, unit, windowInfo) {
            const divisor = {
                'MB': 1024 * 1024,
                'GB': 1024 * 1024 * 1024,
                'TB': 1024 * 1024 * 1024 * 1024,
                'PB': 1024 * 1024 * 1024 * 1024 * 1024,
                'EB': 1024 * 1024 * 1024 * 1024 * 1024 * 1024
            }[unit] || (1024 * 1024);

            const dataTable = new google.visualization.DataTable();
            dataTable.addColumn('datetime', 'Date/Time');
            dataTable.addColumn('number', `TX (${unit})`);
            dataTable.addColumn('number', `RX (${unit})`);

            apiData.chartData.forEach(row => {
                dataTable.addRow([
                    new Date(row.hour.replace(' ', 'T')),
                    parseFloat((row.tx / divisor).toFixed(2)),
                    parseFloat((row.rx / divisor).toFixed(2))
                ]);
            });

            const dashboard = new google.visualization.Dashboard(document.getElementById('dashboard_div'));

            const rangeSlider = new google.visualization.ControlWrapper({
                'controlType': 'ChartRangeFilter',
                'containerId': 'filter_div',
                'options': {
                    'filterColumnIndex': 0,
                    'ui': {
                        'chartType': 'AreaChart',
                        'chartOptions': {
                            'chartArea': {'width': '90%', 'height': '50%'},
                            'hAxis': {'baselineColor': 'none'},
                            'colors': ['#4285F4', '#DB4437'],
                            'lineWidth': 1,
                            'areaOpacity': 0.2
                        },
                        'chartView': {'columns': [0, 1, 2]},
                        'minRangeSize': 3600000,
                        'snapToData': true
                    }
                }
            });

            let subtitle = "";
            if (windowInfo.offset === 0) {
                subtitle = "Last " + windowInfo.length + " hours";
            } else {
                subtitle = windowInfo.start + " to " + windowInfo.end;
            }

            const chartWrapper = new google.visualization.ChartWrapper({
                'chartType': 'ComboChart',
                'containerId': 'chart_div',
                'options': {
                    'title': 'Traffic Stats',
                    'subtitle': subtitle,
                    'chartArea': {'width': '90%', 'height': '80%'},
                    'legend': {'position': 'top'},
                    'seriesType': 'area',
                    'series': {
                        0: {color: '#4285F4', lineWidth: 2, pointSize: 3, areaOpacity: 0.3},
                        1: {color: '#DB4437', lineWidth: 2, pointSize: 3, areaOpacity: 0.3}
                    },
                    'focusTarget': 'category',
                    'curveType': 'function',
                    'hAxis': {'title': 'Date/Time'},
                    'vAxis': {'title': `Traffic (${unit})`},
                    'explorer': {
                        'actions': ['dragToZoom', 'rightClickToReset', 'dragToPan'],
                        'axis': 'both',
                        'keepInBounds': true,
                        'maxZoomIn': 0.01
                    }
                }
            });

            dashboard.bind(rangeSlider, chartWrapper);
            dashboard.draw(dataTable);
        }

        // Handle back/forward navigation
        window.onpopstate = render;

        // Initial render
        render();
    </script>
</body>
</html>
