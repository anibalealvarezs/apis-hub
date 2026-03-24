/** 
 * APIs Hub | Facebook Reports View Logic 🛡️💹
 * Specialized Metrics Analytics Engine for Meta Marketing.
 */

// Global state
let currentData = [];
let sortConfig = { key: 'campaign', dir: 'asc' };
const window_NESTED_DATA_CACHE = {};

const HIERARCHY = {
    campaign: { 
        next: 'adset', 
        label: 'Campaign', 
        icon: 'activity', 
        color: '#1877F2',
        idField: 'channeledCampaign_id',
        nameField: 'campaign',
        filterKey: 'channeledCampaign'
    },
    adset: { 
        next: 'ad', 
        label: 'AdSet', 
        icon: 'layers', 
        color: '#8b5cf6',
        idField: 'adGroup_id',
        nameField: 'adGroup',
        filterKey: 'adGroup'
    },
    ad: { 
        next: 'creative', 
        label: 'Ad', 
        icon: 'megaphone', 
        color: '#ec4899',
        idField: 'ad_id',
        nameField: 'ad',
        filterKey: 'ad'
    },
    creative: { 
        next: null, 
        label: 'Creative', 
        icon: 'eye', 
        color: '#3fb950',
        idField: 'creative_id',
        nameField: 'creative'
    }
};

const DIM_CONFIGS = {
    audience: { dims: ['age', 'gender'], label: 'Age & Gender' },
    placement: { dims: ['publisher_platform', 'platform_position'], label: 'Placement' }
};

function canDisaggregate(toLevel) {
    const config = window.FB_METRICS_CONFIG || { metrics_level: 'creative' };
    const maxLevel = config.metrics_level || 'creative';
    const order = ['ad_account', 'campaign', 'adset', 'ad', 'creative'];
    const maxIdx = order.indexOf(maxLevel);
    const toIdx = order.indexOf(toLevel);
    return toIdx <= maxIdx;
}

function getSparkId(level, key, entityId, idx) {
    const sanitizedId = String(entityId || idx).replace(/[^a-z0-9\-]/gi, '-').toLowerCase();
    return `spark-${level}-${key}-${sanitizedId}`.toLowerCase();
}

function getActiveMetrics() {
    const config = window.FB_METRICS_CONFIG || {};
    const strategy = config.strategy || 'default';
    const metricConfigs = config.metrics_config || {};
    
    const standardMetrics = [
        { key: 'total_spend', label: 'SPEND', format: 'currency', precision: 2, original: 'spend' },
        { key: 'total_clicks', label: 'CLKS', format: 'number', precision: 0, original: 'clicks' },
        { key: 'total_impressions', label: 'IMPR', format: 'number', precision: 0, original: 'impressions' },
        { key: 'total_reach', label: 'REACH', format: 'number', precision: 0, original: 'reach' },
        { key: 'average_frequency', label: 'FREQ', format: 'number', precision: 2, original: 'frequency' },
        { key: 'average_ctr', label: 'CTR', format: 'percent', precision: 2, original: 'ctr' },
        { key: 'average_cpc', label: 'CPC', format: 'currency', precision: 2, original: 'cpc' },
        { key: 'average_cpm', label: 'CPM', format: 'currency', precision: 2, original: 'cpm' },
        { key: 'total_results', label: 'RES', format: 'number', precision: 0, original: 'results' },
        { key: 'average_cost_per_result', label: 'CPR', format: 'currency', precision: 2, original: 'cost_per_result' },
        { key: 'average_result_rate', label: 'R%', format: 'percent', precision: 2, original: 'result_rate' },
        { key: 'average_purchase_roas', label: 'ROAS', format: 'number', precision: 2, original: 'purchase_roas' },
        { key: 'total_actions', label: 'ACT', format: 'number', precision: 0, original: 'actions' }
    ];

    if (strategy === 'default') {
        return standardMetrics.map(m => ({ ...m, config: { enabled: true, precision: m.precision, sparkline: ['total_spend', 'total_clicks', 'total_impressions'].includes(m.key) } }));
    }

    // Custom strategy
    return standardMetrics.filter(m => {
        const slug = m.original;
        return metricConfigs[slug]?.enabled === true;
    }).map(m => {
        const slug = m.original;
        const cfg = metricConfigs[slug];
        return { 
            ...m, 
            format: cfg.format || m.format,
            precision: cfg.precision !== undefined ? cfg.precision : m.precision,
            config: cfg 
        };
    });
}

