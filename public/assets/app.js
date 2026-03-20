const app = document.getElementById('app');
const state = readStateFromUrl();
let deviceListCache = [];
const listWindowByDevice = loadJsonStorage('mikstat.listWindowByDevice', {});
let globalSettings = {
    theme_mode: 'auto'
};
let themeMediaQuery = null;
const themeModeSelector = document.getElementById('themeModeSelector');
const openGlobalSettingsButton = document.getElementById('openGlobalSettings');
const headerBackButton = document.getElementById('headerBackButton');
const headerHomeButton = document.getElementById('headerHomeButton');

function readStateFromUrl() {
    const params = new URLSearchParams(window.location.search);
    return {
        deviceId: params.get('id'),
        settingsDeviceId: params.get('settings_id'),
        appSettings: params.get('settings') === 'app',
        interfaceId: params.get('interface_id'),
        windowHours: parseInt(params.get('window') || '48', 10) || 48,
        offset: parseInt(params.get('offset') || '0', 10) || 0,
        unit: params.get('unit') || 'GB'
    };
}

function persistState(replace = false) {
    const params = new URLSearchParams();
    if (state.deviceId) params.set('id', state.deviceId);
    if (state.settingsDeviceId) params.set('settings_id', state.settingsDeviceId);
    if (state.appSettings) params.set('settings', 'app');
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
        settingsDeviceId: null,
        appSettings: false,
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

function loadJsonStorage(key, fallback) {
    try {
        const value = window.localStorage.getItem(key);
        return value ? JSON.parse(value) : fallback;
    } catch {
        return fallback;
    }
}

function saveJsonStorage(key, value) {
    try {
        window.localStorage.setItem(key, JSON.stringify(value));
    } catch {
        // Ignore storage failures.
    }
}

function parseSqlDate(value) {
    if (!value) {
        return null;
    }

    const date = new Date(String(value).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? null : date;
}

function formatTimestamp(value) {
    if (!value) return 'never';
    const date = parseSqlDate(value);
    if (!date) {
        return String(value);
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23'
    }).format(date);
}

function formatDateOnly(value) {
    const date = parseSqlDate(value);
    if (!date) {
        return String(value || '');
    }

    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    }).format(date);
}

function formatDateRange(fromValue, toValue) {
    if (!fromValue || !toValue) {
        return '';
    }

    const fromDate = parseSqlDate(fromValue);
    const toDate = parseSqlDate(toValue);

    if (!fromDate || !toDate) {
        return `${fromValue} to ${toValue}`;
    }

    const sameDay = fromDate.getFullYear() === toDate.getFullYear()
        && fromDate.getMonth() === toDate.getMonth()
        && fromDate.getDate() === toDate.getDate();

    if (sameDay) {
        return formatDateOnly(fromValue);
    }

    return `${formatDateOnly(fromValue)} to ${formatDateOnly(toValue)}`;
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
        await ensureGlobalSettingsLoaded();
        syncHeaderNavigation();

        if (state.appSettings) {
            renderGlobalSettingsPage();
            return;
        }

        if (state.settingsDeviceId) {
            const settings = await fetchJson({
                action: 'getDeviceSettings',
                id: state.settingsDeviceId
            });
            renderDeviceSettings(settings);
            return;
        }

        if (!state.deviceId) {
            const devices = await fetchJson({ action: 'getDevices' });
            deviceListCache = devices.map((device) => ({
                ...device
            }));
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
        syncHeaderNavigation();
        app.innerHTML = `
            <div class="error-box">${escapeHtml(error instanceof Error ? error.message : String(error))}</div>
            <div class="button-row">
                <button class="btn-secondary" data-action="back-to-list">Back to list</button>
            </div>
        `;
        bindGlobalActions();
    }
}

function syncHeaderNavigation() {
    const showBackButton = Boolean(state.deviceId || state.settingsDeviceId || state.appSettings);
    if (headerBackButton) {
        headerBackButton.hidden = !showBackButton;
    }
}

