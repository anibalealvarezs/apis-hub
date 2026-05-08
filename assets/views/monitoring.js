/** 
 * APIs Hub | Monitoring Dashboard Logic 🛡️🖥️
 * Infrastructure & Container Health Analytics Engine.
 */

// Global State
let currentData = null;

function formatAccountLabel(accountId) {
    if (accountId === null || accountId === undefined || accountId === '') {
        return 'All Accounts';
    }
    return `Account #${accountId}`;
}

function formatTimeframe(startDate, endDate) {
    const hasStart = !!startDate;
    const hasEnd = !!endDate;
    if (!hasStart && !hasEnd) {
        return 'Full Range';
    }
    return `${startDate || '...'} -> ${endDate || '...'}`;
}

// --- Initialization ---
function initMonitoring() {
    lucide.createIcons();
    fetchData();
    setInterval(fetchData, 15000); // 15s heartbeats
}

function getAdminHeaders() {
    const envMeta = document.querySelector('meta[name="app-env"]');
    const isDemo = (envMeta && envMeta.getAttribute('content') === 'demo') ||
                   window.AUTH_BYPASS === true;

    const EXPIRATION_TIME = 24 * 60 * 60 * 1000;
    const now = Date.now();
    let auth = JSON.parse(localStorage.getItem('apis_hub_admin_auth') || '{}');
    
    // Bypass for Demo
    if (isDemo) {
        return { 
            'Authorization': 'Bearer DEMO_BYPASS',
            'Content-Type': 'application/json' 
        };
    }

    if (!auth.token || (now - auth.timestamp > EXPIRATION_TIME)) {
        const token = prompt('ADMIN ACCESS REQUIRED: Please enter your administrative API key to continue.');
        if (token) {
            auth = { token, timestamp: now };
            localStorage.setItem('apis_hub_admin_auth', JSON.stringify(auth));
        } else {
            return {};
        }
    }
    
    return {
        'Authorization': 'Bearer ' + auth.token,
        'Content-Type': 'application/json'
    };
}

async function fetchData() {
    try {
        const headers = getAdminHeaders();
        if (!headers.Authorization) return;

        const response = await fetch('/api/monitoring/data', { headers });
        if (response.status === 401) {
            localStorage.removeItem('apis_hub_admin_auth');
            alert('Session Expired. Please reload.');
            return;
        }
        
        const data = await response.json();
        if (data.error) throw new Error(data.error);
        currentData = data;

        updateDbTotals(data.dbTotals);
        updateContainers(data.containers);
        fetchSyncTelemetry(); // Call separately
        if (data.groupedJobs) {
            // DEBUG: Inject Simulation Jobs to preview UI ONLY in Demo Mode
            const envMeta = document.querySelector('meta[name="app-env"]');
            const isDemo = envMeta && envMeta.content === 'demo';

            if (isDemo) {
                data.groupedJobs['SIMULATION-DEBUG'] = [
                    { 
                        id: 9991, status: 1, status_text: 'SCHEDULED', entity: 'CAMPAIGNS', channel: 'facebook_ads', group: 'SIMULATION-DEBUG', 
                        params: { startDate: '2024-03-01', endDate: '2024-03-10', resume: false }, 
                        created_at: '2024-03-24 10:00', updated_at: '2024-03-24 10:05',
                        execution_time: 'N/A', frequency: '0 0 * * *',
                        message: 'Running daily sync',
                        history: [ { status: 3, date: '2024-03-23 10:00', message: 'Success' }, { status: 3, date: '2024-03-22 10:00', message: 'Success' } ]
                    },
                    { 
                        id: 9992, status: 3, status_text: 'COMPLETED', entity: 'METRICS', channel: 'facebook_marketing', group: 'SIMULATION-DEBUG', 
                        params: { startDate: '2024-02-01', endDate: '2024-02-28', resume: true }, 
                        created_at: '2024-03-23 09:30', updated_at: '2024-03-23 10:01',
                        execution_time: '31m 36s', frequency: 'N/A',
                        message: 'Success',
                        history: [ { status: 3, date: '2024-03-22 09:30', message: 'Full pull ok' }, { status: 4, date: '2024-03-21 09:30', message: 'API Error' } ]
                    },
                    { 
                        id: 9993, status: 4, status_text: 'FAILED', entity: 'PRODUCTS', channel: 'shopify', group: 'SIMULATION-DEBUG', 
                        params: { startDate: '2024-02-01', endDate: '2024-02-15' }, 
                        created_at: '2024-03-24 08:00', updated_at: '2024-03-24 08:30',
                        execution_time: '30m 00s', frequency: '0 */6 * * *',
                        message: 'API Rate Limit: Too many requests for this shopify instance.',
                        history: [ { status: 4, date: '2024-03-23 08:00', message: 'Timeout' } ]
                    }
                ];
            }
            updatePendingJobsDetailed(data.groupedJobs);
        }

        const elUpdated = document.getElementById('last-updated');
        if (elUpdated) {
            const now = new Date();
            elUpdated.textContent = `${now.toLocaleDateString()} ${now.toLocaleTimeString()}`;
        }
    } catch (error) {
        console.error('Monitoring Fetch Error:', error);
    }
}

