const app = document.getElementById('app');
const state = readStateFromUrl();

function readStateFromUrl() {
    const params = new URLSearchParams(window.location.search);
    return {
        deviceId: params.get('id'),
        interfaceId: params.get('interface_id'),
        windowHours: parseInt(params.get('window') || '48', 10) || 48,
        offset: parseInt(params.get('offset') || '0', 10) || 0,
        unit: params.get('unit') || 'GB'
    };
}

function persistState(replace = false) {
    const params = new URLSearchParams();
    if (state.deviceId) params.set('id', state.deviceId);
    if (state.interfaceId) params.set('interface_id', state.interfaceId);
    if (state.windowHours !== 48) params.set('window', String(state.windowHours));
    if (state.offset !== 0) params.set('offset', String(state.offset));
    if (state.unit !== 'GB') params.set('unit', state.unit);

    const url = `${window.location.pathname}${params.toString() ? '?' + params.toString() : ''}`;
    const method = replace ? 'replaceState' : 'pushState';
    window.history[method](null, '', url);
}

function navigate(nextState, replace = false) {
    Object.assign(state, nextState);
    persistState(replace);
    render();
}

function resetToList() {
    navigate({
        deviceId: null,
        interfaceId: null,
        windowHours: 48,
        offset: 0
    });
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function formatTimestamp(value) {
    if (!value) return 'never';
    const date = new Date(value.replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function formatTraffic(input, unit = 'GB') {
    const value = Number(input || 0);
    const divisors = {
        MB: 1024 ** 2,
        GB: 1024 ** 3,
        TB: 1024 ** 4,
        PB: 1024 ** 5,
        EB: 1024 ** 6
    };
    const amount = value / (divisors[unit] || divisors.GB);
    const precision = unit === 'MB' ? 2 : unit === 'GB' ? 3 : 4;
    return `${amount.toFixed(precision)} ${unit}`;
}

async function fetchJson(params) {
    const search = new URLSearchParams(params);
    const response = await fetch(`api.php?${search.toString()}`, {
        headers: {
            Accept: 'application/json'
        }
    });
    const contentType = response.headers.get('content-type') || '';

    if (!contentType.includes('application/json')) {
        const text = await response.text();
        throw new Error(text.trim() || 'Request failed');
    }

    const payload = await response.json();

    if (!response.ok) {
        throw new Error(payload.error || 'Request failed');
    }

    return payload;
}

async function render() {
    app.innerHTML = '<div class="loading">Loading...</div>';

    try {
        if (!state.deviceId) {
            const devices = await fetchJson({ action: 'getDevices' });
            renderDeviceList(devices);
            return;
        }

        const request = {
            action: 'getDeviceData',
            id: state.deviceId,
            window: state.windowHours,
            offset: state.offset
        };

        if (state.interfaceId) {
            request.interface_id = state.interfaceId;
        }

        const detail = await fetchJson(request);
        renderDeviceDetail(detail);
    } catch (error) {
        app.innerHTML = `
            <div class="error-box">${escapeHtml(error instanceof Error ? error.message : String(error))}</div>
            <div class="button-row">
                <button class="btn-secondary" data-action="back-to-list">Back to list</button>
            </div>
        `;
        bindGlobalActions();
    }
}

function renderDeviceList(devices) {
    if (!devices.length) {
        app.innerHTML = '<div class="empty">No devices have reported traffic yet.</div>';
        return;
    }

    const items = devices.map((device) => `
        <div class="device-item">
            <div class="device-item-head">
                <div>
                    <button class="device-link" data-open-device="${device.id}">
                        ${escapeHtml(device.name || device.sn)}
                    </button>
                    <div class="meta">Serial: ${escapeHtml(device.sn)}</div>
                </div>
                <div class="meta">Last check: ${escapeHtml(formatTimestamp(device.last_check))}</div>
            </div>
            <div class="summary-grid">
                <div class="summary-tile">
                    <strong>Upload</strong>
                    ${escapeHtml(formatTraffic(device.last_tx, state.unit))}
                </div>
                <div class="summary-tile">
                    <strong>Download</strong>
                    ${escapeHtml(formatTraffic(device.last_rx, state.unit))}
                </div>
            </div>
            <div class="meta">${escapeHtml(device.comment || 'No device comment configured.')}</div>
        </div>
    `).join('');

    app.innerHTML = `
        <div class="panel">
            <div class="panel-header">
                <h2 class="panel-title">Available Devices</h2>
                <p class="panel-subtitle">Select a device to inspect aggregate or per-interface traffic without reloading the page.</p>
            </div>
            <div class="panel-body">
                <div class="device-list">${items}</div>
            </div>
        </div>
    `;

    app.querySelectorAll('[data-open-device]').forEach((button) => {
        button.addEventListener('click', () => {
            navigate({
                deviceId: button.getAttribute('data-open-device'),
                interfaceId: null,
                offset: 0
            });
        });
    });
}

function renderDeviceDetail(detail) {
    const device = detail.device;
    const stats = detail.stats;
    const interfaces = detail.interfaces || [];
    const selectedInterfaceId = detail.selected_interface_id ? String(detail.selected_interface_id) : '';

    const units = ['MB', 'GB', 'TB', 'PB', 'EB'];
    const interfaceOptions = [
        '<option value="">All interfaces</option>',
        ...interfaces.map((item) => {
            const selected = String(item.id) === selectedInterfaceId ? 'selected' : '';
            return `<option value="${item.id}" ${selected}>${escapeHtml(item.display_name || item.name)}</option>`;
        })
    ].join('');

    app.innerHTML = `
        <div class="split">
            <div class="device-summary">
                <div class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">${escapeHtml(device.name || device.sn)}</h2>
                        <p class="panel-subtitle">
                            Serial ${escapeHtml(device.sn)}
                            ${selectedInterfaceId ? ` · interface ${escapeHtml((interfaces.find((item) => String(item.id) === selectedInterfaceId)?.display_name || interfaces.find((item) => String(item.id) === selectedInterfaceId)?.name || 'selected'))}` : ' · all interfaces'}
                        </p>
                    </div>
                    <div class="panel-body">
                        <div class="summary-grid">
                            <div class="summary-tile">
                                <strong>Last Upload</strong>
                                ${escapeHtml(formatTraffic(device.last_tx, state.unit))}
                            </div>
                            <div class="summary-tile">
                                <strong>Last Download</strong>
                                ${escapeHtml(formatTraffic(device.last_rx, state.unit))}
                            </div>
                            <div class="summary-tile">
                                <strong>Last Check</strong>
                                ${escapeHtml(formatTimestamp(device.last_check))}
                            </div>
                            <div class="summary-tile">
                                <strong>Comment</strong>
                                ${escapeHtml(device.comment || 'None')}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Controls</h2>
                        <p class="panel-subtitle">Switch interface, time window, and units without leaving the page.</p>
                    </div>
                    <div class="panel-body">
                        <div class="toolbar">
                            <div class="toolbar-group">
                                <label for="interfaceSelector">Interface</label>
                                <select id="interfaceSelector">${interfaceOptions}</select>
                            </div>
                            <div class="toolbar-group">
                                <label for="windowSelector">Window</label>
                                <select id="windowSelector">
                                    ${[1, 3, 6, 12, 24, 48, 72, 168, 336, 720, 1440, 2160, 4320].map((hours) => {
                                        const selected = hours === state.windowHours ? 'selected' : '';
                                        const label = hours < 24 ? `${hours} hours` : `${Math.round(hours / 24)} days`;
                                        return `<option value="${hours}" ${selected}>${label}</option>`;
                                    }).join('')}
                                </select>
                            </div>
                            <div class="toolbar-group">
                                <label for="unitSelector">Units</label>
                                <select id="unitSelector">
                                    ${units.map((unit) => `<option value="${unit}" ${unit === state.unit ? 'selected' : ''}>${unit}</option>`).join('')}
                                </select>
                            </div>
                        </div>
                        <div class="button-row" style="margin-top: 14px;">
                            <button class="btn-secondary" data-shift-window="older">Older</button>
                            ${state.offset > 0 ? '<button class="btn-secondary" data-shift-window="current">Current</button>' : ''}
                            ${state.offset > 0 ? '<button class="btn-secondary" data-shift-window="newer">Newer</button>' : ''}
                            <button class="btn-secondary" data-action="back-to-list">Back to list</button>
                        </div>
                    </div>
                </div>

                <div class="panel chart-card">
                    <div class="panel-header">
                        <h2 class="panel-title">Traffic Chart</h2>
                        <p class="panel-subtitle">${escapeHtml(detail.window.offset === 0 ? `Last ${detail.window.length} hours` : `${detail.window.start} to ${detail.window.end}`)}</p>
                    </div>
                    <div class="panel-body">
                        <div id="dashboard_div">
                            <div id="chart_div" style="width: 100%; height: 380px;"></div>
                            <div id="filter_div" style="width: 100%; height: 96px;"></div>
                        </div>
                        <div id="chart_empty" class="chart-empty" style="display: none;">No traffic samples in the selected range.</div>
                    </div>
                </div>

                <div class="stats-grid">
                    ${renderStatCard('Daily', stats.daily, state.unit)}
                    ${renderStatCard('Weekly', stats.weekly, state.unit)}
                    ${renderStatCard('Monthly', stats.monthly, state.unit)}
                    ${renderStatCard('Total', stats.total, state.unit, false)}
                </div>
            </div>

            <div class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">Device Settings</h2>
                    <p class="panel-subtitle">Update the visible label and comment asynchronously.</p>
                </div>
                <div class="panel-body">
                    <form id="deviceForm" class="form-grid">
                        <div>
                            <label for="deviceName">Device name</label>
                            <input id="deviceName" name="name" type="text" value="${escapeHtml(device.name || '')}" placeholder="Optional display name">
                        </div>
                        <div>
                            <label for="deviceComment">Comment</label>
                            <textarea id="deviceComment" name="comment" placeholder="Optional comment">${escapeHtml(device.comment || '')}</textarea>
                        </div>
                        <div class="button-row">
                            <button type="submit" class="btn-primary">Save device</button>
                            <button type="button" class="btn-danger" id="deleteDeviceBtn">Delete device</button>
                        </div>
                        <div id="formStatus" class="meta"></div>
                    </form>
                </div>
            </div>
        </div>
    `;

    bindGlobalActions();
    bindDetailActions(device.id);
    drawChart(detail.chartData, detail.window, state.unit);
}

function renderStatCard(title, payload, unit, includeRange = true) {
    const stats = payload.data;
    const total = Number(stats.sumtx || 0) + Number(stats.sumrx || 0);
    const range = includeRange ? `<div class="meta">${escapeHtml(payload.range.from)} to ${escapeHtml(payload.range.to)}</div>` : '';

    return `
        <div class="stat-card">
            <h3>${escapeHtml(title)}</h3>
            ${range}
            <div>TX: ${escapeHtml(formatTraffic(stats.sumtx, unit))}</div>
            <div>RX: ${escapeHtml(formatTraffic(stats.sumrx, unit))}</div>
            <div>Total: ${escapeHtml(formatTraffic(total, unit))}</div>
        </div>
    `;
}

function bindGlobalActions() {
    app.querySelectorAll('[data-action="back-to-list"]').forEach((button) => {
        button.addEventListener('click', resetToList);
    });

    app.querySelectorAll('[data-shift-window]').forEach((button) => {
        button.addEventListener('click', () => {
            const direction = button.getAttribute('data-shift-window');
            if (direction === 'older') {
                navigate({ offset: state.offset + 1 });
            } else if (direction === 'newer' && state.offset > 0) {
                navigate({ offset: state.offset - 1 });
            } else if (direction === 'current') {
                navigate({ offset: 0 });
            }
        });
    });
}

function bindDetailActions(deviceId) {
    document.getElementById('interfaceSelector')?.addEventListener('change', (event) => {
        navigate({
            interfaceId: event.target.value || null,
            offset: 0
        });
    });

    document.getElementById('windowSelector')?.addEventListener('change', (event) => {
        navigate({
            windowHours: parseInt(event.target.value, 10) || 48,
            offset: 0
        });
    });

    document.getElementById('unitSelector')?.addEventListener('change', (event) => {
        navigate({ unit: event.target.value }, true);
    });

    document.getElementById('deviceForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = new FormData(event.currentTarget);
        const statusNode = document.getElementById('formStatus');

        try {
            statusNode.textContent = 'Saving...';
            await fetchJson({
                action: 'updateDevice',
                id: String(deviceId),
                name: String(form.get('name') || ''),
                comment: String(form.get('comment') || '')
            });
            statusNode.textContent = 'Saved.';
            await render();
        } catch (error) {
            statusNode.textContent = error instanceof Error ? error.message : String(error);
        }
    });

    document.getElementById('deleteDeviceBtn')?.addEventListener('click', async () => {
        const confirmed = window.confirm('Delete this device and all associated interfaces and traffic samples?');
        if (!confirmed) {
            return;
        }

        try {
            await fetchJson({
                action: 'deleteDevice',
                id: String(deviceId)
            });
            resetToList();
        } catch (error) {
            const statusNode = document.getElementById('formStatus');
            if (statusNode) {
                statusNode.textContent = error instanceof Error ? error.message : String(error);
            }
        }
    });
}

function drawChart(chartData, windowInfo, unit) {
    const dashboardDiv = document.getElementById('dashboard_div');
    const emptyDiv = document.getElementById('chart_empty');

    if (!dashboardDiv || !emptyDiv) {
        return;
    }

    if (!chartData.length) {
        dashboardDiv.style.display = 'none';
        emptyDiv.style.display = 'grid';
        return;
    }

    dashboardDiv.style.display = 'block';
    emptyDiv.style.display = 'none';

    google.charts.setOnLoadCallback(() => {
        const data = new google.visualization.DataTable();
        data.addColumn('datetime', 'Timestamp');
        data.addColumn('number', `TX (${unit})`);
        data.addColumn('number', `RX (${unit})`);

        const divisor = {
            MB: 1024 ** 2,
            GB: 1024 ** 3,
            TB: 1024 ** 4,
            PB: 1024 ** 5,
            EB: 1024 ** 6
        }[unit] || 1024 ** 3;

        data.addRows(chartData.map((point) => [
            new Date(String(point.hour).replace(' ', 'T')),
            Number(point.tx || 0) / divisor,
            Number(point.rx || 0) / divisor
        ]));

        const dashboard = new google.visualization.Dashboard(document.getElementById('dashboard_div'));
        const chart = new google.visualization.ChartWrapper({
            chartType: 'LineChart',
            containerId: 'chart_div',
            options: {
                legend: { position: 'top' },
                height: 380,
                chartArea: { left: 60, right: 20, top: 48, bottom: 60 },
                colors: ['#1f7a55', '#14513a'],
                hAxis: {
                    minValue: new Date(windowInfo.start.replace(' ', 'T')),
                    maxValue: new Date(windowInfo.end.replace(' ', 'T'))
                }
            }
        });

        const control = new google.visualization.ControlWrapper({
            controlType: 'ChartRangeFilter',
            containerId: 'filter_div',
            options: {
                filterColumnIndex: 0,
                ui: {
                    chartType: 'LineChart',
                    chartOptions: {
                        height: 96,
                        chartArea: { left: 60, right: 20, top: 8, bottom: 24 },
                        colors: ['#9bcdb8', '#a6b9ae']
                    }
                }
            }
        });

        dashboard.bind(control, chart);
        dashboard.draw(data);
    });
}

window.addEventListener('popstate', () => {
    Object.assign(state, readStateFromUrl());
    render();
});

render();
