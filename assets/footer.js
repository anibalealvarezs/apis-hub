/**
 * Footer Component for APIs Hub
 * centralized footprint with high-fidelity aesthetics
 */

(function() {
    const isDemo = window.location.hostname.includes('demo') || 
                   window.location.hostname.includes('hetzner') || 
                   document.title.toLowerCase().includes('demo');

    const footerHTML = `
    <footer class="footer-premium">
        <div class="footer-container">
            <div class="footer-content">
                <div class="footer-brand">
                    <div class="footer-logo-container">
                        <img src="/assets/images/apishub-trans-light-600.png" alt="APIs Hub" class="footer-logo">
                    </div>
                    <p class="footer-tagline">Advanced Metric Aggregation & API Orchestration Platform</p>
                </div>
                
                <div class="footer-group">
                    <h4 class="footer-label">Product</h4>
                    <ul class="footer-links">
                        <li><a href="/monitoring">Dashboard (Jobs)</a></li>
                        <li><a href="/fb-reports">Marketing Ads</a></li>
                        ${!isDemo ? `<li><a href="/docs">API Swagger Docs</a></li>` : ''}
                    </ul>
                </div>
                
                <div class="footer-group">
                    <h4 class="footer-label">Resources</h4>
                    <ul class="footer-links">
                        <li><a href="/privacy">Privacy Center</a></li>
                        <li><a href="/tos">Terms of Usage</a></li>
                        <li><a href="/data-deletion">Data Management</a></li>
                    </ul>
                </div>

                <div class="footer-group">
                    <h4 class="footer-label">Connect</h4>
                    <ul class="footer-links">
                        <li><a href="https://anibalalvarez.com" target="_blank">About Aníbal</a></li>
                        <li><a href="/fb-login" class="footer-highlight">Meta Ads Login</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="footer-divider"></div>
            
            <div class="footer-bottom">
                <p class="copyright">
                    &copy; ${new Date().getFullYear()} <span>APIs Hub</span>. Masterfully developed by 
                    <a href="https://anibalalvarez.com" target="_blank">Aníbal Álvarez</a>. 
                    <span class="version-tag">Production v2.5.0</span>
                </p>
            </div>
        </div>
    </footer>
    <style>
        .footer-premium {
            margin-top: 100px;
            padding: 80px 0 40px 0;
            background: #0d1117;
            background: linear-gradient(to bottom, transparent, rgba(13, 17, 23, 0.95));
            border-top: 1px solid rgba(48, 54, 61, 0.8);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #8b949e;
            position: relative;
            overflow: hidden;
        }

        .footer-premium::before {
            content: '';
            position: absolute;
            top: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80%;
            height: 1px;
            background: linear-gradient(to right, transparent, rgba(88, 166, 255, 0.3), transparent);
        }

        .footer-container {
            width: 90%;
            max-width: 1400px;
            margin: 0 auto;
        }

        .footer-content {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 60px;
            margin-bottom: 60px;
        }

        .footer-brand {
            display: flex;
            flex-direction: column;
            gap: 15px;
            align-items: center;
            text-align: center;
        }

        .footer-logo-container {
            margin-bottom: 5px;
            display: flex;
            justify-content: center;
        }

        .footer-logo {
            height: 40px;
            width: auto;
            opacity: 1;
        }

        .footer-tagline {
            font-size: 0.9rem;
            line-height: 1.6;
            color: #8b949e;
            max-width: 320px;
        }

        .footer-label {
            color: white;
            font-size: 0.85rem;
            font-weight: 700;
            margin-bottom: 25px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
        }

        .footer-links {
            list-style: none;
            padding: 0;
            margin: 0;
        }

        .footer-links li {
            margin-bottom: 14px;
        }

        .footer-links a {
            color: #8b949e;
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            position: relative;
        }

        .footer-links a:hover {
            color: #58a6ff;
            padding-left: 5px;
        }

        .footer-highlight {
            color: #1877F2 !important;
            font-weight: 600;
        }

        .footer-divider {
            height: 1px;
            background: rgba(48, 54, 61, 0.4);
            margin-bottom: 30px;
        }

        .footer-bottom {
            display: flex;
            justify-content: center;
            align-items: center;
            text-align: center;
        }

        .copyright {
            font-size: 0.85rem;
            color: #484f58;
        }

        .copyright span { color: #f0f6fc; font-weight: 600; }
        .copyright a { color: #58a6ff; text-decoration: none; font-weight: 500; }
        .copyright a:hover { text-decoration: underline; }

        .version-tag {
            margin-left: 15px;
            padding: 2px 8px;
            background: rgba(88, 166, 255, 0.05);
            border: 1px solid rgba(88, 166, 255, 0.1);
            border-radius: 4px;
            font-size: 0.75rem;
            color: #58a6ff;
        }

        @media (max-width: 1000px) {
            .footer-content {
                grid-template-columns: 1fr 1fr;
                gap: 40px;
            }
        }

        @media (max-width: 600px) {
            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            .footer-brand { align-items: center; }
            .footer-tagline { margin: 0 auto; }
        }
    </style>
    `;

    const injectFooter = () => {
        if (document.getElementById('footer-premium-injected')) return;
        
        const footerDiv = document.createElement('div');
        footerDiv.id = 'footer-premium-injected';
        footerDiv.innerHTML = footerHTML;
        
        const oldFooters = document.querySelectorAll('footer:not(.footer-premium)');
        oldFooters.forEach(f => f.remove());
        
        document.body.appendChild(footerDiv);
    };

    if (document.body) {
        injectFooter();
    } else {
        window.addEventListener('DOMContentLoaded', injectFooter);
    }
})();