function updateDbTotals(totals) {
    const grid = document.getElementById('db-totals-grid');
    if (!grid) return;
    grid.innerHTML = '';

    // totals is already grouped by entity from PHP backend
    totals.forEach(item => {
        const totalCount = parseInt(item.count) || 0;
        if (totalCount === 0) return;

        const card = document.createElement('div');
        card.className = 'container-item';
        card.style = 'background: rgba(255,255,255,0.01); border-color: rgba(255,255,255,0.05); padding: 25px;';
        
        let breakdownHtml = '';
        const channels = item.channels || [];
        
        // If no channel breakdown exists (e.g. for simple tables), show total as "Default" 
        if (channels.length === 0) {
            breakdownHtml = `
                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <div style="display:flex; align-items:center; gap:8px;">
                        <div style="width:6px; height:6px; border-radius:50%; background:var(--primary);"></div>
                        <span style="font-size:0.75rem; color:var(--text-dim); text-transform:uppercase; font-weight:700;">TOTAL</span>
                    </div>
                    <span style="font-size:1.3rem; font-weight:800; color:#fff; font-family:var(--font-mono);">${totalCount.toLocaleString()}</span>
                </div>
            `;
        } else {
            channels.forEach(chan => {
                const chanName = chan.channel || chan.name;
                const chanColor = (chanName.toLowerCase().includes('facebook') || chanName.toLowerCase().includes('meta')) ? '#1877F2' : 'var(--primary)';
                
                let labelHtml = `<span style="font-size:0.75rem; color: #fff; font-weight:700;">${chanName}</span>`;
                if (chan.type) {
                    labelHtml += ` <span style="font-size:0.6rem; color:var(--text-dim); opacity:0.6;">• ${chan.type}</span>`;
                }

                breakdownHtml += `
                    <div style="margin-bottom:12px;">
                        <div style="display:flex; justify-content:space-between; align-items:center;">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <div style="width:6px; height:6px; border-radius:50%; background:${chanColor}; flex-shrink:0;"></div>
                                ${labelHtml}
                            </div>
                            <span style="font-size:1.1rem; font-weight:800; color:#fff; font-family:var(--font-mono);">${chan.count.toLocaleString()}</span>
                        </div>
                    </div>
                `;
            });
        }

        const normalizedLabel = item.entity.replace(/^fb_/, '').replace(/^gsc_/, '').replace(/_/g, ' ').toUpperCase();

        card.innerHTML = `
            <div style="font-size: 0.8rem; color: var(--text-dim); text-transform: uppercase; margin-bottom: 20px; font-weight:900; letter-spacing:0.1em; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom:10px;">${normalizedLabel}</div>
            <div class="entity-breakdown-list">
                ${breakdownHtml}
            </div>
        `;
        grid.appendChild(card);
    });
}

async function fetchSyncTelemetry() {
    try {
        const headers = getAdminHeaders();
        if (!headers.Authorization) return;

        const response = await fetch('/api/sync/status', { headers });
        const data = await response.json();
        
        // Handle both direct object and wrapped responses
        const telemetry = data.channels ? data.channels : (data.data && data.data.channels ? data.data.channels : null);
        
        if (telemetry) {
            updateSyncTelemetry(telemetry);
        }
    } catch (e) {
        console.error('Sync Telemetry Fetch Error:', e);
    }
}

