/**
 * Navbar Component for APIs Hub
 * centralized navigation menu with Lucide Icons and Glassmorphism
 */

(function() {
    // Cargar Lucide si no existe
    if (!window.lucide) {
        const script = document.createElement('script');
        script.src = 'https://unpkg.com/lucide@latest';
        script.onload = () => lucide.createIcons();
        document.head.appendChild(script);
    }

    const envMeta = document.querySelector('meta[name="app-env"]');
    const isDemo = (envMeta && envMeta.getAttribute('content') === 'demo') || 
                   window.location.hostname.includes('demo') || 
                   window.location.hostname.includes('hetzner') || 
                   document.title.toLowerCase().includes('demo');

    // Si NO es demo, mostramos todo (Testing/Production)
    const showFullSuite = !isDemo;

    const navbarHTML = `
    <nav class="navbar-premium">
        <div class="navbar-container">
            <div class="navbar-brand">
                <a href="/">
                    <img src="/assets/images/apishub-trans-light-600.png" alt="APIs Hub" class="navbar-logo">
                </a>
            </div>
            
            <div class="navbar-menu-toggle" id="mobile-menu-toggle">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <ul class="navbar-links" id="navbar-links">
                <li class="nav-item">
                    <a href="/" class="nav-link">
                        <i data-lucide="home"></i> Home
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="/monitoring" class="nav-link">
                        <i data-lucide="activity"></i> Data Sync
                    </a>
                </li>

                ${(!isDemo && window.location.pathname.indexOf('/config-manager') === -1) ? `
                <li class="nav-item">
                    <a href="/logs" class="nav-link">
                        <i data-lucide="scroll"></i> Logs
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/command-builder" class="nav-link">
                        <i data-lucide="terminal"></i> Builder
                    </a>
                </li>
                ` : ''}

                <li class="nav-item">
                    <a href="/config-manager" class="nav-link">
                        <i data-lucide="settings"></i> Config
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/fb-reports" class="nav-link">
                        <i data-lucide="bar-chart-3"></i> Marketing
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/fb-organic-reports" class="nav-link">
                        <i data-lucide="bar-chart-2"></i> Organic
                    </a>
                </li>

                <li class="nav-item">
                    <a href="/gsc-reports" class="nav-link">
                        <i data-lucide="search"></i> Search
                    </a>
                </li>
                ${(!isDemo && window.location.pathname.indexOf('/config-manager') === -1) ? `
                <li class="nav-item">
                    <a href="/docs" class="nav-link">
                        <i data-lucide="book"></i> API Docs
                    </a>
                </li>
                ` : ''}
                
                ${(window.location.pathname.indexOf('/config-manager') === -1) ? `
                <li class="nav-item">
                    <a href="/fb-login" class="nav-link nav-btn-login">
                        Connect Meta Ads
                    </a>
                </li>
                ` : ''}
            </ul>
        </div>
    </nav>
    <style>
        .navbar-premium {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 70px;
            background: rgba(13, 17, 23, 0.85);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-bottom: 1px solid rgba(48, 54, 61, 0.7);
            z-index: 10000;
            display: flex;
            align-items: center;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .navbar-container {
            width: 95%;
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand a { display: flex; align-items: center; text-decoration: none; }

        .navbar-logo { height: 32px; width: auto; }

        .navbar-links {
            display: flex;
            list-style: none;
            gap: 15px;
            margin: 0;
            padding: 0;
            align-items: center;
        }

        .nav-link {
            text-decoration: none;
            color: #8b949e;
            font-weight: 500;
            font-size: 0.9rem;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link i { width: 18px; height: 18px; }

        .nav-link:hover {
            color: white;
            background: rgba(255, 255, 255, 0.05);
        }

        .nav-link[href="${window.location.pathname}"] {
            color: #58a6ff;
            background: rgba(88, 166, 255, 0.1);
        }

        .nav-item .nav-btn-login {
            background: #1877F2;
            color: white !important;
            padding: 10px 20px !important;
            border-radius: 12px !important;
            font-weight: 700 !important;
            font-size: 0.85rem !important;
            margin-left: 10px;
            box-shadow: 0 4px 14px rgba(24, 119, 242, 0.4);
        }

        .nav-btn-login:hover {
            background: #2D88FF;
            transform: translateY(-2px);
        }

        .navbar-menu-toggle { display: none; cursor: pointer; flex-direction: column; gap: 5px; }
        .navbar-menu-toggle span { width: 25px; height: 3px; background: white; border-radius: 2px; }

        @media (max-width: 1100px) {
            .navbar-menu-toggle { display: flex; }
            .navbar-links {
                display: none;
                position: absolute;
                top: 70px;
                left: 0;
                width: 100%;
                background: rgba(13, 17, 23, 0.98);
                flex-direction: column;
                padding: 20px 0;
                border-bottom: 1px solid rgba(48, 54, 61, 0.7);
            }
            .navbar-links.active { display: flex; }
            .nav-btn-login { margin-left: 0; margin-top: 10px; }
        }
    </style>
    `;

    const injectNavbar = () => {
        if (document.getElementById('navbar-premium-injected')) return;
        
        const container = document.createElement('div');
        container.id = 'navbar-premium-injected';
        container.innerHTML = navbarHTML;
        
        document.body.insertBefore(container, document.body.firstChild);
        
        const contentNodes = Array.from(document.body.childNodes).filter(node => node !== container);
        const wrapper = document.createElement('div');
        wrapper.className = 'page-content-wrapper';
        wrapper.style.marginTop = '70px';
        contentNodes.forEach(node => wrapper.appendChild(node));
        document.body.appendChild(wrapper);

        const toggle = document.getElementById('mobile-menu-toggle');
        const links = document.getElementById('navbar-links');
        if (toggle && links) {
            toggle.addEventListener('click', () => {
                links.classList.toggle('active');
            });
        }
        
        // Init icons
        if (window.lucide) {
            lucide.createIcons();
        }
    };

    if (document.body) {
        injectNavbar();
    } else {
        window.addEventListener('DOMContentLoaded', injectNavbar);
    }
})();