async function ensureGlobalSettingsLoaded() {
    const settings = await fetchJson({ action: 'getGlobalSettings' });
    globalSettings = settings;
    applyThemeMode(settings.theme_mode || 'auto');
    syncThemeSelector();
}

function applyThemeMode(themeMode) {
    const root = document.documentElement;
    const mode = ['light', 'dark', 'auto'].includes(themeMode) ? themeMode : 'auto';

    if (themeMediaQuery === null && window.matchMedia) {
        themeMediaQuery = window.matchMedia('(prefers-color-scheme: dark)');
        if (typeof themeMediaQuery.addEventListener === 'function') {
            themeMediaQuery.addEventListener('change', () => {
                if ((globalSettings.theme_mode || 'auto') === 'auto') {
                    applyThemeMode('auto');
                }
            });
        } else if (typeof themeMediaQuery.addListener === 'function') {
            themeMediaQuery.addListener(() => {
                if ((globalSettings.theme_mode || 'auto') === 'auto') {
                    applyThemeMode('auto');
                }
            });
        }
    }

    const effectiveMode = mode === 'auto' && themeMediaQuery?.matches ? 'dark' : mode === 'auto' ? 'light' : mode;
    root.setAttribute('data-theme', effectiveMode);
    root.style.colorScheme = effectiveMode;
}

function syncThemeSelector() {
    if (themeModeSelector) {
        themeModeSelector.value = globalSettings.theme_mode || 'auto';
    }

    const pageSelector = document.getElementById('globalThemeMode');
    if (pageSelector) {
        pageSelector.value = globalSettings.theme_mode || 'auto';
    }
}

function getCssVariable(name, fallback = '') {
    const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
    return value || fallback;
}