function updateSyncTelemetry(telemetry) {
    const grid = document.getElementById('sync-telemetry-grid');
    if (!grid) return;
    
    if (!telemetry || Object.keys(telemetry).length === 0) {
        grid.innerHTML = '<div class="empty-state">No synchronization activity detected</div>';
        return;
    }

    grid.innerHTML = '';
    Object.entries(telemetry).forEach(([channel, stats]) => {
        const total = stats.total_jobs || 0;
        if (total === 0) return;

        const pCompleted = (stats.completed / total) * 100;
        const pProcessing = (stats.processing / total) * 100;
        const pFailed = (stats.failed / total) * 100;
        const pScheduled = (stats.scheduled / total) * 100;

        const card = document.createElement('div');
        card.className = 'sync-channel-card';
        card.id = `sync-card-${channel}`;
        
        const channelLabel = channel.replace(/_/g, ' ').toUpperCase();
        
        const fullySyncedP = stats.fully_synced_percentage || 0;
        const fullySyncedCount = stats.fully_synced_count || 0;
        const totalAssets = stats.total_assets || 0;

        card.innerHTML = `
            <div class="sync-card-header">
                <div class="sync-channel-name">
                    <i data-lucide="activity" size="16" style="color:var(--secondary)"></i>
                    ${channelLabel}
                </div>
                <div style="text-align:right;">
                    <div class="sync-percentage">${Math.round(stats.completion_percentage)}%</div>
                    <div style="font-size:0.65rem; color:var(--text-dim); text-transform:uppercase; font-weight:700; margin-top:2px;">GLOBAL DATA LOAD</div>
                </div>
            </div>

            <div class="sync-business-kpi">
                <div class="kpi-main">
                    <span class="kpi-value ${fullySyncedP >= 100 ? 'fully-synced' : ''}">${fullySyncedP}%</span>
                    <span class="kpi-label">ACCOUNTS FULLY SYNCED</span>
                </div>
                <div class="kpi-sub">
                    <span style="color:#fff; font-weight:700;">${fullySyncedCount}</span> / ${totalAssets} ACCOUNTS READY
                </div>
            </div>
            
            <div class="sync-progress-container">
                <div class="sync-progress-segment sync-segment-completed" style="width: ${pCompleted}%" title="Completed: ${stats.completed}"></div>
                <div class="sync-progress-segment sync-segment-processing" style="width: ${pProcessing}%" title="Processing: ${stats.processing}"></div>
                <div class="sync-progress-segment sync-segment-failed" style="width: ${pFailed}%" title="Failed: ${stats.failed}"></div>
                <div class="sync-progress-segment sync-segment-scheduled" style="width: ${pScheduled}%" title="Scheduled: ${stats.scheduled}"></div>
            </div>
            
            <div class="sync-assets-list">
                ${(stats.assets || []).sort((a,b) => b.completion - a.completion).slice(0, 10).map(asset => `
                    <div class="sync-asset-item" onclick="toggleAccountStats('${channel}', '${asset.id}')">
                        <div class="sync-asset-info">
                            <span class="sync-asset-id">${asset.name || '#' + asset.id}</span>
                            <span class="sync-asset-p">${Math.round(asset.completion)}%</span>
                        </div>
                        <div class="sync-asset-bar-bg">
                            <div class="sync-asset-bar-fill" style="width: ${asset.completion}%"></div>
                        </div>
                    </div>
                `).join('')}
                ${stats.assets.length > 10 ? `<div style="font-size:0.6rem; color:var(--text-dim); text-align:center; padding-top:5px; border-top:1px solid rgba(255,255,255,0.03);">+ ${stats.assets.length - 10} more accounts</div>` : ''}
            </div>

            <div id="details-${channel}" class="sync-details-container" style="display:none;">
                <div class="details-header">
                    <span id="details-title-${channel}">Account Details</span>
                    <button class="btn-close-details" onclick="this.parentElement.parentElement.style.display='none'">&times;</button>
                </div>
                <div id="chart-container-${channel}" class="contribution-chart-wrapper">
                    <div class="empty-state"><i data-lucide="loader" class="spinner"></i> Loading sync history...</div>
                </div>
            </div>
            
            <div class="sync-stats-mini">
                <div class="sync-stat-item">
                    <div class="sync-stat-label">Total Jobs</div>
                    <div class="sync-stat-val">${total}</div>
                </div>
                <div class="sync-stat-item">
                    <div class="sync-stat-label">Done</div>
                    <div class="sync-stat-val" style="color:#10b981">${stats.completed}</div>
                </div>
                <div class="sync-stat-item">
                    <div class="sync-stat-label">Active</div>
                    <div class="sync-stat-val" style="color:var(--secondary)">${stats.processing}</div>
                </div>
            </div>
        `;
        grid.appendChild(card);
    });

    lucide.createIcons();
}

async function toggleAccountStats(channel, accountId) {
    const detailsContainer = document.getElementById(`details-${channel}`);
    const chartContainer = document.getElementById(`chart-container-${channel}`);
    const titleEl = document.getElementById(`details-title-${channel}`);
    
    if (!detailsContainer || !chartContainer) return;

    // Show container
    detailsContainer.style.display = 'block';
    titleEl.textContent = `SYNC HISTORY: ACCOUNT #${accountId}`;
    chartContainer.innerHTML = '<div class="empty-state"><i data-lucide="loader" class="spinner"></i> Expanding daily metrics...</div>';
    lucide.createIcons();

    // Scroll to it
    detailsContainer.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    try {
        const headers = getAdminHeaders();
        const response = await fetch(`/api/sync/account-stats?channel=${channel}&account_id=${accountId}`, { headers });
        const data = await response.json();
        
        if (data.name) {
            titleEl.textContent = `SYNC HISTORY: ${data.name}`;
        }

        if (data.completed_days) {
            renderContributionChart(chartContainer, data.completed_days);
        } else {
            chartContainer.innerHTML = '<div class="empty-state">No historical records found for this account</div>';
        }
    } catch (e) {
        chartContainer.innerHTML = `<div class="empty-state" style="color:var(--danger)">Error: ${e.message}</div>`;
    }
}