// --- Initialization ---
function initDashboard() {
    lucide.createIcons();
    
    const flatpickrConfig = {
        dateFormat: "Y-m-d",
        altInput: true,
        altFormat: "F j, Y",
        animate: true,
        disableMobile: "true"
    };

    const yesterday = dayjs().subtract(1, 'day').format('YYYY-MM-DD');
    const lastWeek = dayjs().subtract(8, 'day').format('YYYY-MM-DD');

    flatpickr("#startDate", {
        ...flatpickrConfig,
        defaultDate: lastWeek
    });

    flatpickr("#endDate", {
        ...flatpickrConfig,
        defaultDate: yesterday,
        maxDate: yesterday
    });

    const headers = getAdminHeaders();
    if (headers.Authorization) loadReport();
}

function getAdminHeaders() {
    const envMeta = document.querySelector('meta[name="app-env"]');
    const isDemo = (envMeta && envMeta.getAttribute('content') === 'demo') || window.AUTH_BYPASS === true;
    const EXPIRATION_TIME = 24 * 60 * 60 * 1000;
    const now = Date.now();
    let auth = JSON.parse(localStorage.getItem('apis_hub_admin_auth') || '{}');
    if (isDemo || window.AUTH_BYPASS) return { 'Authorization': 'Bearer DEMO_BYPASS', 'Content-Type': 'application/json' };
    if (!auth.token || (now - auth.timestamp > EXPIRATION_TIME)) {
        const token = prompt('ADMIN ACCESS REQUIRED: Please enter your administrative API key to continue.');
        if (token) { auth = { token, timestamp: now }; localStorage.setItem('apis_hub_admin_auth', JSON.stringify(auth)); }
        else return {};
    }
    return { 'Authorization': 'Bearer ' + auth.token, 'Content-Type': 'application/json' };
}

async function forceRefresh() {
    if (!confirm("This will clear the aggregation cache for Facebook Marketing and reload. Continue?")) return;
    const loader = document.getElementById('loader');
    if (loader) loader.style.display = 'flex';
    try {
        const response = await fetch('/api/config-manager/flush-cache', { 
            method: 'POST', headers: getAdminHeaders(), body: JSON.stringify({ channel: 'facebook_marketing' })
        });
        const result = await response.json();
        if (result.success) loadReport();
        else alert(result.error || 'Flush failed');
    } catch (err) { alert(err.message); }
    finally { if (loader) loader.style.display = 'none'; }
}