function renderDeviceList(devices) {
    if (!devices.length) {
        app.innerHTML = '<div class="empty">No devices have reported traffic yet.</div>';
        return;
    }

    const items = devices.map((device) => {
        const selectedWindow = normalizeListWindow(listWindowByDevice[device.id] || device.overview_label || '3');
        const selectedScope = normalizeListScope(device.home_scope || 'all');
        const selectedInterfaceId = String(device.home_interface_id || '');
        const interfaces = Array.isArray(device.interfaces) ? device.interfaces : [];
        const interfaceList = interfaces.length
            ? interfaces.map((item) => `<span class="interface-chip">${escapeHtml(item.display_name || item.name)}</span>`).join('')
            : '<span class="meta">No interfaces yet.</span>';
        return `
        <div class="device-item" data-device-card="${device.id}">
            <div class="device-item-head">
                <div>
                    <div class="device-item-title-row">
                        <button class="device-link" data-open-device="${device.id}">
                            ${escapeHtml(device.name || device.sn)}
                        </button>
                        <button class="btn-secondary btn-open-device" data-open-device="${device.id}" type="button">Open details</button>
                    </div>
                    ${device.name ? `<div class="meta">Serial: ${escapeHtml(device.sn)}</div>` : ''}
                    ${device.comment ? `<div class="meta">${escapeHtml(device.comment)}</div>` : ''}
                </div>
                <div class="device-card-actions">
                    <div class="meta">Last check: ${escapeHtml(formatTimestamp(device.last_check))}</div>
                    <div class="device-order-buttons">
                        <button class="btn-secondary btn-icon" data-device-move="up" data-device-id="${device.id}" type="button" aria-label="Move device up">↑</button>
                        <button class="btn-secondary btn-icon" data-device-move="down" data-device-id="${device.id}" type="button" aria-label="Move device down">↓</button>
                    </div>
                </div>
            </div>
            <div class="device-list-grid">
                <div class="summary-tile traffic-combined">
                    <strong>Latest Counters</strong>
                    <div class="traffic-pair"><span>Upload</span><span>${escapeHtml(formatTraffic(device.last_tx, state.unit))}</span></div>
                    <div class="traffic-pair"><span>Download</span><span>${escapeHtml(formatTraffic(device.last_rx, state.unit))}</span></div>
                    <div class="interface-list-block">
                        <strong>Interfaces</strong>
                        <div class="interface-chip-list">${interfaceList}</div>
                    </div>
                </div>
                <div class="mini-chart-card">
                    <div class="mini-chart-head">
                        <div class="mini-chart-caption">Traffic overview</div>
                        <div class="mini-controls">
                            <div class="mini-scope-switch" role="tablist" aria-label="Device overview scope">
                                <button class="${selectedScope === 'all' ? 'is-active' : ''}" data-device-scope="all" data-device-id="${device.id}" type="button">All</button>
                                <button class="${selectedScope === 'single' ? 'is-active' : ''}" data-device-scope="single" data-device-id="${device.id}" type="button">Single</button>
                            </div>
                            ${selectedScope === 'single' ? `
                                <select class="mini-interface-select" data-device-interface="${device.id}">
                                    ${interfaces.map((item) => `
                                        <option value="${item.id}" ${String(item.id) === selectedInterfaceId ? 'selected' : ''}>${escapeHtml(item.display_name || item.name)}</option>
                                    `).join('')}
                                </select>
                            ` : ''}
                        </div>
                        <div class="mini-window-switch mini-window-switch-inline" role="tablist" aria-label="Device overview window">
                            ${[
                                ['1', '1h'],
                                ['3', '3h'],
                                ['6', '6h'],
                                ['12', '12h'],
                                ['today', 'Today']
                            ].map(([value, label]) => `
                                <button class="${selectedWindow === value ? 'is-active' : ''}" data-device-window="${value}" data-device-id="${device.id}" type="button">${label}</button>
                            `).join('')}
                        </div>
                    </div>
                    <div class="mini-chart" data-sparkline='${escapeHtml(JSON.stringify(device.overview_chart_data || []))}' data-device-id="${device.id}"></div>
                </div>
            </div>
        </div>
    `;
    }).join('');

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

    app.querySelectorAll('[data-device-window]').forEach((button) => {
        button.addEventListener('click', async () => {
            const deviceId = button.getAttribute('data-device-id');
            const windowValue = normalizeListWindow(button.getAttribute('data-device-window'));
            if (!deviceId) {
                return;
            }

            listWindowByDevice[deviceId] = windowValue;
            saveJsonStorage('mikstat.listWindowByDevice', listWindowByDevice);

            try {
                await refreshDeviceOverview(deviceId);
            } catch (error) {
                const card = app.querySelector(`[data-device-card="${deviceId}"] .mini-chart`);
                if (card) {
                    card.innerHTML = `<div class="meta">${escapeHtml(error instanceof Error ? error.message : String(error))}</div>`;
                }
            }
        });
    });

    app.querySelectorAll('[data-device-move]').forEach((button) => {
        button.addEventListener('click', async () => {
            const deviceId = button.getAttribute('data-device-id');
            const direction = button.getAttribute('data-device-move');
            if (!deviceId || !direction) {
                return;
            }

            await fetchJson({
                action: 'moveDevice',
                id: String(deviceId),
                direction
            });

            const devices = await fetchJson({ action: 'getDevices' });
            deviceListCache = devices.map((device) => ({ ...device }));
            renderDeviceList(deviceListCache);
        });
    });

    app.querySelectorAll('[data-device-scope]').forEach((button) => {
        button.addEventListener('click', async () => {
            const deviceId = button.getAttribute('data-device-id');
            const scope = normalizeListScope(button.getAttribute('data-device-scope'));
            if (!deviceId) {
                return;
            }

            const device = deviceListCache.find((item) => String(item.id) === String(deviceId));
            const interfaceId = scope === 'single'
                ? String(device?.home_interface_id || device?.interfaces?.[0]?.id || '')
                : '';

            if (scope === 'single' && !interfaceId) {
                return;
            }

            const updatedDevice = await fetchJson({
                action: 'updateDevice',
                id: String(deviceId),
                home_scope: scope,
                home_interface_id: interfaceId
            });
            if (device) {
                device.home_scope = updatedDevice.home_scope;
                device.home_interface_id = updatedDevice.home_interface_id;
            }
            await refreshDeviceOverview(deviceId);
        });
    });

    app.querySelectorAll('[data-device-interface]').forEach((select) => {
        select.addEventListener('change', async () => {
            const deviceId = select.getAttribute('data-device-interface');
            if (!deviceId) {
                return;
            }

            const updatedDevice = await fetchJson({
                action: 'updateDevice',
                id: String(deviceId),
                home_scope: 'single',
                home_interface_id: select.value
            });
            const device = deviceListCache.find((item) => String(item.id) === String(deviceId));
            if (device) {
                device.home_scope = updatedDevice.home_scope;
                device.home_interface_id = updatedDevice.home_interface_id;
            }
            await refreshDeviceOverview(deviceId);
        });
    });

    app.querySelectorAll('[data-sparkline]').forEach((node) => {
        const raw = node.getAttribute('data-sparkline') || '[]';
        try {
            renderSparkline(node, JSON.parse(raw));
        } catch {
            node.innerHTML = '<div class="meta">Graph unavailable.</div>';
        }
    });
}