function renderContributionChart(container, completedDays) {
    const today = new Date();
    const daysToShow = 365;
    const dayMap = new Set(completedDays);
    
    let html = '<div class="github-chart">';
    
    // Header with months
    html += '<div class="chart-months">';
    const monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    for (let i = 0; i < 12; i++) {
        const d = new Date();
        d.setMonth(today.getMonth() - (11 - i));
        html += `<span>${monthNames[d.getMonth()]}</span>`;
    }
    html += '</div>';

    html += '<div class="chart-grid">';
    
    // Labels for days of week
    html += '<div class="chart-days"><span>Mon</span><span>Wed</span><span>Fri</span></div>';

    html += '<div class="chart-cells">';
    
    // Generate 53 weeks
    const startDate = new Date();
    startDate.setDate(today.getDate() - daysToShow);
    // Align to start of week (Sunday = 0)
    while (startDate.getDay() !== 0) {
        startDate.setDate(startDate.getDate() - 1);
    }

    for (let i = 0; i < (daysToShow + 7); i++) {
        const current = new Date(startDate);
        current.setDate(startDate.getDate() + i);
        const dateStr = current.toISOString().split('T')[0];
        const isCompleted = dayMap.has(dateStr);
        const isFuture = current > today;
        
        let intensity = isCompleted ? 'level-4' : 'level-0';
        if (isFuture) intensity = 'level-future';

        html += `<div class="chart-cell ${intensity}" title="${dateStr}${isCompleted ? ': Synced' : ''}"></div>`;
    }

    html += '</div></div>';
    
    html += `
        <div class="chart-footer">
            <span>Learn how we track data integrity</span>
            <div class="chart-legend">
                <span>Less</span>
                <div class="chart-cell level-0"></div>
                <div class="chart-cell level-1"></div>
                <div class="chart-cell level-2"></div>
                <div class="chart-cell level-3"></div>
                <div class="chart-cell level-4"></div>
                <span>More</span>
            </div>
        </div>
    `;
    
    html += '</div>';
    container.innerHTML = html;
}