async function loadReport() {
    const start = document.getElementById('startDate').value;
    const end = document.getElementById('endDate').value;
    const loader = document.getElementById('loader');
    const emptyMsg = document.getElementById('empty-msg');
    const cacheBadge = document.getElementById('cache-badge');
    if (start > end) { alert("The start date cannot be later than the end date."); return; }
    const headers = getAdminHeaders();
    if (!headers.Authorization) return;
    if (loader) loader.style.display = 'flex';
    if (emptyMsg) emptyMsg.style.display = 'none';
    if (cacheBadge) cacheBadge.style.display = 'none';

    try {
        const activeMetrics = getActiveMetrics();
        const aggregations = { campaign_status: 'campaign_status' };
        activeMetrics.forEach(m => aggregations[m.key] = m.original);
        const payload = { aggregations, groupBy: ["channeledAccount", "channeledCampaign", "account", "campaign"], startDate: start, endDate: end };
        const resMain = await fetch('/facebook_marketing/metric/aggregate', { method: 'POST', headers, body: JSON.stringify(payload) }).then(r => r.json());
        if (resMain.status === 'success' && resMain.data) {
            if (resMain.meta && resMain.meta.cached && cacheBadge) { cacheBadge.style.display = 'inline-flex'; cacheBadge.title = `Source: Redis (${resMain.meta.cache_type})`; }
            currentData = resMain.data.map(d => ({ ...d, _trend: [] }));
            currentData.sort((a,b) => {
                const accA = String(a.account || '').toLowerCase();
                const accB = String(b.account || '').toLowerCase();
                if (accA !== accB) return accA.localeCompare(accB);
                return String(a.campaign || '').toLowerCase().localeCompare(String(b.campaign || '').toLowerCase());
            });
            render();
            const sparkMetrics = activeMetrics.filter(m => m.config && m.config.sparkline);
            if (sparkMetrics.length > 0) {
                const trendAggregations = {};
                sparkMetrics.forEach(m => trendAggregations[`trend_${m.key}`] = m.original);
                fetch('/facebook_marketing/metric/aggregate', { method: 'POST', headers, body: JSON.stringify({ aggregations: trendAggregations, groupBy: ["daily", "channeledCampaign"], startDate: start, endDate: end }) })
                .then(r => r.json()).then(resTrend => {
                    if (resTrend.status === 'success' && resTrend.data) {
                        const trendMaps = {}; sparkMetrics.forEach(m => trendMaps[m.key] = {});
                        resTrend.data.forEach(d => {
                            const cid = d.channeledCampaign;
                            if (!cid) return;
                            sparkMetrics.forEach(m => {
                                if (!trendMaps[m.key][cid]) trendMaps[m.key][cid] = [];
                                trendMaps[m.key][cid].push({ day: d.daily, val: parseFloat(d[`trend_${m.key}`] || 0) });
                            });
                        });
                        currentData.forEach((row, idx) => {
                            const cid = row.channeledCampaign;
                            sparkMetrics.forEach(m => {
                                const sparkId = getSparkId('campaign', m.key, cid, idx);
                                const container = document.getElementById(sparkId);
                                if (!container) return;
                                const rawTrend = trendMaps[m.key][cid];
                                if (rawTrend && rawTrend.length > 1) {
                                    rawTrend.sort((a,b) => a.day.localeCompare(b.day));
                                    renderSparkline(container, rawTrend.map(x => x.val), m.key.includes('cost_per') || m.key.includes('cpm') || m.key.includes('cpc'));
                                } else container.innerHTML = '<span style="color:var(--text-dim); font-size: 0.6rem; opacity: 0.3;">--</span>';
                            });
                        });
                    }
                });
            }
        } else { if (emptyMsg) emptyMsg.style.display = 'block'; resetSummary(); }
    } catch (error) { console.error("Dashboard Load Error:", error); if (emptyMsg) emptyMsg.style.display = 'block'; }
    finally { if (loader) loader.style.display = 'none'; lucide.createIcons(); }
}

