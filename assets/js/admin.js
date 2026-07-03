
/* =====================================================
   KAUZARIYYA MUSABAQA — ADMIN JS
   GSAP Animations + AJAX SPA Router
   ===================================================== */

(function () {

    'use strict';

    /* =====================================================
       CONSTANTS
       ===================================================== */

    const APP_URL = window.APP_CONFIG?.baseUrl || '';

    const AJAX_HEADER = { 'X-Requested-With': 'XMLHttpRequest' };

    /* =====================================================
       LOADING BAR
       ===================================================== */

    const loader = document.createElement('div');
    loader.className = 'ajax-loader';
    document.body.appendChild(loader);

    function showLoader() {
        loader.classList.remove('done');
        loader.classList.add('active');
    }

    function hideLoader() {
        loader.classList.add('done');
        setTimeout(() => loader.classList.remove('active', 'done'), 400);
    }

    /* =====================================================
       GSAP ANIMATIONS
       ===================================================== */

    function runEntryAnimations() {

        const mc = document.querySelector('.main-content');
        if (!mc) return;

        gsap.fromTo(mc,
            { opacity: 0, y: 18 },
            { opacity: 1, y: 0, duration: 0.55, ease: 'power3.out' }
        );

        const topbar = mc.querySelector('.topbar');
        if (topbar) {
            gsap.fromTo(topbar,
                { y: -16, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.6, delay: 0.08, ease: 'power3.out' }
            );
        }

        const title = mc.querySelector('.page-title');
        if (title) {
            gsap.fromTo(title,
                { y: 22, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.7, delay: 0.12, ease: 'power4.out' }
            );
        }

        const subtitle = mc.querySelector('.page-subtitle');
        if (subtitle) {
            gsap.fromTo(subtitle,
                { y: 14, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.7, delay: 0.18, ease: 'power3.out' }
            );
        }

        mc.querySelectorAll('.dashboard-card').forEach((card, i) => {
            gsap.fromTo(card,
                { y: 40, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.7, stagger: 0.06, delay: 0.15 + i * 0.06, ease: 'power3.out' }
            );
        });

        mc.querySelectorAll('.stat-card').forEach((card, i) => {
            gsap.fromTo(card,
                { y: 30, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.6, delay: 0.12 + i * 0.05, ease: 'power3.out' }
            );
        });

        mc.querySelectorAll('.event-card').forEach((card, i) => {
            gsap.fromTo(card,
                { y: 50, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.7, delay: 0.15 + i * 0.06, ease: 'power3.out' }
            );
        });

        const activePanel = mc.querySelector('.active-team-panel');
        if (activePanel) {
            gsap.fromTo(activePanel,
                { y: 40, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.8, delay: 0.15, ease: 'power4.out' }
            );
        }

        mc.querySelectorAll('.quick-action-btn').forEach((btn, i) => {
            gsap.fromTo(btn,
                { scale: 0.92, opacity: 0 },
                { scale: 1, opacity: 1, duration: 0.6, delay: 0.18 + i * 0.04, ease: 'back.out(1.4)' }
            );
        });

        const rows = mc.querySelectorAll('table tbody tr');
        rows.forEach((row, i) => {
            gsap.fromTo(row,
                { y: 16, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.5, delay: 0.12 + i * 0.03, ease: 'power2.out' }
            );
        });

        mc.querySelectorAll('.panel').forEach((panel, i) => {
            gsap.fromTo(panel,
                { y: 20, opacity: 0 },
                { y: 0, opacity: 1, duration: 0.6, delay: 0.1 + i * 0.06, ease: 'power3.out' }
            );
        });
    }

    /* =====================================================
       SIDEBAR ANIMATIONS (first load only)
       ===================================================== */

    function runSidebarAnimations() {
        gsap.from('.sidebar', {
            x: -40, opacity: 0,
            duration: 1, ease: 'power3.out'
        });

        document.querySelectorAll('.sidebar-link').forEach((link, i) => {
            gsap.from(link, {
                x: -20, opacity: 0,
                duration: 0.7, stagger: 0.04,
                delay: 0.25 + i * 0.04, ease: 'power2.out'
            });
        });
    }

    /* =====================================================
       MODAL SYSTEM
       ===================================================== */

    function initModals() {

        document.querySelectorAll('[data-modal-open]').forEach(button => {
            button.addEventListener('click', () => {
                const target = button.getAttribute('data-modal-open');
                const modal = document.querySelector(target);
                if (!modal) return;
                modal.classList.add('active');
                gsap.fromTo(
                    modal.querySelector('.modal-box'),
                    { y: 50, opacity: 0, scale: 0.95 },
                    { y: 0, opacity: 1, scale: 1, duration: 0.45, ease: 'power3.out' }
                );
            });
        });

        document.querySelectorAll('[data-modal-close]').forEach(button => {
            button.addEventListener('click', () => {
                const modal = button.closest('.modal-overlay');
                if (modal) modal.classList.remove('active');
            });
        });

        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.classList.remove('active');
                }
            });
        });
    }

    /* =====================================================
       INLINE SCRIPT EXECUTOR
       ===================================================== */

    function executeInlineScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');
            if (oldScript.src) {
                newScript.src = oldScript.src;
            } else {
                newScript.textContent = oldScript.textContent;
            }
            for (const attr of oldScript.attributes) {
                if (attr.name !== 'src') {
                    newScript.setAttribute(attr.name, attr.value);
                }
            }
            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }

    /* =====================================================
       SIDEBAR ACTIVE STATE
       ===================================================== */

    function updateSidebarActive(url) {
        const path = new URL(url, location.origin).pathname;
        document.querySelectorAll('.sidebar-link').forEach(link => {
            const linkPath = new URL(link.href, location.origin).pathname;
            if (path === linkPath || path.startsWith(linkPath + '/')) {
                link.classList.add('active');
            } else {
                link.classList.remove('active');
            }
        });
    }

    /* =====================================================
       AJAX NAVIGATION CORE
       ===================================================== */

    function isAdminUrl(url) {
        try {
            const u = new URL(url, location.origin);
            return u.pathname.includes('/admin/') || u.pathname.includes('/admin.');
        } catch { return false; }
    }

    function shouldIntercept(anchor) {
        if (!anchor || !anchor.href) return false;
        if (anchor.target === '_blank') return false;
        if (anchor.hasAttribute('data-ajax-ignore')) return false;
        if (anchor.hasAttribute('download')) return false;

        const url = anchor.href;
        if (url.includes('/tv/')) return false;
        if (url.includes('/auth/')) return false;
        if (url.includes('/utilities/')) return false;
        if (!isAdminUrl(url)) return false;

        return true;
    }

    async function navigateTo(url, pushState = true) {
        showLoader();

        try {
            const resp = await fetch(url, {
                headers: AJAX_HEADER,
                credentials: 'same-origin'
            });

            const contentType = resp.headers.get('Content-Type') || '';

            /* Handle JSON redirect responses */
            if (contentType.includes('application/json')) {
                const data = await resp.json();
                if (data.redirect) {
                    hideLoader();
                    return navigateTo(data.redirect, pushState);
                }
            }

            const html = await resp.text();
            swapContent(html, url, pushState);

        } catch (err) {
            console.error('[AJAX Router] Navigation failed:', err);
            location.href = url; /* Fallback to full reload */
        }
    }

    function swapContent(html, url, pushState) {
        const mainContent = document.querySelector('.main-content');
        if (!mainContent) {
            location.href = url;
            return;
        }

        /* Parse the response HTML */
        const parser = new DOMParser();
        const doc = parser.parseFromString(html, 'text/html');

        /* Try to find .main-content in the response */
        let newContent = doc.querySelector('.main-content');

        if (!newContent) {
            /* If the response IS the main-content (AJAX response without shell) */
            const wrapper = doc.body;
            if (wrapper) {
                newContent = wrapper.querySelector('.main-content');
                if (!newContent) {
                    /* The entire response body might be the content */
                    newContent = wrapper;
                }
            }
        }

        if (!newContent) {
            location.href = url;
            return;
        }

        /* Animate out old content */
        gsap.to(mainContent, {
            opacity: 0, y: -10,
            duration: 0.18,
            ease: 'power2.in',
            onComplete: () => {
                mainContent.innerHTML = newContent.innerHTML;
                mainContent.className = newContent.className;

                /* Execute inline scripts */
                executeInlineScripts(mainContent);

                /* Re-init modals */
                initModals();

                /* Animate in new content */
                mainContent.style.opacity = '0';
                runEntryAnimations();

                /* Update sidebar */
                updateSidebarActive(url);

                /* Update browser URL */
                if (pushState) {
                    history.pushState({ ajaxUrl: url }, '', url);
                }

                /* Update page title */
                const titleEl = mainContent.querySelector('.page-title');
                if (titleEl) {
                    document.title = titleEl.textContent.trim() + ' — Kauzariyya Musabaqa';
                }

                hideLoader();

                /* Scroll to top of main content */
                mainContent.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });
    }

    /* =====================================================
       FORM SUBMISSION INTERCEPTION
       ===================================================== */

    async function handleFormSubmit(form) {
        const action = form.action || location.href;
        if (!isAdminUrl(action)) return false;

        const method = (form.method || 'GET').toUpperCase();
        showLoader();

        try {
            const options = {
                method,
                headers: { ...AJAX_HEADER },
                credentials: 'same-origin'
            };

            if (method === 'POST') {
                options.body = new FormData(form);
            } else {
                const params = new URLSearchParams(new FormData(form));
                const urlObj = new URL(action, location.origin);
                urlObj.search = params.toString();
                return navigateTo(urlObj.href);
            }

            const resp = await fetch(action, options);
            const contentType = resp.headers.get('Content-Type') || '';

            /* Handle JSON redirect responses (from admin_redirect) */
            if (contentType.includes('application/json')) {
                const data = await resp.json();
                if (data.redirect) {
                    hideLoader();
                    return navigateTo(data.redirect);
                }
            }

            /* HTML response */
            const html = await resp.text();
            swapContent(html, resp.url || action, true);

        } catch (err) {
            console.error('[AJAX Router] Form submission failed:', err);
            form.submit(); /* Fallback */
        }

        return true;
    }

    /* =====================================================
       EVENT DELEGATION
       ===================================================== */

    function initRouter() {

        /* --- Link clicks (delegated to document) --- */
        document.addEventListener('click', (e) => {
            const anchor = e.target.closest('a');
            if (!anchor) return;
            if (!shouldIntercept(anchor)) return;

            e.preventDefault();
            navigateTo(anchor.href);
        });

        /* --- Form submissions (delegated to document) --- */
        document.addEventListener('submit', (e) => {
            const form = e.target.closest('form');
            if (!form) return;

            const action = form.action || location.href;
            if (!isAdminUrl(action)) return;
            if (form.hasAttribute('data-ajax-ignore')) return;

            e.preventDefault();
            handleFormSubmit(form);
        });

        /* --- Browser back/forward --- */
        window.addEventListener('popstate', (e) => {
            if (e.state && e.state.ajaxUrl) {
                navigateTo(e.state.ajaxUrl, false);
            } else {
                navigateTo(location.href, false);
            }
        });

        /* Store initial state */
        history.replaceState({ ajaxUrl: location.href }, '', location.href);
    }

    /* =====================================================
       INITIALIZATION
       ===================================================== */

    window.addEventListener('DOMContentLoaded', () => {
        runSidebarAnimations();
        runEntryAnimations();
        initModals();
        initRouter();
    });

    /* Expose for pages that need to call openModal / closeModal */
    window.openModal = function (id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('active');
        gsap.fromTo(
            modal.querySelector('.modal-box'),
            { y: 50, opacity: 0, scale: 0.95 },
            { y: 0, opacity: 1, scale: 1, duration: 0.45, ease: 'power3.out' }
        );
    };

    window.closeModal = function (id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.remove('active');
    };

})();
