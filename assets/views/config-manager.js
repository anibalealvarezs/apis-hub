/** 
 * APIs Hub | Configuration Manager Logic ⚙️
 * Multi-Channel Management & Dynamic Rule Engine.
 */

let currentConfig = null;

// --- Initialization ---
async function initConfig() {
    console.log("Initializing Configuration Engine...");
    lucide.createIcons();
    setupTabSystem();
    await fetchConfig();
    setupEventListeners();
}

function setupTabSystem() {
    const tabs = document.querySelectorAll('.tab');
    const contents = document.querySelectorAll('.tab-content');

    tabs.forEach(tab => {
        tab.onclick = () => {
            tabs.forEach(t => t.classList.remove('active'));
            contents.forEach(c => c.style.display = 'none');
            
            tab.classList.add('active');
            const target = document.getElementById('tab-' + tab.dataset.tab);
            if (target) target.style.display = 'block';
        };
    });
}

function getAdminHeaders() {
    const envMeta = document.querySelector('meta[name="app-env"]');
    const isDemo = (envMeta && envMeta.getAttribute('content') === 'demo') ||
                   window.AUTH_BYPASS === true;
                   
    const EXPIRATION_TIME = 24 * 60 * 60 * 1000;
    const now = Date.now();
    let auth = JSON.parse(localStorage.getItem('apis_hub_admin_auth') || '{}');
    
    // Bypass prompt for Demo Mode
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

async function fetchConfig() {
    try {
        const response = await fetch('/api/config-manager/assets', { headers: getAdminHeaders() });
        if (response.status === 401) {
            localStorage.removeItem('apis_hub_admin_auth');
            alert('Session Expired. Please reload.');
            return;
        }
        
        const data = await response.json();
        console.log("Full data loaded from backend:", data);
        
        if (!data || !data.config) {
            throw new Error("Invalid response: config object missing.");
        }
        
        currentConfig = data.config;

        populateGlobalFields();
        renderAssets(data.assets);
        validateTokens(false);
        
    } catch (error) {
        console.error("Fetch Config Error:", error);
        showToast('Error loading configuration: ' + error.message, true);
    }
}

function populateGlobalFields() {
    if (!currentConfig) return;
    
    console.log("Populating global fields with data:", currentConfig);
    
    try {
        // Infrastructure Settings
        const dbHostEl = document.getElementById('db-host');
        if (dbHostEl) dbHostEl.value = currentConfig.db_host || '';
        
        const dbNameEl = document.getElementById('db-name');
        if (dbNameEl) dbNameEl.value = currentConfig.db_name || '';
        
        const appModeEl = document.getElementById('app-mode');
        if (appModeEl) appModeEl.value = currentConfig.app_mode || 'production';

        // Status Toggles
        const gscStatus = document.getElementById('gsc-channel-enabled');
        if (gscStatus) gscStatus.checked = !!currentConfig.gsc_enabled;

        const fbOrganicStatus = document.getElementById('fb-organic-enabled');
        if (fbOrganicStatus) fbOrganicStatus.checked = !!currentConfig.fb_organic_enabled;

        const fbMarketingStatus = document.getElementById('fb-marketing-enabled');
        if (fbMarketingStatus) fbMarketingStatus.checked = !!currentConfig.fb_marketing_enabled;

        // Windows & Ranges
        const gscRange = document.getElementById('gsc-history-range');
        if (gscRange) gscRange.value = currentConfig.gsc_cache_history_range || '16 months';
        
        const fbOrgRange = document.getElementById('fb-organic-history-range');
        if (fbOrgRange) fbOrgRange.value = currentConfig.fb_organic_history_range || '2 years';
        
        const fbMarkRange = document.getElementById('fb-marketing-history-range');
        if (fbMarkRange) fbMarkRange.value = currentConfig.fb_marketing_history_range || '2 years';

        // Job & Analytics Settings
        const timeoutEl = document.getElementById('jobs-timeout-hours');
        if (timeoutEl) timeoutEl.value = currentConfig.jobs_timeout_hours || 6;
        
        const rawMetricsEl = document.getElementById('cache-raw-metrics');
        if (rawMetricsEl) rawMetricsEl.checked = !!currentConfig.cache_raw_metrics;

        // Extraction Granularity (Conceptual Separation)
        const fbLevelEl = document.getElementById('fb-marketing-level');
        const fbMetricsLevelEl = document.getElementById('fb-marketing-metrics-level');
        const t = currentConfig.fb_feature_toggles || {};

        if (fbLevelEl) {
            let entLevel = 'ad_account';
            if (t.creatives) entLevel = 'creative';
            else if (t.ads) entLevel = 'ad';
            else if (t.adsets) entLevel = 'adset';
            else if (t.campaigns) entLevel = 'campaign';
            fbLevelEl.value = entLevel;
        }

        if (fbMetricsLevelEl) {
            let metLevel = 'ad_account';
            if (t.creative_metrics) metLevel = 'creative';
            else if (t.ad_metrics) metLevel = 'ad';
            else if (t.adset_metrics) metLevel = 'adset';
            else if (t.campaign_metrics) metLevel = 'campaign';
            fbMetricsLevelEl.value = metLevel;
        }
        
        // Strategy Selection
        const strategy = currentConfig.fb_metrics_strategy || 'default';
        const strategyRadio = document.getElementById('fb-strategy-' + strategy);
        if (strategyRadio) strategyRadio.checked = true;
        
        handleFbLevelChange();
        handleFbStrategyChange();
        renderCustomMetricsGrid();
        
    } catch (e) {
        console.error("Error in populateGlobalFields:", e);
    }
}

function handleFbLevelChange() {
    const entEl = document.getElementById('fb-marketing-level');
    const metEl = document.getElementById('fb-marketing-metrics-level');
    const levels = ['ad_account', 'campaign', 'adset', 'ad', 'creative'];
    
    if (!entEl || !metEl) return;

    const entIdx = levels.indexOf(entEl.value);

    // RESTRICTION RULE: Disable metric options deeper than current entity depth
    Array.from(metEl.options).forEach((opt, idx) => {
        if (idx > entIdx) {
            opt.disabled = true;
            opt.style.opacity = '0.3';
        } else {
            opt.disabled = false;
            opt.style.opacity = '1';
        }
    });

    // Clamp metrics level if it exceeds current infrastructure
    if (levels.indexOf(metEl.value) > entIdx) {
        metEl.value = entEl.value;
    }

    const visLevels = ['campaign', 'adset', 'ad', 'creative'];
    
    // Sync Entity Indicators (infrastructure)
    const entActiveIdx = visLevels.indexOf(entEl.value) + 1;
    document.querySelectorAll('[id^="ent-ind-"]').forEach((ind, i) => {
        ind.className = 'level-indicator-dot ' + (i < entActiveIdx ? 'active' : 'inactive');
    });

    // Metrics Indicators (reporting)
    const metActiveIdx = visLevels.indexOf(metEl.value) + 1;
    document.querySelectorAll('[id^="met-ind-"]').forEach((ind, i) => {
        ind.className = 'level-indicator-dot ' + (i < metActiveIdx ? 'active' : 'inactive');
    });
}

function setFbLevel(lvl) {
    const el = document.getElementById('fb-marketing-level');
    if (el) {
        el.value = lvl;
        handleFbLevelChange();
    }
}

function setMetricLevel(lvl) {
    const el = document.getElementById('fb-marketing-metrics-level');
    const entEl = document.getElementById('fb-marketing-level');
    const order = ['ad_account', 'campaign', 'adset', 'ad', 'creative'];
    
    if (el && entEl) {
        if (order.indexOf(lvl) <= order.indexOf(entEl.value)) {
            el.value = lvl;
            handleFbLevelChange();
        } else {
            showToast("Sync Depth (Infrastructure) must be >= Metrics Level", true);
        }
    }
}

function renderCustomMetricsGrid() {
    const container = document.getElementById('fb-metrics-custom-grid');
    if (!container) return;
    container.innerHTML = '';

    const metrics = [
        "spend", "clicks", "impressions", "reach", "frequency", "ctr", "cpc", "cpm", 
        "results", "cost_per_result", "result_rate", "purchase_roas"
    ];

    metrics.forEach(m => {
        const cfg = currentConfig.fb_metrics_config?.[m] || { enabled: false, format: 'number', sparkline: false };
        const card = document.createElement('div');
        card.className = 'metric-config-card ' + (cfg.enabled ? 'active' : '');
        card.style.cursor = 'default';
        card.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                <span class="metric-name-label" style="font-weight:700; color:#fff; font-size: 0.8rem;">${m.toUpperCase().replace(/_/g, ' ')}</span>
                <div style="display:flex; align-items:center; gap:12px;">
                    <div style="display:flex; align-items:center; gap:6px;" title="Enable Sparkline (Trend Graph)">
                        <i data-lucide="trending-up" size="14" style="color:var(--text-dim);"></i>
                        <label class="switch-mini">
                            <input type="checkbox" class="metric-sparkline" ${cfg.sparkline ? 'checked' : ''}>
                            <span class="slider-mini"></span>
                        </label>
                    </div>
                    <div style="width: 1px; height: 16px; background: var(--border); margin: 0 4px;"></div>
                    <label class="switch-mini" title="Enable Metric">
                        <input type="checkbox" class="metric-enable" ${cfg.enabled ? 'checked' : ''} onchange="this.closest('.metric-config-card').classList.toggle('active', this.checked)">
                        <span class="slider-mini"></span>
                    </label>
                </div>
            </div>
            
            <div style="display:flex; gap:10px; margin-bottom:12px;">
                <div style="flex:1;">
                    <div class="rule-group-label">Format</div>
                    <select class="metric-format" style="width:100%; font-size:0.75rem; padding:4px 8px;">
                        <option value="number" ${cfg.format === 'number' ? 'selected' : ''}>Number</option>
                        <option value="currency" ${cfg.format === 'currency' ? 'selected' : ''}>Currency</option>
                        <option value="percent" ${cfg.format === 'percent' ? 'selected' : ''}>Percent</option>
                    </select>
                </div>
                <div style="width:50px;">
                    <div class="rule-group-label">Dec.</div>
                    <input type="number" class="metric-precision" value="${cfg.precision !== undefined ? cfg.precision : (cfg.format === 'currency' || cfg.format === 'percent' || m === 'frequency' ? 2 : 0)}" min="0" max="4" style="width:100%; font-size:0.75rem; padding:3px 6px;">
                </div>
            </div>

            <div class="rule-group-label" style="display:flex; justify-content:space-between; align-items:center;">
                Rules
                <button class="btn-mini" onclick="addMetricRule('${m}')" title="Add Rule"><i data-lucide="plus" size="10"></i></button>
            </div>
            <div id="rules-${m}" class="rules-container" style="margin-top:10px;"></div>
        `;
        container.appendChild(card);
        
        const cond = cfg.conditional || {};
        const rules = cond.config || [];
        if (rules.length > 0) {
            rules.forEach(rule => addMetricRule(m, rule));
        }
    });
    lucide.createIcons();
}

/**
 * Normalizes backend style classes to the 4 UI levels.
 */
function getUILevelFromClass(cls) {
    if (!cls) return 'excellent';
    const c = cls.toLowerCase();
    if (c.includes('excellent')) return 'excellent';
    if (c.includes('good')) return 'good';
    if (c.includes('normal') || c.includes('average') || c.includes('warning')) return 'average';
    if (c.includes('bad') || c.includes('grave')) return 'bad';
    return 'excellent'; // Default for new or unknown
}

function addMetricRule(metric, rule = null) {
    const container = document.getElementById(`rules-${metric}`);
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'rule-item-grid';
    div.style.gridTemplateColumns = 'repeat(3, 1fr) 20px';
    
    const uiLevel = getUILevelFromClass(rule?.class);
    
    const selectHtml = `
        <select class="rule-class" style="padding:2px 4px; font-size:0.7rem; width:100%;">
            <option value="excellent" ${uiLevel === 'excellent' ? 'selected' : ''}>Excellent</option>
            <option value="good" ${uiLevel === 'good' ? 'selected' : ''}>Good</option>
            <option value="average" ${uiLevel === 'average' ? 'selected' : ''}>Average</option>
            <option value="bad" ${uiLevel === 'bad' ? 'selected' : ''}>Bad</option>
        </select>
    `;

    div.innerHTML = `
        <div>
            <div class="rule-group-label" style="font-size:0.5rem;">Min</div>
            <input type="number" step="0.0001" class="rule-min" value="${rule?.min !== undefined ? rule.min : 0}" style="width:100%; padding:2px 4px; font-size:0.7rem;">
        </div>
        <div>
            <div class="rule-group-label" style="font-size:0.5rem;">Max</div>
            <input type="number" step="0.0001" class="rule-max" value="${rule?.max !== undefined ? rule.max : 0}" style="width:100%; padding:2px 4px; font-size:0.7rem;">
        </div>
        <div>
            <div class="rule-group-label" style="font-size:0.5rem;">Style</div>
            ${selectHtml}
        </div>
        <button onclick="this.closest('.rule-item-grid').remove()" style="background:none; border:none; color:var(--danger); cursor:pointer; margin-top:10px;"><i data-lucide="trash-2" size="12"></i></button>
    `;
    container.appendChild(div);
    lucide.createIcons();
}

function renderAssets(assets) {
    if (!assets) return;
    
    const gscList = document.getElementById('gsc-list');
    const fbOrganicList = document.getElementById('fb-pages-list');
    const fbMarketingList = document.getElementById('fb-ad-accounts-list');
    
    if (gscList) {
        gscList.innerHTML = '';
        const props = assets.gsc || [];
        if (props.length === 0) gscList.innerHTML = '<div class="empty-state">No GSC properties found.</div>';
        props.forEach(p => {
            const isSynced = currentConfig.gsc?.[p.url] !== undefined;
            const displayUrl = p.url.replace('sc-domain:', '');
            const div = document.createElement('div');
            div.className = 'asset-item ' + (isSynced ? 'synced' : '');
            div.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
                   <div class="asset-text-truncate" title="${displayUrl}" style="font-size:0.75rem; font-weight:600; color:#fff; flex:1; min-width:0;">${displayUrl}</div>
                   <label class="switch-mini">
                       <input type="checkbox" class="gsc-asset-sync" value="${p.url}" ${isSynced ? 'checked' : ''} onchange="this.closest('.asset-item').classList.toggle('synced', this.checked)">
                       <span class="slider-mini"></span>
                   </label>
                </div>
            `;
            gscList.appendChild(div);
        });
    }

    if (fbOrganicList) {
        fbOrganicList.innerHTML = '';
        const pages = assets.facebook_pages || [];
        if (pages.length === 0) fbOrganicList.innerHTML = '<div class="empty-state">No Facebook pages found.</div>';
        pages.forEach(p => {
            const isSynced = currentConfig.fb_page_ids?.includes(String(p.id));
            const div = document.createElement('div');
            div.className = 'asset-item ' + (isSynced ? 'synced' : '');
            div.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center;">
                   <div style="font-size:0.75rem; font-weight:600; color:#fff;">${p.title}</div>
                   <label class="switch-mini">
                       <input type="checkbox" class="fb-organic-asset-sync" value="${p.id}" ${isSynced ? 'checked' : ''} onchange="this.closest('.asset-item').classList.toggle('synced', this.checked)">
                       <span class="slider-mini"></span>
                   </label>
                </div>
            `;
            fbOrganicList.appendChild(div);
        });
    }

    if (fbMarketingList) {
        fbMarketingList.innerHTML = '';
        const accounts = assets.facebook_ad_accounts || [];
        // Sort alphabetically by name
        accounts.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        
        if (accounts.length === 0) fbMarketingList.innerHTML = '<div class="empty-state">No Ad accounts found.</div>';
        accounts.forEach(a => {
            const isSynced = currentConfig.fb_ad_account_ids?.includes(String(a.id));
            const div = document.createElement('div');
            div.className = 'asset-item ' + (isSynced ? 'synced' : '');
            div.innerHTML = `
                <div style="display:flex; justify-content:space-between; align-items:center;">
                   <div>
                       <div style="font-size:0.75rem; font-weight:600; color:#fff;">${a.name}</div>
                       <div style="font-size:0.6rem; color:var(--text-dim); font-family:'Fira Code';">${a.id}</div>
                   </div>
                   <label class="switch-mini">
                       <input type="checkbox" class="fb-marketing-asset-sync" value="${a.id}" ${isSynced ? 'checked' : ''} onchange="this.closest('.asset-item').classList.toggle('synced', this.checked)">
                       <span class="slider-mini"></span>
                   </label>
                </div>
            `;
            fbMarketingList.appendChild(div);
        });
    }
}

function toggleAllAds() {
    const checkboxes = document.querySelectorAll('.fb-marketing-asset-sync');
    const firstChecked = checkboxes.length > 0 && checkboxes[0].checked;
    checkboxes.forEach(cb => {
        cb.checked = !firstChecked;
        const item = cb.closest('.asset-item');
        if (item) item.classList.toggle('synced', !firstChecked);
    });
}

async function updateConfig(typeArg) {
    const loader = document.getElementById('loading');
    if (loader) loader.style.display = 'flex';

    try {
        const payload = {
            type: typeArg || 'global',
            assets: { pages: [], ad_accounts: [], gsc: [] },
            feature_toggles: {}
        };

        // Assets Sync Status
        document.querySelectorAll('.gsc-asset-sync:checked').forEach(el => {
            payload.assets.gsc.push({ url: el.value, target_countries: [], target_keywords: [] });
        });

        document.querySelectorAll('.fb-organic-asset-sync:checked').forEach(el => {
            payload.assets.pages.push({ id: el.value });
        });

        document.querySelectorAll('.fb-marketing-asset-sync:checked').forEach(el => {
            payload.assets.ad_accounts.push({ id: el.value });
        });

        if (typeArg === 'gsc') {
            payload.enabled = document.getElementById('gsc-channel-enabled').checked;
            payload.cache_history_range = document.getElementById('gsc-history-range').value;
        } else if (typeArg === 'facebook-organic') {
            payload.enabled = document.getElementById('fb-organic-enabled').checked;
            payload.organic_history_range = document.getElementById('fb-organic-history-range').value;
        } else if (typeArg === 'facebook-marketing') {
            payload.enabled = document.getElementById('fb-marketing-enabled').checked;
            payload.marketing_history_range = document.getElementById('fb-marketing-history-range').value;
            
            const entLevel = document.getElementById('fb-marketing-level')?.value || 'ad_account';
            const metLevel = document.getElementById('fb-marketing-metrics-level')?.value || 'ad_account';

            // Feature Toggles (Infrastructure)
            payload.feature_toggles.campaigns = true;
            payload.feature_toggles.adsets = (entLevel === 'adset' || entLevel === 'ad' || entLevel === 'creative');
            payload.feature_toggles.ads = (entLevel === 'ad' || entLevel === 'creative');
            payload.feature_toggles.creatives = (entLevel === 'creative');

            // Feature Toggles (Metrics)
            payload.feature_toggles.ad_account_metrics = true;
            payload.feature_toggles.campaign_metrics = (metLevel === 'campaign' || metLevel === 'adset' || metLevel === 'ad' || metLevel === 'creative');
            payload.feature_toggles.adset_metrics = (metLevel === 'adset' || metLevel === 'ad' || metLevel === 'creative');
            payload.feature_toggles.ad_metrics = (metLevel === 'ad' || metLevel === 'creative');
            payload.feature_toggles.creative_metrics = (metLevel === 'creative');

            const stratCustom = document.getElementById('fb-strategy-custom');
            payload.metrics_strategy = stratCustom && stratCustom.checked ? 'custom' : 'default';

            payload.metrics_config = {};
            document.querySelectorAll('.metric-config-card').forEach(card => {
                const nameEl = card.querySelector('.metric-name-label');
                if (!nameEl) return;
                const name = nameEl.textContent.toLowerCase().replace(/ /g, '_');
                const enabled = card.querySelector('.metric-enable').checked;
                const sparkline = card.querySelector('.metric-sparkline').checked;
                const format = card.querySelector('.metric-format').value;
                const precision = parseInt(card.querySelector('.metric-precision').value || 0);
                const rules = [];

                card.querySelectorAll('.rule-item-grid').forEach(ri => {
                    const classValue = ri.querySelector('.rule-class').value;
                    const finalClass = 'badge-' + classValue;
                    
                    rules.push({
                        min: parseFloat(ri.querySelector('.rule-min').value || 0),
                        max: parseFloat(ri.querySelector('.rule-max').value || 0),
                        class: finalClass
                    });
                });

                payload.metrics_config[name] = {
                    enabled,
                    sparkline,
                    format,
                    precision,
                    conditional: {
                        enabled: rules.length > 0,
                        config: rules
                    }
                };
            });
        }

        // Global Infrastructure (Read-only fields are NOT included in payload)
        payload.jobs_timeout_hours = document.getElementById('jobs-timeout-hours')?.value;
        payload.cache_raw_metrics = document.getElementById('cache-raw-metrics')?.checked;

        const response = await fetch('/api/config-manager/update', {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify(payload)
        });

        const res = await response.json();
        if (res.success) {
            showToast('Configuration updated successfully', false);
            await fetchConfig();
        } else throw new Error(res.error);

    } catch (err) {
        showToast('Error: ' + err.message, true);
    } finally {
        if (loader) loader.style.display = 'none';
    }
}

async function validateTokens(isManual = true) {
    const loader = document.getElementById('loading');
    if (loader && isManual) loader.style.display = 'flex';
    try {
        const response = await fetch('/api/config-manager/validate-tokens', {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify({ type: 'all' })
        });
        const res = await response.json();
        const gscStatusBadge = document.getElementById('gsc-token-status-main');
        const fbStatusBadge = document.getElementById('fb-token-status-main');

        if (gscStatusBadge) {
            const gscRes = res.results?.gsc;
            if (gscRes && gscRes.status === 'valid') {
                gscStatusBadge.innerHTML = '<span style="color:#238636; vertical-align:middle;"><i data-lucide="check-circle" size="12" style="margin-bottom:2px"></i> GSC</span>';
                gscStatusBadge.style.display = 'inline-block';
            } else {
                gscStatusBadge.innerHTML = '<span style="color:#f85149; vertical-align:middle;"><i data-lucide="x-circle" size="12" style="margin-bottom:2px"></i> GSC</span>';
                gscStatusBadge.style.display = 'inline-block';
            }
        }

        if (fbStatusBadge) {
            const fbRes = res.results?.facebook;
            if (fbRes && fbRes.status === 'valid') {
                fbStatusBadge.innerHTML = '<span style="color:#238636; vertical-align:middle;"><i data-lucide="check-circle" size="12" style="margin-bottom:2px"></i> FB Graph</span>';
                fbStatusBadge.style.display = 'inline-block';
            } else {
                fbStatusBadge.innerHTML = '<span style="color:#f85149; vertical-align:middle;"><i data-lucide="x-circle" size="12" style="margin-bottom:2px"></i> FB Graph</span>';
                fbStatusBadge.style.display = 'inline-block';
            }
        }
        
        lucide.createIcons();
        if (isManual) showToast("Token validation completed", false);
    } catch (err) {
        if (isManual) showToast("Validation error: " + err.message, true);
    } finally {
        if (loader && isManual) loader.style.display = 'none';
    }
}

function showToast(msg, isError) {
    const toast = document.createElement('div');
    toast.className = 'toast ' + (isError ? 'error' : 'success');
    toast.innerHTML = `<i data-lucide="${isError ? 'alert-circle' : 'check-circle'}" size="16"></i> ${msg}`;
    document.body.appendChild(toast);
    lucide.createIcons();
    setTimeout(() => { toast.style.opacity = '1'; toast.style.transform = 'translateY(0)'; }, 10);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(20px)';
        setTimeout(() => toast.remove(), 500);
    }, 4000);
}

function setupEventListeners() {
}

function handleFbStrategyChange() {
    const customRadio = document.getElementById('fb-strategy-custom');
    const isCustom = customRadio ? customRadio.checked : false;
    const area = document.getElementById('fb-custom-metrics-area');
    if (area) area.style.display = isCustom ? 'block' : 'none';
}

// Global Hooks
window.setFbLevel = setFbLevel;
window.setMetricLevel = setMetricLevel;
window.toggleAllAds = toggleAllAds;
window.addMetricRule = addMetricRule;
window.updateConfig = updateConfig;
window.validateTokens = validateTokens;
window.handleFbLevelChange = handleFbLevelChange;
window.handleFbStrategyChange = handleFbStrategyChange;

// Initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initConfig);
} else {
    initConfig();
}