function renderSparkline(container, chartData) {
    if (!Array.isArray(chartData) || chartData.length === 0) {
        container.innerHTML = '<div class="meta">No recent data.</div>';
        return;
    }

    const width = 300;
    const height = 92;
    const padding = 8;
    const plotWidth = width - (padding * 2);
    const txColor = getCssVariable('--spark-tx', '#1b63d8');
    const rxColor = getCssVariable('--spark-rx', '#c63b4f');
    const gridColor = getCssVariable('--spark-grid', '#dce6df');
    const verticalColor = getCssVariable('--spark-vertical', '#eef3ef');
    const axisColor = getCssVariable('--spark-axis', '#d9e2dd');
    const values = chartData.flatMap((point) => [Number(point.tx || 0), Number(point.rx || 0)]);
    const maxValue = Math.max(...values, 0);

    if (maxValue <= 0) {
        container.innerHTML = '<div class="meta">No recent traffic.</div>';
        return;
    }

    const xFor = (index) => {
        if (chartData.length === 1) {
            return width / 2;
        }

        return padding + (index * plotWidth) / (chartData.length - 1);
    };
    const yFor = (value) => {
        const usableHeight = height - (padding * 2);
        return height - padding - ((Number(value || 0) / maxValue) * usableHeight);
    };

    const buildPolyline = (key) => chartData.map((point, index) => `${xFor(index)},${yFor(point[key] || 0)}`).join(' ');
    const txPoints = buildPolyline('tx');
    const rxPoints = buildPolyline('rx');
    const txValues = chartData.map((point) => Number(point.tx || 0));
    const rxValues = chartData.map((point) => Number(point.rx || 0));
    const txMax = Math.max(...txValues);
    const rxMax = Math.max(...rxValues);
    const txTotal = txValues.reduce((sum, value) => sum + value, 0);
    const rxTotal = rxValues.reduce((sum, value) => sum + value, 0);
    const gridLines = [0.25, 0.5, 0.75].map((ratio) => {
        const y = padding + ((height - (padding * 2)) * ratio);
        return `<line x1="${padding}" y1="${y}" x2="${width - padding}" y2="${y}" stroke="${gridColor}" stroke-width="1"></line>`;
    }).join('');
    const verticalLines = chartData.length > 1 ? chartData.map((point, index) => {
        if (index === 0 || index === chartData.length - 1) {
            return '';
        }
        const x = xFor(index);
        return `<line x1="${x}" y1="${padding}" x2="${x}" y2="${height - padding}" stroke="${verticalColor}" stroke-width="1"></line>`;
    }).join('') : '';
    const txDots = chartData.map((point, index) => `
        <circle cx="${xFor(index)}" cy="${yFor(point.tx || 0)}" r="2.5" fill="${txColor}"></circle>
    `).join('');
    const rxDots = chartData.map((point, index) => `
        <circle cx="${xFor(index)}" cy="${yFor(point.rx || 0)}" r="2.5" fill="${rxColor}"></circle>
    `).join('');
    const hitAreas = chartData.map((point, index) => {
        const timestamp = escapeHtml(formatTimestamp(point.hour));
        const txLabel = escapeHtml(formatTraffic(point.tx, state.unit));
        const rxLabel = escapeHtml(formatTraffic(point.rx, state.unit));
        return `
            <circle
                class="sparkline-hit"
                cx="${xFor(index)}"
                cy="${Math.min(yFor(point.tx || 0), yFor(point.rx || 0))}"
                r="10"
                fill="transparent"
                data-tooltip-title="${timestamp}"
                data-tooltip-tx="${txLabel}"
                data-tooltip-rx="${rxLabel}"
            ></circle>
        `;
    }).join('');
    const timelineLabels = buildTimelineLabels(chartData);

    container.innerHTML = `
        <div class="sparkline-body">
            <div class="sparkline-visual">
                <div class="sparkline-frame">
                    <div class="sparkline-tooltip" hidden></div>
                    <svg viewBox="0 0 ${width} ${height}" class="sparkline-svg" aria-hidden="true">
                        ${gridLines}
                        ${verticalLines}
                        <line x1="${padding}" y1="${height - padding}" x2="${width - padding}" y2="${height - padding}" stroke="${axisColor}" stroke-width="1"></line>
                        <polyline fill="none" stroke="${txColor}" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" points="${txPoints}"></polyline>
                        <polyline fill="none" stroke="${rxColor}" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round" points="${rxPoints}"></polyline>
                        ${txDots}
                        ${rxDots}
                        ${hitAreas}
                    </svg>
                    <div class="sparkline-timeline" style="margin-left:${padding}px; width:${plotWidth}px;">
                        ${timelineLabels.map((label) => `<span>${escapeHtml(label)}</span>`).join('')}
                    </div>
                </div>
            </div>
            <div class="sparkline-totals">
                <div class="sparkline-total-block">
                    <strong>Total up</strong>
                    <span>${escapeHtml(formatTraffic(txTotal, state.unit))}</span>
                </div>
                <div class="sparkline-total-block">
                    <strong>Total down</strong>
                    <span>${escapeHtml(formatTraffic(rxTotal, state.unit))}</span>
                </div>
                <div class="sparkline-total-block">
                    <strong>Max up</strong>
                    <span>${escapeHtml(formatTraffic(txMax, state.unit))}</span>
                </div>
                <div class="sparkline-total-block">
                    <strong>Max down</strong>
                    <span>${escapeHtml(formatTraffic(rxMax, state.unit))}</span>
                </div>
            </div>
        </div>
        <div class="sparkline-legend">
            <span><i class="swatch tx"></i>Upload</span>
            <span><i class="swatch rx"></i>Download</span>
        </div>
    `;

    const tooltip = container.querySelector('.sparkline-tooltip');
    const hitNodes = container.querySelectorAll('.sparkline-hit');
    const tooltipFrame = container.querySelector('.sparkline-frame');

    hitNodes.forEach((node) => {
        node.addEventListener('mouseenter', () => {
            if (!tooltip) {
                return;
            }

            tooltip.hidden = false;
            tooltip.innerHTML = `
                <div>${escapeHtml(node.getAttribute('data-tooltip-title') || '')}</div>
                <div class="sparkline-tooltip-line tx">Upload: ${escapeHtml(node.getAttribute('data-tooltip-tx') || '')}</div>
                <div class="sparkline-tooltip-line rx">Download: ${escapeHtml(node.getAttribute('data-tooltip-rx') || '')}</div>
            `;
        });

        node.addEventListener('mousemove', (event) => {
            if (!tooltip || !tooltipFrame) {
                return;
            }

            const bounds = tooltipFrame.getBoundingClientRect();
            const left = event.clientX - bounds.left;
            const top = event.clientY - bounds.top - 8;
            tooltip.style.left = `${left}px`;
            tooltip.style.top = `${top}px`;
        });

        node.addEventListener('mouseleave', () => {
            if (tooltip) {
                tooltip.hidden = true;
            }
        });
    });
}

