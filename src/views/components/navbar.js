/**
 * Navbar Component for APIs Hub
 * centralized navigation menu with Glassmorphism aesthetic
 */

(function() {
    const navbarHTML = `
    <nav class="navbar-premium">
        <div class="navbar-container">
            <div class="navbar-brand">
                <a href="/">
                    <img src="/assets/logo.png" alt="APIs Hub" class="navbar-logo" onerror="this.src='https://raw.githubusercontent.com/anibalealvarezs/anibalealvarezs/main/images/logo-1024x1024.png'">
                    <span class="navbar-title">APIs Hub</span>
                </a>
            </div>
            
            <div class="navbar-menu-toggle" id="mobile-menu-toggle">
                <span></span>
                <span></span>
                <span></span>
            </div>

            <ul class="navbar-links" id="navbar-links">
                <li class="nav-item">
                    <a href="/monitoring" class="nav-link">
                        <i class="icon-monitor"></i> Dashboard
                    </a>
                </li>
                
                <li class="nav-item has-dropdown">
                    <a href="#" class="nav-link dropdown-toggle">
                        <i class="icon-reports"></i> Reports <span class="caret"></span>
                    </a>
                    <ul class="dropdown-menu">
                        <li><a href="/fb-reports">Facebook Marketing</a></li>
                        <li><a href="/fb-organic-reports">Facebook Organic</a></li>
                        <li class="disabled"><a href="#">GSC (Disabled)</a></li>
                    </ul>
                </li>
                
                <li class="nav-item">
                    <a href="/config-manager" class="nav-link">
                        <i class="icon-settings"></i> Config
                    </a>
                </li>
                
                <li class="nav-item">
                    <a href="/fb-login" class="nav-link nav-btn-login">
                        <i class="icon-facebook"></i> FB Login
                    </a>
                </li>
            </ul>
        </div>
    </nav>
    <style>
        /* Navbar Premium Glassmorphism Styles */
        .navbar-premium {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 70px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(15px);
            -webkit-backdrop-filter: blur(15px);
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            z-index: 1000;
            display: flex;
            align-items: center;
            transition: all 0.3s ease;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
        }

        .navbar-container {
            width: 90%;
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .navbar-brand a {
            display: flex;
            align-items: center;
            text-decoration: none;
            gap: 12px;
        }

        .navbar-logo {
            height: 40px;
            width: 40px;
            border-radius: 8px;
            box-shadow: 0 4px 10px rgba(0,0,0,0.2);
        }

        .navbar-title {
            color: white;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .navbar-links {
            display: flex;
            list-style: none;
            gap: 20px;
            margin: 0;
            padding: 0;
            align-items: center;
        }

        .nav-link {
            text-decoration: none;
            color: rgba(255, 255, 255, 0.8);
            font-weight: 500;
            font-size: 0.95rem;
            padding: 8px 12px;
            border-radius: 8px;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link:hover {
            color: white;
            background: rgba(255, 255, 255, 0.15);
        }

        .nav-btn-login {
            background: linear-gradient(135deg, #1877F2, #0D65D3);
            color: white !important;
            padding: 8px 18px !important;
            box-shadow: 0 4px 15px rgba(24, 119, 242, 0.3);
        }

        .nav-btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(24, 119, 242, 0.4);
            background: linear-gradient(135deg, #2D88FF, #1877F2);
        }

        /* Dropdown Logic */
        .has-dropdown {
            position: relative;
        }

        .dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            background: rgba(30, 30, 30, 0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 10px 0;
            min-width: 200px;
            display: none;
            box-shadow: 0 10px 30px rgba(0,0,0,0.5);
            margin-top: 10px;
        }

        .has-dropdown:hover .dropdown-menu {
            display: block;
            animation: fadeInDown 0.2s ease;
        }

        .dropdown-menu li a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            padding: 10px 20px;
            display: block;
            transition: all 0.2s ease;
        }

        .dropdown-menu li a:hover {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            padding-left: 25px;
        }

        .dropdown-menu .disabled a {
            opacity: 0.4;
            cursor: not-allowed;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Responsive */
        .navbar-menu-toggle {
            display: none;
            cursor: pointer;
            flex-direction: column;
            gap: 5px;
        }

        .navbar-menu-toggle span {
            width: 25px;
            height: 3px;
            background: white;
            border-radius: 2px;
        }

        @media (max-width: 900px) {
            .navbar-menu-toggle { display: flex; }
            .navbar-links {
                display: none;
                position: absolute;
                top: 70px;
                left: 0;
                width: 100%;
                background: rgba(0, 0, 0, 0.9);
                flex-direction: column;
                padding: 20px 0;
                text-align: center;
            }
            .navbar-links.active { display: flex; }
        }
    </style>
    `;

    // Function to inject navbar
    const injectNavbar = () => {
        if (document.getElementById('navbar-premium-injected')) return;
        
        const container = document.createElement('div');
        container.id = 'navbar-premium-injected';
        container.innerHTML = navbarHTML;
        
        // Insert at the VERY beginning of body
        document.body.insertBefore(container, document.body.firstChild);
        
        // Wrap existing content to handle the 70px offset
        const content = Array.from(document.body.childNodes).filter(node => node !== container);
        const wrapper = document.createElement('div');
        wrapper.className = 'page-content-wrapper';
        wrapper.style.marginTop = '70px';
        content.forEach(node => wrapper.appendChild(node));
        document.body.appendChild(wrapper);

        // Toggle mobile menu
        const toggle = document.getElementById('mobile-menu-toggle');
        const links = document.getElementById('navbar-links');
        if (toggle && links) {
            toggle.addEventListener('click', () => {
                links.classList.toggle('active');
            });
        }
    };

    // Try injecting immediately, but also wait for DOM
    if (document.body) {
        injectNavbar();
    } else {
        window.addEventListener('DOMContentLoaded', injectNavbar);
    }
})();