function render() {
    const body = document.getElementById('table-body');
    if (!body) return;
    body.innerHTML = '';
    const activeMetrics = getActiveMetrics();
    const headRow = document.getElementById('table-head-row');
    if (headRow) {
        headRow.innerHTML = `
            <th class="col-actions">&nbsp;</th>
            <th data-sort="account" onclick="sortTable('account')" class="col-account text-left clickable">
                <div class="header-flex">AD ACCOUNT <span class="sort-icon">↕</span></div>
            </th>
            <th data-sort="campaign" onclick="sortTable('campaign')" class="col-campaign text-left clickable">
                <div class="header-flex">CAMPAIGN NAME <span class="sort-icon">↕</span></div>
            </th>
            <th class="col-status text-center">STATUS</th>
            ${activeMetrics.map(m => `
                <th class="col-metric text-right clickable" data-sort="${m.key}" onclick="sortTable('${m.key}')">
                    <div class="header-flex-end">${m.label.toUpperCase()} <span class="sort-icon">↕</span></div>
                </th>`).join('')}
        `;
    }
    let currentAccount = '';
    currentData.forEach((row, idx) => {
        if (row.account !== currentAccount) {
            const groupRow = document.createElement('tr');
            groupRow.className = 'account-group-row';
            groupRow.innerHTML = `<td colspan="${4 + activeMetrics.length}" style="background: rgba(255,255,255,0.02); padding: 12px 20px; font-weight: 800; font-size: 0.65rem; color: var(--text-dim); letter-spacing: 0.05em; border-bottom: 1px solid var(--border);"><i data-lucide="target" size="12" style="vertical-align:middle; margin-right:8px; color:#1877F2;"></i> AD ACCOUNT: ${row.account.toUpperCase()}</td>`;
            body.appendChild(groupRow);
            currentAccount = row.account;
        }
        const tr = document.createElement('tr');
        const rowId = `row-campaign-${row.channeledCampaign || idx}`.replace(/[^a-z0-9\-]/gi, '-');
        tr.id = rowId;
        const statusClass = row.campaign_status?.toLowerCase() === 'active' ? 'status-active' : 'status-paused';
        const isDisaggregatable = canDisaggregate('adset');
        tr.innerHTML = `
            <td class="col-actions cell-no-padding">
                <div class="btn-group-center">
                    <button class="btn-expand dim-btn" onclick="toggleHierarchy('${rowId}', 'dimensions', 'campaign', '${row.channeledCampaign}', '${row.campaign.replace(/'/g, "\\'")}')" title="Audience Analysis" ${!isDisaggregatable ? 'disabled class="disabled-btn"' : ''}><i data-lucide="users" size="14"></i></button>
                    <button class="btn-expand next-btn" onclick="toggleHierarchy('${rowId}', 'next', 'campaign', '${row.channeledCampaign}', '${row.campaign.replace(/'/g, "\\'")}')" title="Explore AdSets" ${!isDisaggregatable ? 'disabled class="disabled-btn"' : ''}><i data-lucide="layers" size="14"></i></button>
                </div>
            </td>
            <td class="account-cell">${row.account}</td>
            <td class="campaign-cell clickable-text" onclick="toggleHierarchy('${rowId}', 'next', 'campaign', '${row.channeledCampaign}', '${row.campaign.replace(/'/g, "\\'")}')">${row.campaign}</td>
            <td class="text-center">${getStatusIcon(row.campaign_status)}</td>
            ${activeMetrics.map(m => {
                const val = row[m.key] || 0;
                const formatted = applyFormatting(val, m.format, m.key, m.precision);
                const sparkId = getSparkId('campaign', m.key, row.channeledCampaign, idx);
                const badgeClass = applyConditional(val, m.config.conditional, m.key);
                return `<td class="text-right metric-cell"><div class="metric-flex-end"><span class="${badgeClass}">${formatted}</span>${m.config.sparkline ? `<div id="${sparkId}" class="sparkline-inline"><div class="spark-loading"></div></div>` : ''}</div></td>`;
            }).join('')}
        `;
        body.appendChild(tr);
    });
    const count = document.getElementById('record-count');
    if (count) count.textContent = currentData.length;
    renderSummary(currentData);
    updateSortHeaders();
    lucide.createIcons();
}

