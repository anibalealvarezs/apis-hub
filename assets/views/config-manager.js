/** 
 * APIs Hub | Configuration Manager Logic ⚙️
 * Multi-Channel Management & Dynamic Rule Engine.
 */

let currentConfig = null;

// --- Initialization ---
async function initConfig() {
    try {
        console.log("Initializing Configuration Engine...");
        lucide.createIcons();
        setupTabSystem();
        await fetchConfig();
        setupEventListeners();
    } catch (e) {
        console.error("Initialization Error:", e);
        showToast("Config Engine initialization failed. Global functions are still available.", true);
    }
}

function setupTabSystem() {
    const nav = document.getElementById('main-tab-nav');
    if (!nav) return;

    nav.onclick = (e) => {
        const tab = e.target.closest('.tab');
        if (!tab) return;
        
        const tabs = document.querySelectorAll('.tab');
        const contents = document.querySelectorAll('.tab-content');

        tabs.forEach(t => t.classList.remove('active'));
        contents.forEach(c => c.style.display = 'none');
        
        tab.classList.add('active');
        const target = document.getElementById('tab-' + tab.dataset.tab);
        if (target) target.style.display = 'block';
    };
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

function renderDynamicTabs(channels) {
    const nav = document.getElementById('main-tab-nav');
    const container = document.getElementById('dynamic-tabs-container');
    if (!nav || !container) return;

    // Infrastructure is always there static but we clear dynamic ones
    nav.querySelectorAll('.tab:not([data-tab="infrastructure"])').forEach(t => t.remove());
    container.innerHTML = '';
    
    channels.forEach(chan => {
        const tab = document.createElement('div');
        tab.className = 'tab';
        tab.dataset.tab = (chan === 'google_search_console' ? 'google' : (chan === 'facebook_organic' ? 'facebook' : (chan === 'facebook_marketing' ? 'facebook-marketing' : chan)));
        
        let icon = 'layers';
        let label = chan;
        
        const map = {
            'google_search_console': { icon: 'search', label: 'Google Search Console' },
            'google_analytics': { icon: 'bar-chart', label: 'Google Analytics' },
            'facebook_organic': { icon: 'facebook', label: 'Meta Organic' },
            'facebook_marketing': { icon: 'trending-up', label: 'Meta Marketing' },
            'shopify': { icon: 'shopping-bag', label: 'Shopify' },
            'klaviyo': { icon: 'mail', label: 'Klaviyo' },
            'netsuite': { icon: 'box', label: 'NetSuite' },
            'amazon': { icon: 'shopping-cart', label: 'Amazon SP-API' },
            'bigcommerce': { icon: 'shopping-cart', label: 'BigCommerce' },
            'tiktok': { icon: 'music', label: 'TikTok' },
            'triplewhale': { icon: 'whale', label: 'TripleWhale' }
        };

        if (map[chan]) {
            icon = map[chan].icon;
            label = map[chan].label;
        }

        tab.innerHTML = `<i data-lucide="${icon}"></i> ${label}`;
        nav.appendChild(tab);

        // Specialized tabs are already in HTML, we only add others to dynamic-tabs-container
        const tabId = 'tab-' + tab.dataset.tab;
        if (!document.getElementById(tabId)) {
             const content = document.createElement('div');
             content.id = tabId;
             content.className = 'tab-content';
             content.style.display = 'none';
             content.innerHTML = renderGenericChannelUI(chan, label, icon);
             container.appendChild(content);
        }
    });

    lucide.createIcons();
}

function renderGenericChannelUI(chan, label, icon) {
    return `
        <div class="settings-horizontal-grid">
            <div class="card compact-card" style="flex-direction:row; justify-content:space-between; align-items:center;">
                <div>
                    <div class="section-title small" style="margin-bottom:5px; border:none; padding:0;"><i data-lucide="power"></i> Channel Status</div>
                    <div style="font-size:0.75rem; color:var(--text-dim);">Enable ${label} Integration</div>
                </div>
                <label class="switch">
                    <input type="checkbox" id="${chan}-channel-enabled">
                    <span class="slider"></span>
                </label>
            </div>
            <div class="card compact-card">
                <div class="section-title small"><i data-lucide="calendar"></i> History Range</div>
                <div style="margin-top:auto">
                    <select id="${chan}-history-range" class="control-select" style="width:100%">
                        <option value="1 month">Last Month</option>
                        <option value="3 months">Last 3 Months</option>
                        <option value="6 months">Last 6 Months</option>
                        <option value="1 year" selected>Last 12 Months</option>
                        <option value="2 years">Last 2 Years</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="section-title small" style="display:flex; justify-content:space-between; align-items:center;">
                <span><i data-lucide="${icon}"></i> ${label} assets</span>
                <button class="btn btn-mini" onclick="forceRefresh('${chan}')" style="background:var(--primary);"><i data-lucide="refresh-cw" size="14"></i> Sync from Provider</button>
            </div>
            <div id="${chan}-list" class="asset-grid">
                 <div class="empty-state">No assets found for ${label}. Click Sync to fetch them.</div>
            </div>
        </div>
        <button class="btn btn-save-fixed" onclick="updateConfig('${chan}')"><i data-lucide="save"></i> Save ${label} Settings</button>
    `;
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

        renderDynamicTabs(data.available_channels);
        populateGlobalFields();
        renderAssets(data.assets);
        renderInfrastructureRules(data.config.infrastructure_rules, data.available_channels);
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
        if (rawMetricsEl) {
            rawMetricsEl.checked = !!currentConfig.cache_raw_metrics;
            toggleRawMetricsWarning(rawMetricsEl.checked);
        }

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

        // FB Organic Global Granularity
        const fbOrgLvlEl = document.getElementById('fb-organic-level');
        const fbIgLvlEl = document.getElementById('fb-ig-level');
        
        if (fbOrgLvlEl) {
            let lvl = 'page';
            if (t.post_metrics) lvl = 'post_metrics';
            else if (t.posts) lvl = 'posts';
            else if (t.page_metrics) lvl = 'page_metrics';
            fbOrgLvlEl.value = lvl;
        }

        if (fbIgLvlEl) {
            let lvl = 'none';
            if (t.ig_account_media_metrics) lvl = 'media_metrics';
            else if (t.ig_account_media) lvl = 'media';
            else if (t.ig_account_metrics) lvl = 'metrics';
            else if (t.ig_accounts) lvl = 'accounts';
            fbIgLvlEl.value = lvl;
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
        console.log("Setting up strategy selectors...");
        const strategy = currentConfig.fb_metrics_strategy || 'default';
        const strategyRadio = document.getElementById('fb-strategy-' + strategy);
        if (strategyRadio) strategyRadio.checked = true;
        
        // Entity Filters
        const filters = currentConfig.fb_entity_filters || {};
        console.log("DEBUG: FB Entity Filters Object from Controller:", JSON.stringify(filters));
        
        const map = {
            'fb-marketing-campaign-filter': filters.CAMPAIGN,
            'fb-marketing-adset-filter': filters.ADSET,
            'fb-marketing-ad-filter': filters.AD,
            'fb-marketing-creative-filter': filters.CREATIVE
        };
        
        console.log("DEBUG: Mapping Filters to UI Elements...", map);
        
        Object.entries(map).forEach(([id, val]) => {
            const el = document.getElementById(id);
            if (el) el.value = val || '';
        });
        
        // Cron Scheduling Hours (Global)
        const globalEntHourEl = document.getElementById('cron-entities-hour');
        if (globalEntHourEl) globalEntHourEl.value = (currentConfig.cron_entities_hour !== undefined && currentConfig.cron_entities_hour !== null) ? currentConfig.cron_entities_hour : 2;
        const globalEntMinEl = document.getElementById('cron-entities-minute');
        if (globalEntMinEl) globalEntMinEl.value = (currentConfig.cron_entities_minute !== undefined && currentConfig.cron_entities_minute !== null) ? currentConfig.cron_entities_minute : 0;

        const globalRecHourEl = document.getElementById('cron-recent-hour');
        if (globalRecHourEl) globalRecHourEl.value = (currentConfig.cron_recent_hour !== undefined && currentConfig.cron_recent_hour !== null) ? currentConfig.cron_recent_hour : 5;
        const globalRecMinEl = document.getElementById('cron-recent-minute');
        if (globalRecMinEl) globalRecMinEl.value = (currentConfig.cron_recent_minute !== undefined && currentConfig.cron_recent_minute !== null) ? currentConfig.cron_recent_minute : 0;

        // Cron Scheduling Hours (Channels)
        const gscCronHourEl = document.getElementById('gsc-cron-hour');
        if (gscCronHourEl) gscCronHourEl.value = (currentConfig.gsc_cron_recent_hour !== undefined && currentConfig.gsc_cron_recent_hour !== null) ? currentConfig.gsc_cron_recent_hour : 5;
        const gscCronMinEl = document.getElementById('gsc-cron-minute');
        if (gscCronMinEl) gscCronMinEl.value = (currentConfig.gsc_cron_recent_minute !== undefined && currentConfig.gsc_cron_recent_minute !== null) ? currentConfig.gsc_cron_recent_minute : 0;

        const fbOrgEntHourEl = document.getElementById('fb-organic-entities-cron-hour');
        if (fbOrgEntHourEl) fbOrgEntHourEl.value = (currentConfig.fb_organic_cron_entities_hour !== undefined && currentConfig.fb_organic_cron_entities_hour !== null) ? currentConfig.fb_organic_cron_entities_hour : 2;
        const fbOrgEntMinEl = document.getElementById('fb-organic-entities-cron-minute');
        if (fbOrgEntMinEl) fbOrgEntMinEl.value = (currentConfig.fb_organic_cron_entities_minute !== undefined && currentConfig.fb_organic_cron_entities_minute !== null) ? currentConfig.fb_organic_cron_entities_minute : 0;

        const fbOrgRecHourEl = document.getElementById('fb-organic-recent-cron-hour');
        if (fbOrgRecHourEl) fbOrgRecHourEl.value = (currentConfig.fb_organic_cron_recent_hour !== undefined && currentConfig.fb_organic_cron_recent_hour !== null) ? currentConfig.fb_organic_cron_recent_hour : 6;
        const fbOrgRecMinEl = document.getElementById('fb-organic-recent-cron-minute');
        if (fbOrgRecMinEl) fbOrgRecMinEl.value = (currentConfig.fb_organic_cron_recent_minute !== undefined && currentConfig.fb_organic_cron_recent_minute !== null) ? currentConfig.fb_organic_cron_recent_minute : 0;

        const fbMarkEntHourEl = document.getElementById('fb-marketing-entities-cron-hour');
        if (fbMarkEntHourEl) fbMarkEntHourEl.value = (currentConfig.fb_marketing_cron_entities_hour !== undefined && currentConfig.fb_marketing_cron_entities_hour !== null) ? currentConfig.fb_marketing_cron_entities_hour : 2;
        const fbMarkEntMinEl = document.getElementById('fb-marketing-entities-cron-minute');
        if (fbMarkEntMinEl) fbMarkEntMinEl.value = (currentConfig.fb_marketing_cron_entities_minute !== undefined && currentConfig.fb_marketing_cron_entities_minute !== null) ? currentConfig.fb_marketing_cron_entities_minute : 0;

        const fbMarkRecHourEl = document.getElementById('fb-marketing-recent-cron-hour');
        if (fbMarkRecHourEl) fbMarkRecHourEl.value = (currentConfig.fb_marketing_cron_recent_hour !== undefined && currentConfig.fb_marketing_cron_recent_hour !== null) ? currentConfig.fb_marketing_cron_recent_hour : 5;
        const fbMarkRecMinEl = document.getElementById('fb-marketing-recent-cron-minute');
        if (fbMarkRecMinEl) fbMarkRecMinEl.value = (currentConfig.fb_marketing_cron_recent_minute !== undefined && currentConfig.fb_marketing_cron_recent_minute !== null) ? currentConfig.fb_marketing_cron_recent_minute : 0;

        updateCronStatusIndicators();
        
        handleFbLevelChange();
        handleFbOrganicLevelChange();
        handleFbStrategyChange();
        renderCustomMetricsGrid();

        // Dynamic Channel Settings
        if (currentConfig.available_channels) {
            currentConfig.available_channels.forEach(chan => {
                const statusEl = document.getElementById(chan + '-channel-enabled');
                if (statusEl) statusEl.checked = !!currentConfig[chan + '_enabled'];
                
                const rangeEl = document.getElementById(chan + '-history-range');
                if (rangeEl) rangeEl.value = currentConfig[chan + '_history_range'] || '1 year';
            });
        }
        
    } catch (e) {
        console.error("Error in populateGlobalFields:", e);
    }
}

function updateCronStatusIndicators() {
    if (!currentConfig || !currentConfig.effective_schedules) return;
    
    const schedules = currentConfig.effective_schedules;
    
    const check = (inputId, minId, statusId, warningId, instancePrefix) => {
        const inputH = document.getElementById(inputId);
        const inputM = document.getElementById(minId);
        const status = document.getElementById(statusId);
        const warning = document.getElementById(warningId);
        
        if (!inputH || !status) return;
        
        const hour = parseInt(inputH.value);
        const minute = inputM ? parseInt(inputM.value) : 0;
        
        // Find a matching schedule in effective_schedules
        let effective = null;
        Object.entries(schedules).forEach(([key, val]) => {
            if (key.includes(instancePrefix)) {
                effective = val;
            }
        });

        const isSynced = effective && parseInt(effective.hour) === hour && parseInt(effective.minute) === minute;
        
        status.className = 'cron-dot ' + (isSynced ? 'synced' : 'pending');
        status.title = isSynced ? 'Cron Synchronized (' + effective.time + ' *)' : 'Real Cron: ' + (effective ? effective.time : 'None') + ' | Expected: ' + hour + ':' + String(minute).padStart(2,'0');
        
        if (warning) {
            warning.style.display = isSynced ? 'none' : 'flex';
        }
        
        // GSC specific (one indicator)
        if (statusId === 'gsc-cron-sync-status') {
            status.className = 'cron-status-indicator ' + (isSynced ? 'synced' : 'pending');
            status.innerHTML = isSynced ? '<i data-lucide="check" size="10"></i> Synced' : '<i data-lucide="clock" size="10"></i> Update Required';
            lucide.createIcons();
        }
    };

    check('gsc-cron-hour', 'gsc-cron-minute', 'gsc-cron-sync-status', null, 'google-search-console');
    check('fb-organic-entities-cron-hour', 'fb-organic-entities-cron-minute', 'fb-organic-entities-cron-status', 'fb-organic-cron-sync-warning', 'facebook-organic-entities');
    check('fb-organic-recent-cron-hour', 'fb-organic-recent-cron-minute', 'fb-organic-recent-cron-status', 'fb-organic-cron-sync-warning', 'facebook-organic-recent');
    check('fb-marketing-entities-cron-hour', 'fb-marketing-entities-cron-minute', 'fb-marketing-entities-cron-status', 'fb-marketing-cron-sync-warning', 'facebook-marketing-entities');
    check('fb-marketing-recent-cron-hour', 'fb-marketing-recent-cron-minute', 'fb-marketing-recent-cron-status', 'fb-marketing-cron-sync-warning', 'facebook-marketing-recent');
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
    
    // Sync Entity Indicators (infrastructure - Cumulative hierarchy as needed for tree)
    const entActiveIdx = visLevels.indexOf(entEl.value) + 1;
    document.querySelectorAll('[id^="ent-ind-"]').forEach((ind, i) => {
        ind.className = 'level-indicator-dot ' + (i < entActiveIdx ? 'active' : 'inactive');
    });

    // Metrics Indicators (reporting - Atomic Radio model)
    const metActiveIdx = visLevels.indexOf(metEl.value) + 1;
    document.querySelectorAll('[id^="met-ind-"]').forEach((ind, i) => {
        ind.className = 'level-indicator-dot ' + (i === metActiveIdx - 1 ? 'active' : 'inactive');
    });
}

function setFbLevel(lvl) {
    const el = document.getElementById('fb-marketing-level');
    if (el) {
        el.value = lvl;
        handleFbLevelChange();
    }
}

function handleFbOrganicLevelChange() {
    const fbEl = document.getElementById('fb-organic-level');
    const igEl = document.getElementById('fb-ig-level');
    
    // FB Dots
    const fbLevels = ['page', 'page_metrics', 'posts', 'post_metrics'];
    const fbActiveIdx = fbLevels.indexOf(fbEl?.value) + 1; // 1 to 4
    for (let i = 1; i <= 4; i++) {
        const ind = document.getElementById('org-fb-ind-' + i);
        if (ind) ind.className = 'level-indicator-dot ' + (i <= fbActiveIdx ? 'active' : 'inactive');
    }

    // IG Dots
    const igLevels = ['accounts', 'metrics', 'media', 'media_metrics'];
    const igActiveIdx = igLevels.indexOf(igEl?.value) + 1; // 1 to 4
    for (let i = 1; i <= 4; i++) {
        const ind = document.getElementById('org-ig-ind-' + i);
        if (ind) ind.className = 'level-indicator-dot ' + (i <= igActiveIdx ? 'active' : 'inactive');
    }

    // Enforce global limits on local page toggles
    enforceGlobalOrganicLimits(fbEl?.value, igEl?.value);
}

function enforceGlobalOrganicLimits(globalFbLvl, globalIgLvl) {
    const fbLevels = ['page', 'page_metrics', 'posts', 'post_metrics'];
    const igLevels = ['accounts', 'metrics', 'media', 'media_metrics'];
    
    const maxFbIdx = fbLevels.indexOf(globalFbLvl);
    const maxIgIdx = igLevels.indexOf(globalIgLvl);

    document.querySelectorAll('.fb-page-main-toggle').forEach(mainToggle => {
        const pageId = mainToggle.dataset.id;
        const opts = document.querySelectorAll(`.fb-page-opt[data-page="${pageId}"]`);
        
        opts.forEach(opt => {
            const key = opt.dataset.opt;
            let allowed = true;
            
            // FB Logic
            if (key === 'page_metrics' && maxFbIdx < 1) allowed = false;
            if (key === 'posts' && maxFbIdx < 2) allowed = false;
            if (key === 'post_metrics' && maxFbIdx < 3) allowed = false;
            
            // IG Logic
            if (key === 'ig_accounts' && maxIgIdx < 0) allowed = false;
            if (key === 'ig_account_metrics' && maxIgIdx < 1) allowed = false;
            if (key === 'ig_account_media' && maxIgIdx < 2) allowed = false;
            if (key === 'ig_account_media_metrics' && maxIgIdx < 3) allowed = false;

            opt.disabled = !allowed;
            if (!allowed) opt.checked = false;
            
            // Special sub-item opacity
            const subItem = opt.closest('.hierarchy-sub-item');
            if (subItem) subItem.style.opacity = allowed ? '1' : '0.2';
        });
    });
}

function setFbOrganicLevel(lvl) {
    const el = document.getElementById('fb-organic-level');
    if (el) { el.value = lvl; handleFbOrganicLevelChange(); }
}

function setIgLevel(lvl) {
    const el = document.getElementById('fb-ig-level');
    if (el) { el.value = lvl; handleFbOrganicLevelChange(); }
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
        const cfg = currentConfig.fb_metrics_config?.[m] || { enabled: false, format: 'number', sparkline: false, sparkline_direction: 'standard' };
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
                            <input type="checkbox" class="metric-sparkline" ${cfg.sparkline ? 'checked' : ''} onchange="this.closest('.metric-config-card').querySelector('.sparkline-extra-settings').style.display = this.checked ? 'block' : 'none'">
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

            <div class="sparkline-extra-settings" style="display:${cfg.sparkline ? 'block' : 'none'}; margin-bottom:15px; background:rgba(0,0,0,0.15); padding:10px; border-radius:8px; border:1px solid rgba(255,255,255,0.03);">
                <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
                    <div>
                        <div class="rule-group-label" style="font-size:0.55rem;">Trend Logic</div>
                        <select class="metric-sparkline-direction" style="width:100%; font-size:0.7rem; padding:2px 4px;">
                            <option value="standard" ${cfg.sparkline_direction === 'standard' ? 'selected' : ''}>Standard (+ is Green)</option>
                            <option value="inverted" ${cfg.sparkline_direction === 'inverted' ? 'selected' : ''}>Inverted (- is Green)</option>
                        </select>
                    </div>
                    <div>
                        <div class="rule-group-label" style="font-size:0.55rem;">Fixed Color (Optional)</div>
                        <input type="text" class="metric-sparkline-color" placeholder="Hex (e.g. #fff)" value="${cfg.sparkline_color || ''}" style="width:100%; font-size:0.7rem; padding:3px 6px;">
                    </div>
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
                Conditional Rules (Badges)
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
    
    const colors = {
        excellent: '#3fb950',
        good: '#58a6ff',
        average: '#d29922',
        bad: '#f85149'
    };
    
    const selectHtml = `
        <div style="display:flex; align-items:center; gap:5px;">
            <div class="rule-color-indicator" style="width:8px; height:8px; border-radius:50%; background:${colors[uiLevel] || '#333'}; shrink:0;"></div>
            <select class="rule-class" style="padding:2px 4px; font-size:0.7rem; width:100%;" onchange="this.previousElementSibling.style.background = {excellent:'#3fb950', good:'#58a6ff', average:'#d29922', bad:'#f85149'}[this.value]">
                <option value="excellent" ${uiLevel === 'excellent' ? 'selected' : ''}>Excellent</option>
                <option value="good" ${uiLevel === 'good' ? 'selected' : ''}>Good</option>
                <option value="average" ${uiLevel === 'average' ? 'selected' : ''}>Average</option>
                <option value="bad" ${uiLevel === 'bad' ? 'selected' : ''}>Bad</option>
            </select>
        </div>
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
            <div class="rule-group-label" style="font-size:0.5rem;">Style Preview</div>
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
            const configGsc = currentConfig?.gsc || {};
            const isSynced = configGsc[p.url] !== undefined && !p.lost_access;
            const displayUrl = p.url.replace('sc-domain:', '');
            const div = document.createElement('div');
            
            let itemClass = 'asset-item';
            if (isSynced) itemClass += ' synced';
            if (p.is_new) itemClass += ' is-new';
            if (p.lost_access) itemClass += ' lost-access';

            div.className = itemClass;
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
            const getCfg = (key, def = true) => {
                const savedPages = currentConfig.fb_pages_full_config || [];
                const pId = String(p.id).trim();
                const saved = savedPages.find(pg => String(pg.id).trim() === pId);
                
                if (saved && saved[key] !== undefined) {
                    return !!saved[key];
                }
                return def;
            };

            const isSynced = getCfg('enabled') && !p.lost_access;
            const isNew = p.is_new === true;
            const lostAccess = p.lost_access === true;
            
            let cardClasses = 'glass-card page-config-card';
            if (isSynced) cardClasses += ' synced';
            if (isNew) cardClasses += ' is-new';
            if (lostAccess) cardClasses += ' lost-access';

            const div = document.createElement('div');
            div.innerHTML = `
            <div class="${cardClasses}" data-id="${p.id}" data-ig="${p.ig_account || ''}" style="margin-bottom:20px; padding:15px; border:1px solid var(--border); border-radius:12px; position:relative;">
                ${isNew ? '<div class="asset-badge-new">NEW</div>' : ''}
                ${lostAccess ? '<div class="asset-badge-lost">LOST ACCESS</div>' : ''}
                
                <!-- Page Header -->
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <div style="display:flex; align-items:center; gap:10px;">
                        <div style="background:rgba(255,255,255,0.05); width:32px; height:32px; border-radius:8px; display:flex; align-items:center; justify-content:center; color:var(--primary);">
                            <i data-lucide="layout" size="18"></i>
                        </div>
                        <div>
                            <div style="font-weight:700; color:white; font-size:0.9rem;">${p.title || 'Untitled Page'}</div>
                            <div style="font-size:0.65rem; color:var(--text-dim);">ID: ${p.id}</div>
                        </div>
                    </div>
                    <label class="switch">
                        <input type="checkbox" class="fb-page-main-toggle" data-id="${p.id}" ${getCfg('enabled') ? 'checked' : ''} onchange="toggleOrganicHierarchy('${p.id}', this.checked)">
                        <span class="slider"></span>
                    </label>
                </div>

                <!-- Page Options Hierarchy -->
                <div id="hierarchy-${p.id}" style="display:${getCfg('enabled') ? 'grid' : 'none'}; grid-template-columns: 1fr 1fr; gap:20px; padding-top:10px; border-top:1px solid rgba(255,255,255,0.05);">
                    
                    <!-- FB Section -->
                    <div class="hierarchy-col">
                        <div style="font-size:0.65rem; color:var(--text-dim); text-transform:uppercase; margin-bottom:12px; font-weight:700; opacity:0.6;">Facebook Extraction</div>
                        
                        <div class="hierarchy-item-premium">
                            <label class="switch-inline">
                                <input type="checkbox" class="fb-page-opt" data-page="${p.id}" data-opt="page_metrics" ${getCfg('page_metrics') ? 'checked' : ''}>
                                <span class="slider-sm"></span>
                                <span class="lbl">Page Metrics</span>
                            </label>
                        </div>
                        
                        <div class="hierarchy-item-premium">
                            <label class="switch-inline">
                                <input type="checkbox" class="fb-page-opt" data-page="${p.id}" data-opt="posts" ${getCfg('posts') ? 'checked' : ''}>
                                <span class="slider-sm"></span>
                                <span class="lbl">Posts Content</span>
                            </label>
                        </div>
                        
                        <div class="hierarchy-item-premium" style="margin-left: 20px; border-left: 1px solid rgba(255,255,255,0.1); padding-left:12px;">
                            <label class="switch-inline">
                                <input type="checkbox" class="fb-page-opt" data-page="${p.id}" data-opt="post_metrics" ${getCfg('post_metrics') ? 'checked' : ''}>
                                <span class="slider-sm"></span>
                                <span class="lbl">Post Insights</span>
                            </label>
                        </div>
                    </div>

                    <!-- IG Section -->
                    ${p.ig_account ? `
                    <div class="hierarchy-col" style="border-left: 1px solid rgba(255,255,255,0.05); padding-left: 20px;">
                        <div style="display:flex; align-items:center; gap:6px; margin-bottom:12px;">
                            <div style="background:rgba(225, 48, 108, 0.1); color:#E1306C; padding:3px 8px; border-radius:6px; font-size:0.65rem; font-weight:700; text-transform:uppercase; display:flex; align-items:center; gap:5px;">
                                <i data-lucide="instagram" size="10"></i> ${p.ig_account_name || p.ig_account}
                            </div>
                        </div>
                        
                        <div class="hierarchy-item-premium">
                            <label class="switch-inline">
                                <input type="checkbox" class="fb-page-opt" data-page="${p.id}" data-opt="ig_accounts" ${getCfg('ig_accounts', false) ? 'checked' : ''} onchange="toggleSubOpt('${p.id}', 'ig_accounts', this.checked)">
                                <span class="slider-sm"></span>
                                <span class="lbl">Sync Instagram</span>
                            </label>
                        </div>

                        <div id="sub-${p.id}-ig_accounts" style="opacity:${getCfg('ig_accounts', false) ? 1 : 0.3}; margin-left:20px; border-left: 1px solid rgba(255,255,255,0.1); padding-left:12px;">
                            <div class="hierarchy-item-premium">
                                <label class="switch-inline">
                                    <input type="checkbox" class="fb-page-opt" data-page="${p.id}" data-opt="ig_account_metrics" ${getCfg('ig_account_metrics') ? 'checked' : ''} ${!getCfg('ig_accounts') ? 'disabled' : ''}>
                                    <span class="slider-sm"></span>
                                    <span class="lbl">Account Metrics</span>
                                </label>
                            </div>
                            <div class="hierarchy-item-premium">
                                <label class="switch-inline">
                                    <input type="checkbox" class="fb-page-opt" data-page="${p.id}" data-opt="ig_account_media" ${getCfg('ig_account_media') ? 'checked' : ''} ${!getCfg('ig_accounts') ? 'disabled' : ''} onchange="toggleSubOpt('${p.id}', 'ig_account_media', this.checked)">
                                    <span class="slider-sm"></span>
                                    <span class="lbl">Media Content</span>
                                </label>
                            </div>
                            <div id="sub-${p.id}-ig_account_media" class="hierarchy-item-premium" style="opacity:${getCfg('ig_account_media') ? 1 : 0.3}; margin-left:20px; border-left: 1px solid rgba(255,255,255,0.1); padding-left:12px;">
                                <label class="switch-inline">
                                    <input type="checkbox" class="fb-page-opt" data-page="${p.id}" data-opt="ig_account_media_metrics" ${getCfg('ig_account_media_metrics') ? 'checked' : ''} ${!getCfg('ig_account_media') ? 'disabled' : ''}>
                                    <span class="slider-sm"></span>
                                    <span class="lbl">Media Insights</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    ` : `
                    <div class="hierarchy-col" style="display:flex; align-items:center; justify-content:center; opacity:0.3; border-left: 1px solid rgba(255,255,255,0.05);">
                        <div style="text-align:center;">
                            <i data-lucide="instagram-off" size="24"></i>
                            <div style="font-size:0.6rem; margin-top:5px;">No IG Link</div>
                        </div>
                    </div>
                    `}
                </div>
            </div>
            `;
            fbOrganicList.appendChild(div);
        });
        lucide.createIcons();
    }

    if (fbMarketingList) {
        fbMarketingList.innerHTML = '';
        const accounts = assets.facebook_ad_accounts || [];
        // Sort alphabetically by name
        accounts.sort((a, b) => (a.name || '').localeCompare(b.name || ''));
        
        if (accounts.length === 0) fbMarketingList.innerHTML = '<div class="empty-state">No Ad accounts found.</div>';
        accounts.forEach(a => {
            const syncedIds = currentConfig?.fb_ad_account_ids || [];
            const isSynced = syncedIds.includes(String(a.id)) && !a.lost_access;
            const div = document.createElement('div');
            
            let itemClass = 'asset-item';
            if (isSynced) itemClass += ' synced';
            if (a.is_new) itemClass += ' is-new';
            if (a.lost_access) itemClass += ' lost-access';

            div.className = itemClass;
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

        // 1. Collect FB Organic Pages (All of them, enabled or not, for granularity preservation)
        document.querySelectorAll('.page-config-card').forEach(card => {
            const mainToggle = card.querySelector('.fb-page-main-toggle');
            if (!mainToggle) return;
            
            const pageId = String(mainToggle.dataset.id);
            const igId = card.dataset.ig || null;
            
            const pageData = {
                id: pageId,
                enabled: mainToggle.checked,
                ig_account: igId
            };
            
            card.querySelectorAll('.fb-page-opt').forEach(opt => {
                pageData[opt.dataset.opt] = opt.checked;
            });
            
            // Meta-info
            const titleEl = card.querySelector('[style*="font-weight:700"]');
            if (titleEl) pageData.title = titleEl.textContent.trim();
            
            const igText = card.querySelector('[style*="color:#E1306C"]')?.textContent.trim();
            if (igText) pageData.ig_account_name = igText;

            payload.assets.pages.push(pageData);
        });

        document.querySelectorAll('.fb-marketing-asset-sync:checked').forEach(el => {
            const item = el.closest('.asset-item');
            const nameEl = item ? item.querySelector('[style*="font-weight:600"]') : null;
            payload.assets.ad_accounts.push({ 
                id: el.value,
                name: nameEl ? nameEl.textContent.trim() : null
            });
        });

        if (typeArg === 'google_search_console') {
            payload.enabled = document.getElementById('gsc-channel-enabled')?.checked;
            payload.cache_history_range = document.getElementById('gsc-history-range')?.value;
            payload.feature_toggles.cron_recent_hour = document.getElementById('gsc-cron-hour')?.value;
            payload.feature_toggles.cron_recent_minute = document.getElementById('gsc-cron-minute')?.value;
        } else if (typeArg === 'facebook_organic') {
            payload.enabled = document.getElementById('fb-organic-enabled').checked;
            payload.organic_history_range = document.getElementById('fb-organic-history-range').value;
            payload.feature_toggles.cron_entities_hour = document.getElementById('fb-organic-entities-cron-hour')?.value;
            payload.feature_toggles.cron_entities_minute = document.getElementById('fb-organic-entities-cron-minute')?.value;
            payload.feature_toggles.cron_recent_hour = document.getElementById('fb-organic-recent-cron-hour')?.value;
            payload.feature_toggles.cron_recent_minute = document.getElementById('fb-organic-recent-cron-minute')?.value;
        } else if (typeArg === 'facebook_marketing') {
            payload.enabled = document.getElementById('fb-marketing-enabled').checked;
            payload.marketing_history_range = document.getElementById('fb-marketing-history-range').value;
            payload.feature_toggles.cron_entities_hour = document.getElementById('fb-marketing-entities-cron-hour')?.value;
            payload.feature_toggles.cron_entities_minute = document.getElementById('fb-marketing-entities-cron-minute')?.value;
            payload.feature_toggles.cron_recent_hour = document.getElementById('fb-marketing-recent-cron-hour')?.value;
            payload.feature_toggles.cron_recent_minute = document.getElementById('fb-marketing-recent-cron-minute')?.value;
            
            payload.entity_filters = {
                CAMPAIGN: document.getElementById('fb-marketing-campaign-filter')?.value || '',
                ADSET: document.getElementById('fb-marketing-adset-filter')?.value || '',
                AD: document.getElementById('fb-marketing-ad-filter')?.value || '',
                CREATIVE: document.getElementById('fb-marketing-creative-filter')?.value || ''
            };

            const entLevel = document.getElementById('fb-marketing-level')?.value || 'ad_account';
            const metLevel = document.getElementById('fb-marketing-metrics-level')?.value || 'ad_account';

            // Feature Toggles (Infrastructure - Cumulative hierarchy tree)
            payload.feature_toggles.campaigns = true;
            payload.feature_toggles.adsets = (entLevel === 'adset' || entLevel === 'ad' || entLevel === 'creative');
            payload.feature_toggles.ads = (entLevel === 'ad' || entLevel === 'creative');
            payload.feature_toggles.creatives = (entLevel === 'creative');

            // Feature Toggles (Metrics - Exclusive Radio Logic)
            payload.feature_toggles.ad_account_metrics = (metLevel === 'ad_account');
            payload.feature_toggles.campaign_metrics = (metLevel === 'campaign');
            payload.feature_toggles.adset_metrics = (metLevel === 'adset');
            payload.feature_toggles.ad_metrics = (metLevel === 'ad');
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
                    sparkline_direction: card.querySelector('.metric-sparkline-direction').value,
                    sparkline_color: card.querySelector('.metric-sparkline-color').value || null,
                    format,
                    precision,
                    conditional: {
                        enabled: rules.length > 0,
                        config: rules
                    }
                };
            });
        } else if (typeArg !== 'global') {
            // Generic Channel Update
            payload.enabled = document.getElementById(typeArg + '-channel-enabled')?.checked;
            payload.cache_history_range = document.getElementById(typeArg + '-history-range')?.value;
        }

        // Feature Toggles (Organic Tiers - Derived from Selectors)
        if (typeArg === 'facebook_organic' || typeArg === 'global') {
            const fbLvl = document.getElementById('fb-organic-level')?.value || 'page';
            const igLvl = document.getElementById('fb-ig-level')?.value || 'accounts';

            payload.feature_toggles.page_metrics = (fbLvl === 'page_metrics' || fbLvl === 'posts' || fbLvl === 'post_metrics');
            payload.feature_toggles.posts = (fbLvl === 'posts' || fbLvl === 'post_metrics');
            payload.feature_toggles.post_metrics = (fbLvl === 'post_metrics');

            payload.feature_toggles.ig_accounts = (igLvl !== 'none');
            payload.feature_toggles.ig_account_metrics = (igLvl === 'metrics' || igLvl === 'media' || igLvl === 'media_metrics');
            payload.feature_toggles.ig_account_media = (igLvl === 'media' || igLvl === 'media_metrics');
            payload.feature_toggles.ig_account_media_metrics = (igLvl === 'media_metrics');
        }

        if (typeArg === 'global') {
            payload.jobs_timeout_hours = document.getElementById('jobs-timeout-hours').value;
            payload.cache_raw_metrics = document.getElementById('cache-raw-metrics').checked;
            payload.cron_entities_hour = document.getElementById('cron-entities-hour')?.value;
            payload.cron_entities_minute = document.getElementById('cron-entities-minute')?.value;
            payload.cron_recent_hour = document.getElementById('cron-recent-hour')?.value;
            payload.cron_recent_minute = document.getElementById('cron-recent-minute')?.value;
        }

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
            const gscRes = res.results?.google_search_console || res.results?.gsc;
            if (gscRes && gscRes.status === 'valid') {
                gscStatusBadge.innerHTML = '<span style="color:#238636; vertical-align:middle;"><i data-lucide="check-circle" size="12" style="margin-bottom:2px"></i> GSC</span>';
                gscStatusBadge.style.display = 'inline-block';
            } else {
                gscStatusBadge.innerHTML = '<span style="color:#f85149; vertical-align:middle;"><i data-lucide="x-circle" size="12" style="margin-bottom:2px"></i> GSC</span>';
                gscStatusBadge.style.display = 'inline-block';
            }
        }

        if (fbStatusBadge) {
            const fbMarkRes = res.results?.facebook_marketing || res.results?.facebook;
            const fbOrgRes = res.results?.facebook_organic;
            const isFbValid = (fbMarkRes && fbMarkRes.status === 'valid') && (fbOrgRes && fbOrgRes.status === 'valid');
            
            if (isFbValid) {
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

function toggleOrganicHierarchy(id, enabled) {
    const content = document.getElementById('content-' + id);
    if (content) content.style.display = enabled ? 'block' : 'none';
    
    const card = content?.closest('.asset-group-card');
    if (card) card.classList.toggle('synced', enabled);
}

function toggleSubOpt(pageId, opt, enabled) {
    const subContainer = document.getElementById(`sub-${pageId}-${opt}`);
    if (subContainer) {
        subContainer.style.opacity = enabled ? '1' : '0.3';
        subContainer.querySelectorAll('input').forEach(input => {
            input.disabled = !enabled;
            if (!enabled) input.checked = false;
        });
    }
}

function setupEventListeners() {
}

function handleFbStrategyChange() {
    const customRadio = document.getElementById('fb-strategy-custom');
    const isCustom = customRadio ? customRadio.checked : false;
    const area = document.getElementById('fb-custom-metrics-area');
    if (area) area.style.display = isCustom ? 'block' : 'none';
}

async function forceRefresh(type) {
    const loader = document.getElementById('loading');
    if (loader) {
        loader.style.display = 'flex';
        const textEl = loader.querySelector('div:nth-child(2)');
        if (textEl) textEl.textContent = 'Force Syncing from API...';
    }

    try {
        const response = await fetch(`/api/config-manager/assets?refresh=1&type=${type}`, { headers: getAdminHeaders() });
        if (response.status === 401) {
            localStorage.removeItem('apis_hub_admin_auth');
            alert('Session Expired. Please reload.');
            return;
        }
        
        const data = await response.json();
        if (!data || !data.config) throw new Error("Invalid response.");
        
        currentConfig = data.config;
        populateGlobalFields();
        renderAssets(data.assets);
        showToast('Assets synchronized successfully!', false);
    } catch (error) {
        showToast('Error syncing assets: ' + error.message, true);
    } finally {
        if (loader) {
            loader.style.display = 'none';
            const textEl = loader.querySelector('div:nth-child(2)');
            if (textEl) textEl.textContent = 'Synchronizing Config...';
        }
    }
}

/**
 * Force manual execution of a global sync for a channel.
 * @param {string} channel 
 * @param {string} entity 
 */
async function triggerFullSync(channel, entity) {
    if (!confirm(`Are you sure you want to FORCE GLOBAL SYNC for ${channel}?\nThis will create a new background job immediately.`)) {
        return;
    }

    const loader = document.getElementById('loading');
    if (loader) {
        loader.style.display = 'flex';
        const textEl = loader.querySelector('div:nth-child(2)');
        if (textEl) textEl.textContent = 'Triggering Global Pipeline...';
    }

    try {
        const response = await fetch(`/cache/${channel}/${entity}`, {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify({})
        });

        const data = await response.json();

        if (response.ok) {
            showToast('Success: Global sync scheduled. Check Monitoring Dashboard.', false);
        } else {
            throw new Error(data.error || 'Server error occurred');
        }
    } catch (e) {
        console.error("Force Sync Error:", e);
        showToast(`Failed to trigger sync: ${e.message}`, true);
    } finally {
        if (loader) loader.style.display = 'none';
        
        // Reset loader text if it exists
        const loaderText = document.querySelector('#loading div:nth-child(2)');
        if (loaderText) loaderText.textContent = 'Synchronizing Config...';
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

async function toggleInfrastructureRule(channel, enabled) {
    try {
        const response = await fetch('/api/config-manager/infrastructure/rule', {
            method: 'POST',
            headers: getAdminHeaders(),
            body: JSON.stringify({ channel, enabled })
        });
        const res = await response.json();
        if (res.success) {
            showToast(`Infrastructure worker for ${channel} updated`, false);
        } else throw new Error(res.error);
    } catch (e) {
        showToast("Rule Update Error: " + e.message, true);
    }
}

function renderInfrastructureRules(rules, channels) {
    const container = document.getElementById('infrastructure-workers-list');
    if (!container) return;
    container.innerHTML = '';

    channels.forEach(chan => {
        const rule = rules[chan] || { enabled: false };
        const div = document.createElement('div');
        div.className = 'asset-item' + (rule.enabled ? ' synced' : '');
        div.style.padding = '12px 15px';
        
        let label = chan;
        const map = {
           'google_search_console': 'GSC SearchConsole',
           'facebook_marketing': 'Meta Marketing',
           'facebook_organic': 'Meta Organic',
           'shopify': 'Shopify Store',
           'klaviyo': 'Klaviyo Marketing'
        };
        if (map[chan]) label = map[chan];

        div.innerHTML = `
            <div style="display:flex; justify-content:space-between; align-items:center; width:100%;">
                <div style="display:flex; align-items:center; gap:10px; min-width:0;">
                    <i data-lucide="server" size="14" style="color:${rule.enabled ? 'var(--primary)' : 'var(--text-dim)'}; flex-shrink:0;"></i>
                    <span style="font-size:0.75rem; font-weight:600; color:#fff; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${label}</span>
                </div>
                <label class="switch-mini">
                    <input type="checkbox" ${rule.enabled ? 'checked' : ''} onchange="toggleInfrastructureRule('${chan}', this.checked); this.closest('.asset-item').classList.toggle('synced', this.checked)">
                    <span class="slider-mini"></span>
                </label>
            </div>
        `;
        container.appendChild(div);
    });
    lucide.createIcons();
}

// Global Hooks (Expose to window)
window.setFbLevel = setFbLevel;
window.setMetricLevel = setMetricLevel;
window.toggleAllAds = toggleAllAds;
window.addMetricRule = addMetricRule;
window.updateConfig = updateConfig;
window.validateTokens = validateTokens;
window.handleFbLevelChange = handleFbLevelChange;
window.handleFbOrganicLevelChange = handleFbOrganicLevelChange;
window.handleFbStrategyChange = handleFbStrategyChange;
window.forceRefresh = forceRefresh;
window.toggleOrganicHierarchy = toggleOrganicHierarchy;
window.toggleSubOpt = toggleSubOpt;
window.setFbOrganicLevel = setFbOrganicLevel;
window.setIgLevel = setIgLevel;
window.triggerFullSync = triggerFullSync;
window.toggleInfrastructureRule = toggleInfrastructureRule;
window.showToast = showToast;

console.log("Config Manager script loaded.");

// Initialize
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initConfig);
} else {
    initConfig();
}

function toggleRawMetricsWarning(show) {
    const el = document.getElementById('raw-metrics-danger-zone');
    if (el) {
        el.style.display = show ? 'block' : 'none';
        if (show) {
            el.animate([
                { borderColor: 'var(--danger)', background: 'rgba(248, 81, 73, 0.1)' },
                { borderColor: 'transparent', background: 'transparent' },
                { borderColor: 'var(--danger)', background: 'rgba(248, 81, 73, 0.05)' }
            ], { duration: 500, iterations: 2 });
        }
    }
}