function updateContainers(containers) {
    const grid = document.getElementById('container-grid');
    if (!grid) return;
    grid.innerHTML = '';
    
    if (!containers || containers.length === 0) {
        grid.innerHTML = '<div class="empty-state">No infrastructure instances detected</div>';
        return;
    }

    // 1. Group Containers by Source (Channel)
    const grouped = {};
    containers.forEach(c => {
        const source = (c.source || 'Other').toUpperCase();
        if (!grouped[source]) grouped[source] = [];
        grouped[source].push(c);
    });

    Object.entries(grouped).forEach(([source, groupContainers]) => {
        const section = document.createElement('div');
        section.className = 'workflow-group-section';
        section.style.gridColumn = '1 / -1';
        section.style.marginTop = '10px';
        
        section.innerHTML = `
            <div class="workflow-group-header" style="font-size:0.85rem; margin-bottom:15px; color:var(--primary);">
                <i data-lucide="layers" class="workflow-group-icon" size="16"></i>
                <span>${source}</span>
                <div class="workflow-group-line"></div>
            </div>
            <div class="containers-inner-grid" style="display:grid; grid-template-columns: repeat(auto-fill, minmax(360px, 1fr)); gap:15px;"></div>
        `;
        
        const cardGrid = section.querySelector('.containers-inner-grid');

        groupContainers.forEach(container => {
            const card = document.createElement('div');
            card.className = 'glass-card container-item';
            card.style.padding = '18px';
            card.style.borderLeft = '3px solid var(--primary)';
            
            const stats = container.stats || { total: 0 };
            const completedCount = stats.completed || 0;
            const failedCount = stats.failed || 0;
            const activeJob = container.active_job || null;

            const activeJobHtml = activeJob ? `
                <div class="monitoring-active-job">
                    <div class="monitoring-active-job-title">Active Job</div>
                    <div class="monitoring-active-job-grid">
                        <div><span class="monitoring-muted-key">Flow:</span> ${activeJob.channel || 'n/a'} -> ${activeJob.entity || 'n/a'}</div>
                        <div><span class="monitoring-muted-key">Scope:</span> ${formatAccountLabel(activeJob.account_id)}</div>
                        <div><span class="monitoring-muted-key">Timeframe:</span> ${formatTimeframe(activeJob.start_date, activeJob.end_date)}</div>
                        <div><span class="monitoring-muted-key">Priority:</span> ${activeJob.priority ?? 0}</div>
                    </div>
                </div>
            ` : `
                <div class="monitoring-active-job" style="font-size:0.72rem; color:var(--text-dim);">
                    No active job assigned
                </div>
            `;

            card.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:10px;">
                    <div>
                        <div style="font-weight:900; font-size:0.95rem; color:#fff; line-height:1.2; letter-spacing:0.02em;">${container.name}</div>
                        <div style="font-family:var(--font-mono); font-size:0.7rem; color:var(--text-dim); margin-top:3px;">${container.id}</div>
                    </div>
                    <span class="status-badge" style="background: rgba(88, 166, 255, 0.1); color: #4ade80; font-size:0.6rem; padding: 2px 8px; border-radius:4px; font-weight:700;">ACTIVE</span>
                </div>

                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px; margin:15px 0 10px;">
                    <div style="font-family:var(--font-mono); font-size:0.75rem; color: #fff;">
                        <span style="color:var(--text-dim);">#Port:</span> ${container.port}
                    </div>
                    <div style="font-family:var(--font-mono); font-size:0.75rem; color: #fff; display:flex; align-items:center; gap:4px;">
                        <i data-lucide="tag" size="12" style="color:var(--text-dim);"></i>
                        <span style="color:var(--text-dim);">Src:</span> ${container.source}
                    </div>
                </div>

                <div style="border-top: 1px solid rgba(255,255,255,0.03); padding-top:12px; margin-top:10px; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <div style="font-size:0.6rem; color:var(--text-dim); text-transform:uppercase; font-weight:800; letter-spacing:0.05em; margin-bottom:8px;">INSTANCE JOB STATS</div>
                        <div style="display:flex; align-items:center; gap:12px;">
                            <div style="display:flex; align-items:center; gap:6px; color:#4ade80; font-weight:700; font-size:0.9rem;">
                                <i data-lucide="check" size="15"></i>
                                <span>${completedCount}</span>
                            </div>
                            ${failedCount > 0 ? `
                                 <div style="display:flex; align-items:center; gap:6px; color:var(--danger); font-weight:700; font-size:0.9rem;">
                                    <i data-lucide="x-circle" size="15"></i>
                                    <span>${failedCount}</span>
                                 </div>
                            ` : ''}
                        </div>
                    </div>
                    <button class="btn btn-mini disabled-btn" disabled 
                            style="background:rgba(255, 255, 255, 0.05); color:var(--text-dim); padding:8px; border-radius:8px; border:1px solid rgba(255, 255, 255, 0.1); cursor:not-allowed;"
                            title="Force Sync (Feature disabled for now. This will allow manual, out-of-schedule execution for specific instances in future updates.)">
                        <i data-lucide="play-circle" style="opacity:0.3" size="18"></i>
                    </button>
                </div>

                ${activeJobHtml}
            `;
            cardGrid.appendChild(card);
        });

        grid.appendChild(section);
    });

    lucide.createIcons();
}

function updatePendingJobsDetailed(groupedJobs) {
    const list = document.getElementById('jobs-detailed-list');
    if (!list) return;
    list.innerHTML = '';

    if (!groupedJobs || Object.keys(groupedJobs).length === 0) {
        const badge = document.getElementById('job-count-badge');
        if (badge) badge.textContent = '0 Pending';
        list.innerHTML = '<div class="empty-state">No synchronization pipelines active</div>';
        return;
    }

    const totalJobs = Object.values(groupedJobs).reduce((acc, jobs) => acc + (jobs?.length || 0), 0);
    const badge = document.getElementById('job-count-badge');
    if (badge) badge.textContent = `${totalJobs} Active`;

    // Sort group keys (Channels) to have specific ones first
    const groupKeys = Object.keys(groupedJobs).sort((a,b) => {
       if (a === 'SIMULATION-DEBUG') return 1;
       if (b === 'SIMULATION-DEBUG') return -1;
       return a.localeCompare(b);
    });

    groupKeys.forEach(groupKey => {
        const jobs = groupedJobs[groupKey];
        if (!jobs || jobs.length === 0) return;

        // Create Channel Group Section
        const section = document.createElement('div');
        section.className = 'workflow-group-section';
        
        const groupTitle = groupKey.replace(/_/g, '').toUpperCase();
        section.innerHTML = `
            <div class="workflow-group-header">
                <i data-lucide="workflow" class="workflow-group-icon" size="20"></i>
                <span>${groupTitle} WORKFLOW</span>
                <div class="workflow-group-line"></div>
            </div>
            <div class="group-jobs-container" style="display:flex; flex-direction:column; gap:12px;"></div>
        `;
        
        const cardContainer = section.querySelector('.group-jobs-container');

        jobs.forEach(job => {
            const card = document.createElement('div');
            card.className = 'glass-card pipeline-card';
            card.dataset.id = job.id;
            
            const statusClass = `badge-${job.status_text.toLowerCase()}`;
            const canReschedule = job.status !== 1 && job.status !== 2;
            const canCancel = job.status === 1 || job.status === 2;
            const headerIcon = job.status === 3 ? 'check-circle' : 
                               (job.status === 4 ? 'alert-circle' : 
                               (job.status === 5 ? 'activity' : 
                               (job.status === 6 ? 'ban' : 
                               (job.status === 1 ? 'clock' : 'activity'))));
            const iconColor = job.status === 3 ? '#4ade80' : 
                               (job.status === 4 ? '#f87171' : 
                               (job.status === 5 ? '#f59e0b' : 
                               (job.status === 6 ? '#8b949e' : 
                               (job.status === 1 ? '#f59e0b' : 'var(--primary)'))));

            card.innerHTML = `
                <div class="job-summary-header" onclick="this.parentElement.classList.toggle('expanded')">
                    <div class="job-summary-left">
                        <i data-lucide="chevron-down" size="14" class="summary-chevron"></i>
                        <i data-lucide="${headerIcon}" size="16" style="color:${iconColor};"></i>
                        <span class="job-summary-title">${job.instance_label || job.group} <span style="opacity:0.4; font-weight:400; font-size: 0.7rem; margin-left:5px;">» ${job.entity.toUpperCase()}</span></span>
                    </div>
                    <div class="job-summary-right">
                        <span class="status-badge monitoring-priority-badge">P${job.priority ?? 0}</span>
                        <span class="job-id-tag">#${job.id}</span>
                        <div class="job-latest-activity">
                            <div style="font-size:0.6rem; text-transform:uppercase; font-weight:700;">Latest Activity</div>
                            ${job.updated_at || job.created_at}
                        </div>
                    </div>
                </div>

                <div class="job-details-pane">
                    <div class="job-header-flex" style="border:none; margin-bottom:0;">
                         <div class="job-title-path">
                            ${job.channel || 'N/A'} <span class="job-path-sep">»</span> <span class="job-entity-name">${job.type || job.entity.toUpperCase()}</span>
                        </div>
                        <div>
                            <span class="status-badge ${statusClass}">${job.status_text.toUpperCase()}</span>
                        </div>
                    </div>

                    <div class="job-metric-grid">
                        <div class="job-metric-item">
                            <span class="job-metric-label">FREQUENCY (CRON)</span>
                            <div class="job-metric-value highlight">${job.frequency || 'N/A'}</div>
                        </div>
                        <div class="job-metric-item">
                            <span class="job-metric-label">CREATED AT</span>
                            <div class="job-metric-value">${job.created_at}</div>
                        </div>
                        <div class="job-metric-item">
                            <span class="job-metric-label">EXECUTION TIME</span>
                            <div class="job-metric-value highlight">${job.execution_time || '0s'}</div>
                        </div>
                        <div class="job-metric-item">
                            <span class="job-metric-label">UPDATED AT</span>
                            <div class="job-metric-value">${job.updated_at}</div>
                        </div>
                        <div class="job-metric-item">
                            <span class="job-metric-label">ACCOUNT SCOPE</span>
                            <div class="job-metric-value">${formatAccountLabel(job.account_id)}</div>
                        </div>
                        <div class="job-metric-item">
                            <span class="job-metric-label">TIMEFRAME</span>
                            <div class="job-metric-value highlight">${formatTimeframe(job.start_date, job.end_date)}</div>
                        </div>
                    </div>

                    <span class="job-section-label">STATUS MESSAGE</span>
                    <div class="job-box job-status-box">${job.message || 'No extra info'}</div>

                    <span class="job-section-label">CONFIGURATION PAYLOAD</span>
                    <div class="job-box job-payload-box">${JSON.stringify(job.params, null, 4)}</div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top: 1px solid rgba(255,255,255,0.03); padding-top:15px;">
                       <div style="display:flex; flex-direction:column; gap:4px;">
                           <span class="job-metric-label" style="margin:0;">PIPELINE HISTORY</span>
                           <div class="job-history-dots" style="margin:0; border:none; padding:0;">
                              ${(job.history || []).map(h => {
                                   let dotStatusClass = 'unknown';
                                   if (h.status === 1) dotStatusClass = 'scheduled';
                                   else if (h.status === 2) dotStatusClass = 'processing';
                                   else if (h.status === 3) dotStatusClass = 'completed';
                                   else if (h.status === 4) dotStatusClass = 'failed';
                                   else if (h.status === 5) dotStatusClass = 'delayed';
                                   else if (h.status === 6) dotStatusClass = 'cancelled';
                                   return `<div class="history-dot dot-${dotStatusClass}" title="${h.date}: ${h.message}"></div>`;
                               }).join('')}
                           </div>
                       </div>
                        <div style="display:flex; gap:8px;">
                           <div class="monitoring-priority-controls">
                               <button onclick="event.stopPropagation(); adjustJobPriority(${job.id}, -1)" 
                                       class="btn btn-mini monitoring-priority-btn-down"
                                       title="Lower queue priority">
                                   <i data-lucide="arrow-down" size="14"></i>
                               </button>
                               <span class="monitoring-priority-value">${job.priority ?? 0}</span>
                               <button onclick="event.stopPropagation(); adjustJobPriority(${job.id}, 1)" 
                                       class="btn btn-mini monitoring-priority-btn-up"
                                       title="Raise queue priority">
                                   <i data-lucide="arrow-up" size="14"></i>
                               </button>
                           </div>
                           ${(job.status === 1 || job.status === 4 || job.status === 5) ? `
                               <button onclick="event.stopPropagation(); runJobNow(${job.id})" 
                                       class="btn btn-mini" 
                                       style="background: rgba(16, 185, 129, 0.15); border-color: rgba(16, 185, 129, 0.2); color: #10b981; padding: 8px 12px;" 
                                       title="Run now (bypass cron cycle)">
                                   <i data-lucide="play" size="14"></i>
                               </button>
                           ` : ''}
                           <button onclick="event.stopPropagation(); processJob(${job.id})" 
                                   class="btn btn-mini ${!canReschedule ? 'disabled-btn' : ''}" 
                                   ${!canReschedule ? 'disabled' : ''}
                                   style="background: rgba(88, 166, 255, 0.1); border-color: rgba(88, 166, 255, 0.2); padding: 8px 15px; color: var(--primary); display:flex; align-items:center; gap:6px;">
                               <i data-lucide="calendar-plus" size="14"></i>
                               <span style="font-size:0.75rem; font-weight:700;">Re-schedule</span>
                           </button>
                           <button onclick="event.stopPropagation(); cancelJob(${job.id})" 
                                   class="btn btn-mini ${!canCancel ? 'disabled-btn' : ''}" 
                                   ${!canCancel ? 'disabled' : ''}
                                   style="background: rgba(248, 81, 73, 0.1); border-color: rgba(248, 81, 73, 0.2); color:var(--danger); padding: 8px 12px;" 
                                   title="Abort synchronization">
                               <i data-lucide="trash-2" size="14"></i>
                           </button>
                        </div>
                    </div>
                </div>
            `;
            cardContainer.appendChild(card);
        });

        list.appendChild(section);
    });

    lucide.createIcons();
}

/** Interactive Actions **/
let activeJobId = null;

function processJob(id) {
    activeJobId = id;
    const modal = document.getElementById('job-confirmation-modal');
    const jobNameEl = document.getElementById('modal-job-name');
    const jobIdEl = document.getElementById('modal-job-id');

    // Find job details in currentData mapping
    let foundJob = null;
    if (currentData && currentData.groupedJobs) {
        Object.values(currentData.groupedJobs).forEach(jobs => {
            const found = jobs.find(j => j.id === id);
            if (found) foundJob = found;
        });
    }

    jobNameEl.textContent = foundJob ? `${foundJob.entity.toUpperCase()} (${foundJob.channel ? foundJob.channel.toUpperCase() : 'N/A'})` : `JOB #${id}`;
    jobIdEl.textContent = id;
    
    // Default options
    document.getElementById('smart-caching-check').checked = true;
    document.getElementById('bypass-dependency-check').checked = false;

    modal.style.display = 'flex';
}

function closeJobModal() {
    document.getElementById('job-confirmation-modal').style.display = 'none';
}

async function confirmReschedule() {
    if (!activeJobId) return;
    
    const resume = document.getElementById('smart-caching-check').checked;
    const bypass = document.getElementById('bypass-dependency-check').checked;
    
    try {
        const response = await fetch('/api/monitoring/jobs/action', {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify({
                action: 'retry',
                id: activeJobId,
                resume: resume,
                bypass_dependency: bypass
            })
        });

        const res = await response.json();
        if (res.success || res.status === 'success') {
            closeJobModal();
            fetchData(); // Reload
        } else {
            alert('Error rescheduling job: ' + (res.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Network error: ' + e.message);
    }
}

async function cancelJob(id) {
    activeJobId = id;
    const modal = document.getElementById('cancel-confirmation-modal');
    const jobNameEl = document.getElementById('modal-cancel-job-name');
    const jobIdEl = document.getElementById('modal-cancel-job-id');

    // Find job details in currentData mapping
    let foundJob = null;
    if (currentData && currentData.groupedJobs) {
        Object.values(currentData.groupedJobs).forEach(jobs => {
            const found = jobs.find(j => j.id === id);
            if (found) foundJob = found;
        });
    }

    jobNameEl.textContent = foundJob ? `${foundJob.entity.toUpperCase()} (${foundJob.channel ? foundJob.channel.toUpperCase() : 'N/A'})` : `JOB #${id}`;
    jobIdEl.textContent = id;
    
    modal.style.display = 'flex';
}

function closeCancelModal() {
    document.getElementById('cancel-confirmation-modal').style.display = 'none';
}

async function confirmCancelAction() {
    if (!activeJobId) return;

    try {
        const response = await fetch('/api/monitoring/jobs/action', {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify({ action: 'cancel', id: activeJobId })
        });
        const res = await response.json();
        if (res.success || res.status === 'success') {
            closeCancelModal();
            fetchData(); // Reload
        } else {
            alert('Error cancelling job: ' + (res.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Network error: ' + e.message);
    }
}

async function adjustJobPriority(id, delta) {
    try {
        const response = await fetch('/api/monitoring/jobs/action', {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify({ action: 'priority_adjust', id, delta })
        });

        const res = await response.json();
        if (res.success || res.status === 'success') {
            showToast(`Priority updated to ${res.priority ?? 'new value'}`, false);
            fetchData();
        } else {
            alert('Error updating priority: ' + (res.error || 'Unknown error'));
        }
    } catch (e) {
        alert('Network error: ' + e.message);
    }
}

// Bind buttons
document.addEventListener('DOMContentLoaded', () => {
    const confirmBtn = document.getElementById('confirm-reschedule-btn');
    if (confirmBtn) confirmBtn.onclick = confirmReschedule;
    
    const confirmCancelBtn = document.getElementById('confirm-cancel-btn');
    if (confirmCancelBtn) confirmCancelBtn.onclick = confirmCancelAction;

    const confirmRunNowBtn = document.getElementById('confirm-run-now-btn');
    if (confirmRunNowBtn) confirmRunNowBtn.onclick = confirmRunNowAction;
});


/**
 * Force manual execution of a specific sync pipeline instance.
 * @param {string} channel 
 * @param {string} entity 
 * @param {string} instanceId 
 */
async function triggerSyncInstance(channel, entity, instanceId) {
    if (!confirm(`Are you sure you want to FORCE EXECUTION for ${instanceId}?\nThis will bypass the scheduler and run a full sync now.`)) {
        return;
    }

    showToast(`Triggering ${instanceId} sync...`, false);

    try {
        const response = await fetch(`/cache/${channel}/${entity}`, {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify({
                instance_name: instanceId
            })
        });

        const data = await response.json();

        if (response.ok) {
            showToast('Success: Pipeline triggered in background.', false);
            // Auto-refresh data to see the new job appearing
            if (typeof fetchMonitoringData === 'function') {
                setTimeout(fetchMonitoringData, 1500);
            } else if (typeof fetchData === 'function') {
                setTimeout(fetchData, 1500);
            }
        } else {
            throw new Error(data.error || 'Server error occurred');
        }
    } catch (e) {
        console.error("Force Sync Error:", e);
        showToast(`Failed to trigger sync: ${e.message}`, true);
    }
}

/**
 * Display a premium notification toast.
 * @param {string} msg 
 * @param {boolean} isError 
 */
function showToast(msg, isError) {
    const toast = document.createElement('div');
    toast.className = 'toast animate-slide-in';
    if (isError) toast.classList.add('error');
    toast.innerHTML = `
        <i data-lucide="${isError ? 'alert-circle' : 'check-circle'}" size="16"></i>
        <span>${msg}</span>
    `;
    document.body.appendChild(toast);
    lucide.createIcons();
    setTimeout(() => {
        toast.classList.add('animate-slide-out');
        setTimeout(() => toast.remove(), 500);
    }, 4000);
}

/**
 * Process a specific job immediately in the background.
 * @param {number} id 
 */
async function runJobNow(id) {
    activeJobId = id;
    const modal = document.getElementById('run-now-confirmation-modal');
    const jobNameEl = document.getElementById('modal-run-job-name');
    const jobIdEl = document.getElementById('modal-run-job-id');

    // Find job details to display in modal
    let foundJob = null;
    if (currentData && currentData.groupedJobs) {
        Object.values(currentData.groupedJobs).forEach(jobs => {
            const found = jobs.find(j => j.id === id);
            if (found) foundJob = found;
        });
    }

    jobNameEl.textContent = foundJob ? `${foundJob.instance_label || foundJob.group} » ${foundJob.entity.toUpperCase()}` : `JOB #${id}`;
    jobIdEl.textContent = id;
    
    modal.style.display = 'flex';
}

function closeRunNowModal() {
    document.getElementById('run-now-confirmation-modal').style.display = 'none';
}

async function confirmRunNowAction() {
    if (!activeJobId) return;

    closeRunNowModal();
    showToast(`Triggering Job #${activeJobId}...`, false);

    try {
        const response = await fetch('/api/monitoring/jobs/action', {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify({
                action: 'process',
                id: activeJobId
            })
        });

        const data = await response.json();

        if (response.ok) {
            showToast(`Success: Job #${activeJobId} triggered.`, false);
            setTimeout(fetchData, 1000);
        } else {
            throw new Error(data.error || 'Server error occurred');
        }
    } catch (e) {
        console.error("Run Job Error:", e);
        showToast(`Failed to trigger job: ${e.message}`, true);
    }
}

// Global Exports
window.processJob = processJob;
window.cancelJob = cancelJob;
window.runJobNow = runJobNow;
window.adjustJobPriority = adjustJobPriority;
window.closeRunNowModal = closeRunNowModal;
window.confirmRunNowAction = confirmRunNowAction;
window.triggerSyncInstance = triggerSyncInstance;
window.showToast = showToast;

// Initialize
document.addEventListener('DOMContentLoaded', initMonitoring);