async function toggleHierarchy(rowId, type, level, entityId, entityName) {
    const mainRow = document.getElementById(rowId);
    const nextRow = mainRow?.nextElementSibling;
    const hItem = HIERARCHY[level];
    const btn = mainRow?.querySelector(type === 'dimensions' ? '.dim-btn' : '.next-btn');
    const isSub = nextRow?.classList.contains('hierarchy-row');
    if (isSub && nextRow.dataset.parentRow === rowId && nextRow.dataset.type === type) { nextRow.remove(); btn?.classList.remove('active'); return; }
    if (isSub && nextRow.dataset.parentRow === rowId) { nextRow.remove(); mainRow.querySelectorAll('.dim-btn, .next-btn').forEach(b => b.classList.remove('active')); }
    btn?.classList.add('active');
    const breakdownRow = document.createElement('tr');
    breakdownRow.className = 'hierarchy-row';
    breakdownRow.dataset.parentRow = rowId;
    breakdownRow.dataset.type = type;
    const containerId = `container-${type}-${rowId}`.replace(/[^a-z0-9\-]/gi, '-');
    breakdownRow.innerHTML = `<td colspan="20"><div id="${containerId}" class="nested-container level-${level}"><div class="breakdown-title"><i data-lucide="loader" class="spinner"></i> Loading breakdown...</div></div></td>`;
    mainRow.after(breakdownRow);
    lucide.createIcons();
    try {
        const headers = getAdminHeaders();
        const start = document.getElementById('startDate').value;
        const end = document.getElementById('endDate').value;
        const activeMetrics = getActiveMetrics();
        const aggs = {}; const trendAggs = {};
        activeMetrics.forEach(m => { aggs[m.key] = m.original; if (m.config.sparkline) trendAggs[`trend_${m.key}`] = m.original; });
        const filter = { [hItem.filterKey || hItem.idField.replace('_id', '')]: entityId };
        const payloadBase = { aggregations: aggs, filters: filter, startDate: start, endDate: end };
        if (type === 'dimensions') {
            const AUDIENCE_SETS = [
                { dims: ['age', 'gender'], label: 'Age x Gender' },
                { dims: ['age'], label: 'Age Distribution' },
                { dims: ['gender'], label: 'Gender Distribution' }
            ];
            
            const container = document.getElementById(containerId);
            container.innerHTML = `<div class="breakdown-title" style="color:#1877F2; margin-bottom:20px;"><i data-lucide="users"></i> Audience Intelligence for <strong>${entityName}</strong></div><div id="inner-${containerId}" style="display:flex; flex-direction:column; gap:40px;"></div>`;
            const inner = document.getElementById(`inner-${containerId}`);

            for (const set of AUDIENCE_SETS) {
                const subContainerId = `audience-${set.dims.join('-')}-${rowId}`;
                const subWrapper = document.createElement('div');
                subWrapper.innerHTML = `<div class="breakdown-title" style="font-size:0.65rem; opacity:0.8; margin-bottom:10px; text-transform:uppercase; letter-spacing:0.05em;"><i data-lucide="bar-chart-2" style="width:12px; margin-right:5px;"></i> ${set.label}</div><div id="${subContainerId}"></div>`;
                inner.appendChild(subWrapper);

                const resMain = await fetch('/facebook_marketing/metric/aggregate', { method: 'POST', headers, body: JSON.stringify({ ...payloadBase, groupBy: set.dims }) }).then(r => r.json());
                const resTrend = await fetch('/facebook_marketing/metric/aggregate', { method: 'POST', headers, body: JSON.stringify({ ...payloadBase, aggregations: trendAggs, groupBy: ["daily", ...set.dims] }) }).then(r => r.json());
                
                renderDimensionTable(document.getElementById(subContainerId), resMain.data || [], set.dims, entityId);

                if (resTrend.status === 'success' && resTrend.data) {
                    const trendMaps = {}; 
                    resTrend.data.forEach(d => {
                        const dimKey = set.dims.map(dim => d[dim]).join('-');
                        if (!trendMaps[dimKey]) trendMaps[dimKey] = {};
                        activeMetrics.forEach(m => { 
                            if (d[`trend_${m.key}`] !== undefined) { 
                                if (!trendMaps[dimKey][m.key]) trendMaps[dimKey][m.key] = []; 
                                trendMaps[dimKey][m.key].push({ day: d.daily, val: parseFloat(d[`trend_${m.key}`] || 0) }); 
                            } 
                        });
                    });

                    (resMain.data || []).forEach((row, idx) => {
                        const dimValKey = set.dims.map(dim => row[dim]).join('-');
                        const uniqueId = `dim-${entityId}-${dimValKey}`;
                        activeMetrics.forEach(m => {
                            if (!m.config.sparkline) return;
                            const sparkId = getSparkId('dim-' + set.dims.join('-'), m.key, uniqueId, idx);
                            const sparkContainer = document.getElementById(sparkId);
                            const rawTrend = trendMaps[dimValKey]?.[m.key];
                            if (sparkContainer && rawTrend && rawTrend.length > 1) {
                                rawTrend.sort((a,b) => a.day.localeCompare(b.day));
                                renderSparkline(sparkContainer, rawTrend.map(x => x.val), m.key.includes('cost_per') || m.key.includes('cpm') || m.key.includes('cpc'));
                            } else if (sparkContainer) sparkContainer.innerHTML = '--';
                        });
                    });
                }
            }
        } else {
            const nextHItem = HIERARCHY[hItem.next];
            const groupBy = [nextHItem.idField, nextHItem.nameField];
            const resMain = await fetch('/facebook_marketing/metric/aggregate', { method: 'POST', headers, body: JSON.stringify({ ...payloadBase, groupBy }) }).then(r => r.json());
            const resTrend = await fetch('/facebook_marketing/metric/aggregate', { method: 'POST', headers, body: JSON.stringify({ ...payloadBase, aggregations: trendAggs, groupBy: ["daily", ...groupBy] }) }).then(r => r.json());
            const container = document.getElementById(containerId);
            container.innerHTML = `<div class="breakdown-title" style="color:${hItem.color}"><i data-lucide="${nextHItem.icon}"></i> ${nextHItem.label} Analysis for <strong>${entityName}</strong></div><div id="inner-${containerId}"></div>`;
            const nestedData = resMain.data || [];
            renderRecursiveTable(document.getElementById(`inner-${containerId}`), nestedData, hItem.next, rowId, entityName);
            if (resTrend.status === 'success' && resTrend.data) {
                const trendMaps = {}; resTrend.data.forEach(d => {
                    const nextId = d[nextHItem.idField]; if (!nextId) return;
                    if (!trendMaps[nextId]) trendMaps[nextId] = {};
                    activeMetrics.forEach(m => { if (d[`trend_${m.key}`] !== undefined) { if (!trendMaps[nextId][m.key]) trendMaps[nextId][m.key] = []; trendMaps[nextId][m.key].push({ day: d.daily, val: parseFloat(d[`trend_${m.key}`] || 0) }); } });
                });
                nestedData.forEach((row, idx) => {
                    const nextId = row[nextHItem.idField];
                    activeMetrics.forEach(m => {
                        if (!m.config.sparkline) return;
                        const sparkId = getSparkId(hItem.next, m.key, nextId, idx);
                        const sparkContainer = document.getElementById(sparkId);
                        const rawTrend = trendMaps[nextId]?.[m.key];
                        if (sparkContainer && rawTrend && rawTrend.length > 1) {
                            rawTrend.sort((a,b) => a.day.localeCompare(b.day));
                            renderSparkline(sparkContainer, rawTrend.map(x => x.val), m.key.includes('cost_per') || m.key.includes('cpm') || m.key.includes('cpc'));
                        } else if (sparkContainer) sparkContainer.innerHTML = '--';
                    });
                });
            }
        }
    } catch (err) { console.error("Hierarchy error:", err); document.getElementById(containerId).innerHTML = `<div class="empty-state">${err.message}</div>`; }
    finally { lucide.createIcons(); }
}