function buildTimelineLabels(chartData) {
    if (!Array.isArray(chartData) || chartData.length === 0) {
        return [];
    }

    const indices = chartData.length === 1
        ? [0]
        : Array.from(new Set([
            0,
            Math.max(0, Math.floor((chartData.length - 1) / 2)),
            chartData.length - 1
        ]));

    return indices.map((index) => formatTimelineLabel(chartData[index]?.hour || ''));
}

function formatTimelineLabel(value) {
    if (!value) {
        return '';
    }

    const date = parseSqlDate(value);
    if (!date) {
        return String(value);
    }

    return new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23'
    }).format(date);
}

function normalizeListWindow(value) {
    const normalized = String(value || '3').toLowerCase();
    return ['1', '3', '6', '12', 'today'].includes(normalized) ? normalized : '3';
}

function normalizeListScope(value) {
    return String(value || 'all').toLowerCase() === 'single' ? 'single' : 'all';
}

async function refreshDeviceOverview(deviceId) {
    const windowValue = normalizeListWindow(listWindowByDevice[deviceId] || '3');
    const device = deviceListCache.find((item) => String(item.id) === String(deviceId));
    if (!device) {
        return;
    }

    const scope = normalizeListScope(device.home_scope || 'all');
    const request = {
        action: 'getDeviceOverview',
        id: deviceId,
        overview_window: windowValue
    };

    if (scope === 'single' && device.home_interface_id) {
        request.interface_id = device.home_interface_id;
    }

    const overview = await fetchJson(request);

    device.overview_chart_data = overview.overview_chart_data || [];
    device.overview_label = overview.overview_label || windowValue;
    if (overview.last_counters) {
        device.last_tx = overview.last_counters.last_tx || 0;
        device.last_rx = overview.last_counters.last_rx || 0;
    }
    renderDeviceList(deviceListCache);
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
        <div class="device-summary detail-layout">
            <div class="panel">
                <div class="panel-header">
                    <div class="detail-title-row">
                        <h2 class="panel-title">${escapeHtml(device.name || device.sn)}</h2>
                        <button class="btn-secondary" data-open-settings="${device.id}" type="button">Open settings</button>
                    </div>
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
                    </div>
                </div>
            </div>

            <div class="panel chart-card">
                <div class="panel-header">
                    <h2 class="panel-title">Traffic Chart</h2>
                    <p class="panel-subtitle">${escapeHtml(detail.window.offset === 0 ? `Last ${detail.window.length} hours` : formatDateRange(detail.window.start, detail.window.end))}</p>
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
    `;

    bindGlobalActions();
    bindDetailActions(device.id);
    drawChart(detail.chartData, detail.window, state.unit);
}

function renderDeviceSettings(payload) {
    const device = payload.device;
    const interfaces = payload.interfaces || [];

    app.innerHTML = `
        <div class="split">
            <div class="device-summary">
                <div class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">Device Settings</h2>
                        <p class="panel-subtitle">${escapeHtml(device.name || device.sn)} · Serial ${escapeHtml(device.sn || device.serial_number)}</p>
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
                            <div>
                                <label for="homeScope">Home page mode</label>
                                <select id="homeScope" name="home_scope">
                                    <option value="all" ${device.home_scope === 'all' ? 'selected' : ''}>All interfaces</option>
                                    <option value="single" ${device.home_scope === 'single' ? 'selected' : ''}>Single interface</option>
                                </select>
                            </div>
                            <div id="homeInterfaceRow" style="${device.home_scope === 'single' ? '' : 'display: none;'}">
                                <label for="homeInterfaceId">Home page interface</label>
                                <select id="homeInterfaceId" name="home_interface_id">
                                    ${interfaces.map((item) => `
                                        <option value="${item.id}" ${String(item.id) === String(device.home_interface_id || '') ? 'selected' : ''}>${escapeHtml(item.display_name || item.name)}</option>
                                    `).join('')}
                                </select>
                            </div>
                            <div class="button-row">
                                <button type="submit" class="btn-primary">Save device</button>
                                <button type="button" class="btn-secondary" data-open-device="${device.id}">Back to detail</button>
                                <button type="button" class="btn-danger" id="deleteDeviceBtn">Delete device</button>
                            </div>
                            <div id="formStatus" class="meta"></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    `;

    bindGlobalActions();
    bindSettingsActions(device.id);
}

function renderGlobalSettingsPage() {
    app.innerHTML = `
        <div class="split">
            <div class="device-summary">
                <div class="panel">
                    <div class="panel-header">
                        <h2 class="panel-title">App Settings</h2>
                        <p class="panel-subtitle">Global preferences shared by the whole app instance.</p>
                    </div>
                    <div class="panel-body">
                        <form id="globalSettingsForm" class="form-grid">
                            <div>
                                <label for="globalThemeMode">Theme mode</label>
                                <select id="globalThemeMode" name="theme_mode">
                                    <option value="auto" ${globalSettings.theme_mode === 'auto' ? 'selected' : ''}>Auto</option>
                                    <option value="light" ${globalSettings.theme_mode === 'light' ? 'selected' : ''}>Light</option>
                                    <option value="dark" ${globalSettings.theme_mode === 'dark' ? 'selected' : ''}>Dark</option>
                                </select>
                            </div>
                            <div class="button-row">
                                <button type="submit" class="btn-primary">Save settings</button>
                                <button type="button" class="btn-secondary" data-action="back-to-list">Back to list</button>
                            </div>
                            <div id="globalSettingsStatus" class="meta"></div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    `;

    bindGlobalActions();
    bindAppSettingsActions();
    syncThemeSelector();
}

function renderStatCard(title, payload, unit, includeRange = true) {
    const stats = payload.data;
    const total = Number(stats.sumtx || 0) + Number(stats.sumrx || 0);
    const range = includeRange ? `<div class="meta">${escapeHtml(formatDateRange(payload.range.from, payload.range.to))}</div>` : '';

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

    app.querySelectorAll('[data-open-settings]').forEach((button) => {
        button.addEventListener('click', () => {
            navigate({
                settingsDeviceId: button.getAttribute('data-open-settings'),
                deviceId: null,
                interfaceId: null
            });
        });
    });

    app.querySelectorAll('[data-open-device]').forEach((button) => {
        if (button.closest('.device-item')) {
            return;
        }

        button.addEventListener('click', () => {
            navigate({
                deviceId: button.getAttribute('data-open-device'),
                settingsDeviceId: null,
                interfaceId: null,
                offset: 0
            });
        });
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

function bindHeaderActions() {
    headerHomeButton?.addEventListener('click', () => {
        resetToList();
    });

    headerBackButton?.addEventListener('click', () => {
        resetToList();
    });

    openGlobalSettingsButton?.addEventListener('click', () => {
        navigate({
            appSettings: true,
            deviceId: null,
            settingsDeviceId: null,
            interfaceId: null,
            offset: 0
        });
    });

    themeModeSelector?.addEventListener('change', async (event) => {
        const nextThemeMode = event.target.value || 'auto';
        const previousThemeMode = globalSettings.theme_mode || 'auto';

        try {
            globalSettings = await fetchJson({
                action: 'updateGlobalSettings',
                theme_mode: nextThemeMode
            });
            applyThemeMode(globalSettings.theme_mode || 'auto');
            syncThemeSelector();
        } catch (error) {
            globalSettings.theme_mode = previousThemeMode;
            applyThemeMode(previousThemeMode);
            syncThemeSelector();
            window.alert(error instanceof Error ? error.message : String(error));
        }
    });
}

function bindSettingsActions(deviceId) {
    document.getElementById('homeScope')?.addEventListener('change', (event) => {
        const row = document.getElementById('homeInterfaceRow');
        if (row) {
            row.style.display = event.target.value === 'single' ? '' : 'none';
        }
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
                comment: String(form.get('comment') || ''),
                home_scope: String(form.get('home_scope') || 'all'),
                home_interface_id: form.get('home_scope') === 'single' ? String(form.get('home_interface_id') || '') : ''
            });
            statusNode.textContent = 'Saved.';
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

function bindAppSettingsActions() {
    document.getElementById('globalSettingsForm')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const statusNode = document.getElementById('globalSettingsStatus');
        const form = new FormData(event.currentTarget);
        const themeMode = String(form.get('theme_mode') || 'auto');

        try {
            statusNode.textContent = 'Saving...';
            globalSettings = await fetchJson({
                action: 'updateGlobalSettings',
                theme_mode: themeMode
            });
            applyThemeMode(globalSettings.theme_mode || 'auto');
            syncThemeSelector();
            statusNode.textContent = 'Saved.';
        } catch (error) {
            statusNode.textContent = error instanceof Error ? error.message : String(error);
        }
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
        const rootTheme = document.documentElement.getAttribute('data-theme') || 'light';
        const chartText = getCssVariable('--text', '#16221b');
        const chartMuted = getCssVariable('--muted', '#607064');
        const chartLine = getCssVariable('--line', '#d4ddd5');
        const chartPanel = getCssVariable('--panel', '#ffffff');
        const txColor = getCssVariable('--spark-tx', '#1b63d8');
        const rxColor = getCssVariable('--spark-rx', '#c63b4f');
        const data = new google.visualization.DataTable();
        data.addColumn('datetime', 'Timestamp');
        data.addColumn('number', `TX (${unit})`);
        data.addColumn({ type: 'string', role: 'tooltip', p: { html: true } });
        data.addColumn('number', `RX (${unit})`);
        data.addColumn({ type: 'string', role: 'tooltip', p: { html: true } });

        const divisor = {
            MB: 1024 ** 2,
            GB: 1024 ** 3,
            TB: 1024 ** 4,
            PB: 1024 ** 5,
            EB: 1024 ** 6
        }[unit] || 1024 ** 3;

        data.addRows(chartData.map((point) => {
            const timestamp = formatTimestamp(point.hour);
            const tx = Number(point.tx || 0) / divisor;
            const rx = Number(point.rx || 0) / divisor;
            const tooltip = `
                <div class="chart-tooltip">
                    <div class="chart-tooltip-title">${escapeHtml(timestamp)}</div>
                    <div class="chart-tooltip-line tx">Upload: ${escapeHtml(formatTraffic(point.tx || 0, unit))}</div>
                    <div class="chart-tooltip-line rx">Download: ${escapeHtml(formatTraffic(point.rx || 0, unit))}</div>
                </div>
            `;

            return [
                new Date(String(point.hour).replace(' ', 'T')),
                tx,
                tooltip,
                rx,
                tooltip
            ];
        }));

        const dashboard = new google.visualization.Dashboard(document.getElementById('dashboard_div'));
        const chart = new google.visualization.ChartWrapper({
            chartType: 'LineChart',
            containerId: 'chart_div',
            options: {
                legend: { position: 'top' },
                backgroundColor: chartPanel,
                height: 380,
                pointSize: 5,
                lineWidth: 2,
                chartArea: { left: 60, right: 20, top: 48, bottom: 60 },
                colors: [txColor, rxColor],
                legendTextStyle: { color: chartText },
                hAxis: {
                    minValue: new Date(windowInfo.start.replace(' ', 'T')),
                    maxValue: new Date(windowInfo.end.replace(' ', 'T')),
                    textStyle: { color: chartMuted },
                    gridlines: { color: chartLine },
                    baselineColor: chartLine
                },
                vAxis: {
                    textStyle: { color: chartMuted },
                    gridlines: { color: chartLine },
                    baselineColor: chartLine
                },
                explorer: { axis: 'horizontal', keepInBounds: true },
                tooltip: {
                    isHtml: true,
                    textStyle: { color: chartText }
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
                        backgroundColor: chartPanel,
                        height: 96,
                        chartArea: { left: 60, right: 20, top: 8, bottom: 24 },
                        colors: [txColor, rxColor],
                        hAxis: {
                            textStyle: { color: chartMuted },
                            gridlines: { color: rootTheme === 'dark' ? chartPanel : chartLine },
                            baselineColor: chartLine
                        },
                        vAxis: {
                            textStyle: { color: chartMuted },
                            gridlines: { color: rootTheme === 'dark' ? chartPanel : chartLine },
                            baselineColor: chartLine
                        }
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

bindHeaderActions();
render();