function renderRecursiveTable(container, data, level, parentRowId, parentName) {
    const hItem = HIERARCHY[level];
    const activeMetrics = getActiveMetrics();
    let html = `<table class="nested-table"><thead><tr>
        <th style="width: 85px; text-align: center;">EXPLORE</th>
        <th style="text-align: left; min-width: 200px;">${hItem.label.toUpperCase()} NAME</th>
        <th style="width: 80px; text-align: center;">STATUS</th>`;
    
    activeMetrics.forEach(m => {
        html += `<th style="text-align: right; min-width: 80px;">
            <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px;">${m.label.toUpperCase()}</div>
        </th>`;
    });
    html += `</tr></thead><tbody>`;
    data.forEach((row, idx) => {
        const eId = row[hItem.idField];
        const rowId = `row-${level}-${eId || idx}`.replace(/[^a-z0-9\-]/gi, '-');
        html += `<tr id="${rowId}">
            <td class="text-center">
                <div class="btn-group-center">
                    <button class="btn-expand dim-btn" onclick="toggleHierarchy('${rowId}', 'dimensions', '${level}', '${eId}', '${row[hItem.nameField].replace(/'/g, "\\'")}')"><i data-lucide="users" size="12"></i></button>
                    ${hItem.next && canDisaggregate(hItem.next) ? `<button class="btn-expand next-btn" onclick="toggleHierarchy('${rowId}', 'next', '${level}', '${eId}', '${row[hItem.nameField].replace(/'/g, "\\'")}')"><i data-lucide="${HIERARCHY[hItem.next].icon}" size="12"></i></button>` : ''}
                </div>
            </td>
            <td class="campaign-cell"><strong>${row[hItem.nameField]}</strong></td>
            <td class="text-center">${getStatusIcon(row.status || row.campaign_status)}</td>`;
        activeMetrics.forEach(m => {
            const val = row[m.key] || 0;
            const sparkId = getSparkId(level, m.key, eId, idx);
            html += `<td class="text-right">
                <div class="metric-flex-end">
                    <span class="${applyConditional(val, m.config.conditional, m.key)}">${applyFormatting(val, m.format, m.key, m.precision)}</span>
                    ${m.config.sparkline ? `<div id="${sparkId}" class="sparkline-inline"><div class="spark-loading"></div></div>` : ''}
                </div>
            </td>`;
        });
        html += `</tr>`;
    });
    html += `</tbody></table>`;
    container.innerHTML = html;
    lucide.createIcons();
}

function renderDimensionTable(container, data, dims, parentId = '') {
    const activeMetrics = getActiveMetrics();
    let html = `<table class="nested-table"><thead><tr>
        <th style="text-align: left; min-width: 150px;">${dims.join(' / ').toUpperCase()}</th>`;
    
    activeMetrics.forEach(m => {
        html += `<th style="text-align: right; min-width: 80px;">
            <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px;">${m.label.toUpperCase()}</div>
        </th>`;
    });
    html += `</tr></thead><tbody>`;
    data.forEach((row, idx) => {
        const dimValKey = dims.map(d => row[d]).join('-');
        const uniqueId = `dim-${parentId}-${dimValKey}`;
        html += `<tr><td>${dims.map(d => row[d]).join(' / ')}</td>`;
        activeMetrics.forEach(m => {
            const val = row[m.key] || 0;
            const sparkId = getSparkId('dim-' + dims.join('-'), m.key, uniqueId, idx);
            html += `<td class="text-right">
                <div style="display:flex; justify-content:flex-end; align-items:center; gap:8px;">
                    <span class="${applyConditional(val, m.config.conditional, m.key)}">${applyFormatting(val, m.format, m.key, m.precision)}</span>
                    ${m.config.sparkline ? `<div id="${sparkId}" class="sparkline-inline"><div class="spark-loading"></div></div>` : ''}
                </div>
            </td>`;
        });
        html += `</tr>`;
    });
    html += `</tbody></table>`;
    container.innerHTML = html;
    lucide.createIcons();
}

function getStatusIcon(status) {
    const s = (status || 'unknown').toLowerCase();
    if (s === 'active' || s === 'on') return '<i data-lucide="circle" style="fill:#3fb950; color:#3fb950; width:12px;"></i>';
    if (s === 'paused' || s === 'off') return '<i data-lucide="pause-circle" style="color:#f0883e; width:14px;"></i>';
    return '<i data-lucide="help-circle" style="width:14px; opacity:0.5"></i>';
}

function sortTable(key) {
    if (sortConfig.key === key) sortConfig.dir = sortConfig.dir === 'asc' ? 'desc' : 'asc';
    else { sortConfig.key = key; sortConfig.dir = 'asc'; }
    currentData.sort((a,b) => {
        let vA = a[key] || 0; let vB = b[key] || 0;
        if (typeof vA === 'string') return sortConfig.dir === 'asc' ? vA.localeCompare(vB) : vB.localeCompare(vA);
        return sortConfig.dir === 'asc' ? vA - vB : vB - vA;
    });
    render();
}

function updateSortHeaders() {
    document.querySelectorAll('th[data-sort]').forEach(th => {
        const icon = th.querySelector('.sort-icon');
        if (icon) {
            icon.textContent = '↕';
            icon.style.color = 'inherit';
            icon.style.opacity = '0.3';
            if (th.dataset.sort === sortConfig.key) {
                icon.textContent = sortConfig.dir === 'asc' ? '↑' : '↓';
                icon.style.color = 'var(--primary)';
                icon.style.opacity = '1';
                icon.style.fontWeight = '700';
            }
        }
    });
}

function applyFormatting(val, format, key, dec) {
    if (format === 'currency' || (key && (key.includes('cost') || key.includes('cp')))) return '€' + formatNum(val, dec);
    if (format === 'percent' || (key && (key.includes('ctr') || key.includes('rate')))) return formatPct(parseFloat(val) * (val > 1 ? 1 : 100), dec);
    return formatNum(val, dec);
}

function applyConditional(val, rules, key) {
    if (!rules) return '';
    try {
        const data = typeof rules === 'string' ? JSON.parse(rules) : rules;
        if (!data.enabled) return '';
        
        let v = parseFloat(val);
        // Normalize CTR/Rate for range comparison
        if (key && (key.includes('ctr') || key.includes('rate'))) {
            if (v > 0 && v <= 1) v *= 100;
        }

        const ruleList = data.config || data.rules || [];
        for (const r of ruleList) {
            const min = (r.min !== undefined && r.min !== null) ? r.min : -Infinity;
            const max = (r.max !== undefined && r.max !== null && r.max !== 0) ? r.max : Infinity;
            
            if (v >= min && v <= max) {
                return r.class.startsWith('badge-') ? r.class : `badge-${r.class}`;
            }
        }
    } catch(e) { console.error("Conditional error:", e); }
    return '';
}

function formatNum(v, dec) { 
    const precision = (dec !== undefined && dec !== null) ? dec : 2;
    return (parseFloat(v) || 0).toLocaleString('en-US', { minimumFractionDigits: precision, maximumFractionDigits: precision }); 
}
function formatInt(v) { return (parseInt(v) || 0).toLocaleString('en-US'); }
function formatPct(v, dec) { 
    const precision = (dec !== undefined && dec !== null) ? dec : 2;
    return (parseFloat(v) || 0).toFixed(precision) + '%'; 
}

function renderSummary(data) {
    const sum = data.reduce((acc, row) => {
        acc.spend += parseFloat(row.total_spend || 0);
        acc.clicks += parseInt(row.total_clicks || 0);
        acc.impr += parseInt(row.total_impressions || 0);
        return acc;
    }, { spend:0, clicks:0, impr:0 });
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
    set('total-spend', `€ ${formatNum(sum.spend)}`);
    set('total-impressions', formatInt(sum.impr));
    set('total-clicks', formatInt(sum.clicks));
    set('total-ctr', formatPct((sum.clicks / (sum.impr || 1)) * 100));
}

function resetSummary() { ['total-spend', 'total-impressions', 'total-clicks', 'total-ctr'].forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '0'; }); }

function renderSparkline(container, points, isInverted = false) {
    if (!container || !points || points.length < 2) return;
    const width = 80; const height = 24;
    const numericPoints = points.map(p => parseFloat(p) || 0);
    const max = Math.max(...numericPoints) || 1; const min = Math.min(...numericPoints) || 0; const range = (max - min) || 1;
    const color = numericPoints[numericPoints.length-1] > numericPoints[0] ? (isInverted ? '#f85149' : '#3fb950') : (isInverted ? '#3fb950' : '#f85149');
    const svgPoints = numericPoints.map((p, i) => `${(i / (points.length - 1)) * width},${height - ((p - min) / range) * (height - 4) - 2}`);
    container.innerHTML = `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}"><polyline fill="none" stroke="${color}" stroke-width="1.5" points="${svgPoints.join(' ')}" /></svg>`;
}

// Global hooks
window.loadReport = loadReport;
window.forceRefresh = forceRefresh;
window.initDashboard = initDashboard;
window.toggleHierarchy = toggleHierarchy;
window.sortTable = sortTable;
document.addEventListener('DOMContentLoaded', initDashboard);
