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

    let navigationController = null;
    let navigationSequence = 0;
    let pageScriptController = null;
    let pageScriptScopeSequence = 0;
    let cursorGlowsInitialized = false;
    let modalStateObserver = null;

    function escapeHtml(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

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

    function hasGsap() {
        return typeof window.gsap !== 'undefined';
    }

    function runEntryAnimations() {
        if (!hasGsap()) return;

        const mc = document.querySelector('.main-content');
        if (!mc) return;

        gsap.fromTo(mc,
            { opacity: 0, y: 8 },
            { opacity: 1, y: 0, duration: 0.2, ease: 'power2.out', clearProps: 'transform' }
        );
    }

    function initCursorGlows() {
        // This handler belongs to the persistent admin shell. Re-registering it
        // after every AJAX navigation causes duplicated work on each pointer move.
        if (cursorGlowsInitialized || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
        cursorGlowsInitialized = true;

        let activeCard = null;
        let activeRect = null;
        let animationFrame = null;
        let lastX = 0, lastY = 0;

        document.addEventListener('mouseover', (e) => {
            const card = e.target.closest('.panel, .card, .role-action-btn, .dashboard-card, .stat-card, .stat-card-v2, .quick-action-btn, .utility-card, .program-card, .section-card');
            if (card && card !== activeCard) {
                activeCard = card;
                activeRect = card.getBoundingClientRect();
            }
        }, { passive: true });

        document.addEventListener('mousemove', (e) => {
            if (!activeCard || !activeRect) return;

            if (Math.abs(e.clientX - lastX) < 4 && Math.abs(e.clientY - lastY) < 4) return;
            lastX = e.clientX;
            lastY = e.clientY;

            if (animationFrame) return;
            animationFrame = requestAnimationFrame(() => {
                animationFrame = null;
                if (activeCard && activeRect) {
                    const x = e.clientX - activeRect.left;
                    const y = e.clientY - activeRect.top;
                    activeCard.style.setProperty('--mouse-x', `${x}px`);
                    activeCard.style.setProperty('--mouse-y', `${y}px`);
                }
            });
        }, { passive: true });
    }

    /* =====================================================
       SIDEBAR ANIMATIONS (first load only)
       ===================================================== */

    function runSidebarAnimations() {
        const links = document.querySelectorAll('.sidebar-link');

        /* Keep the sidebar usable even if an animation is interrupted. */
        links.forEach(link => {
            link.style.opacity = '';
            link.style.transform = '';
        });

        if (window.matchMedia('(max-width: 920px)').matches) return;

        if (!hasGsap()) return;

        gsap.from('.sidebar', {
            x: -40,
            duration: 1,
            ease: 'power3.out',
            clearProps: 'transform'
        });

        links.forEach((link, i) => {
            gsap.fromTo(link,
                { x: -20 },
                {
                x: 0,
                duration: 0.7, stagger: 0.04,
                delay: 0.25 + i * 0.04, ease: 'power2.out',
                clearProps: 'transform'
                }
            );
        });
    }

    /* =====================================================
       MOBILE SIDEBAR
       ===================================================== */
    function initSidebarToggle() {
        const mobileMenuBtn = document.getElementById('eventMobileMenuBtn');
        const navMenu = document.getElementById('eventNavMenu');



        if (mobileMenuBtn && navMenu) {
            mobileMenuBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                const isOpen = navMenu.classList.toggle('is-open');
                mobileMenuBtn.classList.toggle('is-open', isOpen);
                mobileMenuBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });
        }
    }    /* =====================================================
       ALERT SYSTEM
       ===================================================== */

    function initAlerts() {
        document.querySelectorAll('.alert').forEach(alert => {
            if (alert.dataset.initialized) return;
            alert.dataset.initialized = 'true';

            // Wrap text in span
            const content = alert.innerHTML;
            alert.innerHTML = `<span style="flex-grow: 1; padding-right: 8px;">${content}</span>`;

            // Close button
            const closeBtn = document.createElement('button');
            closeBtn.className = 'alert-close-btn';
            closeBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            closeBtn.setAttribute('type', 'button');
            alert.appendChild(closeBtn);

            // Progress bar
            const progressBar = document.createElement('div');
            progressBar.className = 'alert-progress';
            alert.appendChild(progressBar);

            if (hasGsap()) {
                const tl = gsap.timeline();
                
                // Animate entry with a 3D tilt and bounce
                tl.fromTo(alert, 
                    { opacity: 0, y: -24, rotationX: -15, transformPerspective: 800, transformOrigin: "top center" }, 
                    { opacity: 1, y: 0, rotationX: 1.5, duration: 0.75, ease: 'back.out(1.1)' }
                );

                // Progress indicator (12 seconds)
                tl.fromTo(progressBar, 
                    { scaleX: 1 }, 
                    { scaleX: 0, duration: 12, ease: 'none' }
                );

                // Auto dismiss (1.2s fadeout with tilt back)
                tl.to(alert, {
                    opacity: 0,
                    y: -16,
                    rotationX: -10,
                    height: 0,
                    paddingTop: 0,
                    paddingBottom: 0,
                    marginTop: 0,
                    marginBottom: 0,
                    borderWidth: 0,
                    duration: 1.2,
                    ease: 'power3.inOut',
                    onComplete: () => alert.remove()
                });

                closeBtn.addEventListener('click', () => {
                    tl.kill();
                    gsap.to(alert, {
                        opacity: 0,
                        y: -16,
                        rotationX: -10,
                        height: 0,
                        paddingTop: 0,
                        paddingBottom: 0,
                        marginTop: 0,
                        marginBottom: 0,
                        borderWidth: 0,
                        duration: 0.45,
                        ease: 'power3.out',
                        onComplete: () => alert.remove()
                    });
                });
            } else {
                // Fallback
                setTimeout(() => {
                    alert.style.transition = 'opacity 1.2s ease, height 1.2s ease, transform 1.2s ease';
                    alert.style.opacity = '0';
                    alert.style.transform = 'perspective(800px) rotateX(-10deg) translateY(-16px)';
                    setTimeout(() => alert.remove(), 1200);
                }, 12000);

                closeBtn.addEventListener('click', () => {
                    alert.style.transition = 'opacity 0.4s ease, height 0.4s ease';
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 400);
                });
            }
        });
    }

    /* =====================================================
       MODAL SYSTEM
       ===================================================== */

    function initModals() {
        document.querySelectorAll('[data-modal-open], [data-open-modal]').forEach(button => {
            button.addEventListener('click', () => {
                const target = button.getAttribute('data-modal-open') || button.getAttribute('data-open-modal');
                if (!target) return;
                const modal = target.startsWith('#') ? document.querySelector(target) : document.getElementById(target);
                if (!modal) return;
                window.openModal(modal.id);
            });
        });

        document.querySelectorAll('[data-modal-close], [data-close]').forEach(button => {
            button.addEventListener('click', () => {
                const targetId = button.getAttribute('data-modal-close') || button.getAttribute('data-close');
                if (targetId) {
                    window.closeModal(targetId);
                } else {
                    const modal = button.closest('.modal-overlay');
                    if (modal) window.closeModal(modal.id);
                }
            });
        });

        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    window.closeModal(modal.id);
                }
            });
        });
    }

    function syncModalScrollLock() {
        const hasOpenModal = Boolean(document.querySelector('.modal-overlay.active, .chat-modal-overlay.active'));
        document.documentElement.classList.toggle('modal-scroll-locked', hasOpenModal);
        document.body.classList.toggle('modal-open', hasOpenModal);
    }

    function initModalScrollLock() {
        if (modalStateObserver) return;

        modalStateObserver = new MutationObserver(syncModalScrollLock);
        modalStateObserver.observe(document.body, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['class']
        });
        syncModalScrollLock();
    }

    /* =====================================================
       INLINE SCRIPT EXECUTOR
       ===================================================== */

    function listenerOptionsWithSignal(options, signal) {
        if (options && typeof options === 'object') {
            return options.signal ? options : { ...options, signal };
        }

        return { capture: Boolean(options), signal };
    }

    function createPageEventTarget(target, signal, replayDomReady = false) {
        return new Proxy(target, {
            get(object, property) {
                if (property === 'addEventListener') {
                    return (type, listener, options) => {
                        const isReady = document.readyState !== 'loading';
                        const isLoadEvent = type === 'DOMContentLoaded' || type === 'load';
                        if (replayDomReady && isLoadEvent && isReady) {
                            if (!signal.aborted) {
                                const ev = new Event(type);
                                if (typeof listener === 'function') {
                                    listener.call(object, ev);
                                } else {
                                    listener?.handleEvent?.call(listener, ev);
                                }
                            }
                            return;
                        }

                        object.addEventListener(
                            type,
                            listener,
                            listenerOptionsWithSignal(options, signal)
                        );
                    };
                }

                // Page scripts run inside the AJAX content area. Track their
                // scheduled work so it cannot continue after the next page swap.
                if (property === 'setInterval' || property === 'setTimeout') {
                    const clearMethod = property === 'setInterval' ? 'clearInterval' : 'clearTimeout';
                    return (callback, delay, ...args) => {
                        const timerId = object[property](callback, delay, ...args);
                        signal.addEventListener('abort', () => object[clearMethod](timerId), { once: true });
                        return timerId;
                    };
                }

                if (property === 'requestAnimationFrame') {
                    return callback => {
                        const frameId = object.requestAnimationFrame(callback);
                        signal.addEventListener('abort', () => object.cancelAnimationFrame(frameId), { once: true });
                        return frameId;
                    };
                }

                const value = object[property];
                return typeof value === 'function' ? value.bind(object) : value;
            },
            set(object, property, value) {
                object[property] = value;
                return true;
            }
        });
    }

    function executeInlineScripts(container, signal) {
        const scopeKey = `__adminPageScriptScope${++pageScriptScopeSequence}`;
        window[scopeKey] = {
            document: createPageEventTarget(document, signal, true),
            window: createPageEventTarget(window, signal, true)
        };
        signal.addEventListener('abort', () => delete window[scopeKey], { once: true });

        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            try {
                const newScript = document.createElement('script');
                if (oldScript.src) {
                    newScript.src = oldScript.src;
                } else {
                    /*
                     * AJAX pages are inserted more than once during a session.
                     * Page scripts commonly declare top-level const/let variables;
                     * running those declarations in the window scope a second time
                     * throws a SyntaxError and prevents every handler below it from
                     * being registered. Give each injected page its own scope while
                     * keeping explicitly exported window.* helpers available.
                     */
                    const source = oldScript.textContent || '';
                    const isModule = oldScript.type === 'module';
                    newScript.textContent = isModule
                        ? source
                        : `((document, window, setInterval, clearInterval, setTimeout, clearTimeout, requestAnimationFrame, cancelAnimationFrame) => {\n${source}\n})(window[${JSON.stringify(scopeKey)}].document, window[${JSON.stringify(scopeKey)}].window, window[${JSON.stringify(scopeKey)}].window.setInterval, window[${JSON.stringify(scopeKey)}].window.clearInterval, window[${JSON.stringify(scopeKey)}].window.setTimeout, window[${JSON.stringify(scopeKey)}].window.clearTimeout, window[${JSON.stringify(scopeKey)}].window.requestAnimationFrame, window[${JSON.stringify(scopeKey)}].window.cancelAnimationFrame);`;
                }
                for (const attr of oldScript.attributes) {
                    if (attr.name !== 'src') {
                        newScript.setAttribute(attr.name, attr.value);
                    }
                }

                oldScript.parentNode.replaceChild(newScript, oldScript);
            } catch (scriptErr) {
                console.error('[AJAX Router] Inline script execution failed:', scriptErr);
            }
        });
    }

    function closeSidebarOnMobile() {
        const wrapper = document.querySelector('.nav-container-wrapper');
        const rolesDropdown = document.getElementById('slidingNavRolesDropdown');
        if (wrapper) {
            wrapper.classList.remove('open');
            localStorage.setItem('admin_nav_expanded', 'false');
        }
        if (rolesDropdown) {
            rolesDropdown.classList.remove('open');
        }
    }

    /* =====================================================
       SIDEBAR ACTIVE STATE
       ===================================================== */

    function updateSidebarActive(url) {
        let currentUrl;
        try {
            currentUrl = new URL(url, location.origin);
        } catch {
            return;
        }

        const normPath = (pathname) => {
            let p = pathname.replace(/\.php$/i, '');
            if (p.length > 1 && p.endsWith('/')) p = p.slice(0, -1);
            return p;
        };

        const currentPath = normPath(currentUrl.pathname);
        const currentQuery = currentUrl.searchParams;

        const links = Array.from(document.querySelectorAll('.sidebar-link, .nav-item-link, .role-item-link, .sidebar-vertical-link'));

        let bestLink = null;
        let bestScore = -1;

        links.forEach(link => {
            if (!link.href) return;
            let linkUrl;
            try {
                linkUrl = new URL(link.href, location.origin);
            } catch {
                return;
            }

            const linkPath = normPath(linkUrl.pathname);
            const linkQuery = linkUrl.searchParams;

            let score = -1;

            if (currentPath === linkPath) {
                score = 10;
                let queryMatchCount = 0;
                let queryMismatch = false;

                linkQuery.forEach((val, key) => {
                    if (currentQuery.get(key) === val) {
                        queryMatchCount++;
                    } else {
                        queryMismatch = true;
                    }
                });

                if (queryMismatch) {
                    score = 0;
                } else {
                    score += queryMatchCount * 10;
                }
            } else if (linkPath.endsWith('/index') && currentPath === normPath(linkPath.replace(/\/index$/, ''))) {
                score = 8;
            }

            if (score > bestScore) {
                bestScore = score;
                bestLink = link;
            }
        });

        links.forEach(link => {
            if (bestLink && link === bestLink && bestScore > 0) {
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
        const navigationId = ++navigationSequence;
        navigationController?.abort();
        navigationController = new AbortController();
        showLoader();

        try {
            const resp = await fetch(url, {
                headers: AJAX_HEADER,
                credentials: 'same-origin',
                signal: navigationController.signal
            });

            if (navigationId !== navigationSequence) return;

            if (resp.redirected && !isAdminUrl(resp.url)) {
                location.href = resp.url;
                return;
            }

            if (!resp.ok) {
                throw new Error(`Request failed with status ${resp.status}`);
            }

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
            if (navigationId === navigationSequence) {
                swapContent(html, url, pushState, navigationId);
            }

        } catch (err) {
            if (err.name === 'AbortError') return;
            console.error('[AJAX Router] Navigation failed:', err);
            location.href = url; /* Fallback to full reload */
        }
    }

    function swapContent(html, url, pushState, navigationId = navigationSequence) {
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

        const completeSwap = () => {
                if (navigationId !== navigationSequence) return;

                // Give the outgoing page a chance to release charts, timers, or
                // other resources that were created on a full page load.
                window.dispatchEvent(new CustomEvent('admin:before-content-swap'));
                pageScriptController?.abort();
                pageScriptController = new AbortController();
                window.adminAjaxPaginationFetch = null;

                // Clone fragment and remove any nested layout sidebars/headers to prevent duplication
                const contentClone = newContent.cloneNode(true);
                contentClone.querySelectorAll('.sidebar-vertical, .sidebar, .nav-container-wrapper, .event-top-nav').forEach(el => el.remove());

                mainContent.innerHTML = contentClone.innerHTML;
                mainContent.className = contentClone.className;

                // Remove any accidental duplicate sidebars or nav containers outside main-content
                const sidebars = document.querySelectorAll('.sidebar-vertical');
                if (sidebars.length > 1) {
                    for (let i = 1; i < sidebars.length; i++) {
                        sidebars[i].remove();
                    }
                }

                // Clear previously injected AJAX inline styles to avoid conflicts
                document.querySelectorAll('style[data-ajax-injected]').forEach(el => el.remove());

                // Dynamically update horizontal navigation header links if present in response
                const newNavHeader = doc.querySelector('.sliding-nav-header');
                const currentNavHeader = document.querySelector('.sliding-nav-header');
                if (newNavHeader && currentNavHeader) {
                    currentNavHeader.innerHTML = newNavHeader.innerHTML;
                }

                // Dynamically update vertical sidebar navigation if present in response
                const newSidebar = doc.querySelector('.sidebar-vertical');
                const currentSidebar = document.querySelector('.sidebar-vertical');
                if (newSidebar && currentSidebar) {
                    currentSidebar.innerHTML = newSidebar.innerHTML;
                }

                // Inject inline style elements from the response so they parse immediately
                doc.querySelectorAll('style').forEach(style => {
                    const s = document.createElement('style');
                    s.setAttribute('data-ajax-injected', 'true');
                    s.textContent = style.textContent;
                    document.head.appendChild(s);
                });

                // Inject page-specific external stylesheets
                doc.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
                    const href = link.getAttribute('href');
                    if (href && !document.querySelector('link[rel="stylesheet"][href="' + href.replace(/"/g, '\\"') + '"]')) {
                        document.head.appendChild(link.cloneNode(true));
                    }
                });

                // Inject page-specific external scripts
                doc.querySelectorAll('script[src]').forEach(script => {
                    const src = script.getAttribute('src');
                    if (src && !document.querySelector('script[src="' + src.replace(/"/g, '\\"') + '"]')) {
                        const s = document.createElement('script');
                        s.src = src;
                        document.head.appendChild(s);
                    }
                });

                /*
                 * Some PHP pages emit their initializer, pagination helper or
                 * page style immediately after .main-content. DOM-only swaps
                 * used to discard those nodes, leaving a page that looked
                 * loaded but had no working controls until a full refresh.
                 */
                if (newContent !== doc.body) {
                    Array.from(doc.body.children).forEach(sibling => {
                        if (['SCRIPT', 'STYLE', 'LINK'].includes(sibling.tagName) || sibling.classList?.contains('modal-overlay')) {
                            mainContent.appendChild(sibling.cloneNode(true));
                        }
                    });
                    /* Also copy any nested modal overlays that were outside newContent */
                    doc.querySelectorAll('.modal-overlay').forEach(modal => {
                        if (!newContent.contains(modal) && !mainContent.querySelector('#' + CSS.escape(modal.id))) {
                            mainContent.appendChild(modal.cloneNode(true));
                        }
                    });
                }

                // Ensure no nested sidebar or nav header exists inside mainContent
                mainContent.querySelectorAll('.sidebar-vertical, .sidebar, .nav-container-wrapper, .event-top-nav').forEach(el => el.remove());

                /* Execute inline scripts */
                executeInlineScripts(mainContent, pageScriptController.signal);

                /* Re-init modals */
                initModals();
                initAlerts();
                initCursorGlows();

                /* Let page features that need a fresh DOM re-initialize safely. */
                window.dispatchEvent(new CustomEvent('admin:content-swapped', {
                    detail: { url, container: mainContent }
                }));

                /* Animate in new content */
                if (hasGsap()) {
                    mainContent.style.opacity = '0';
                    runEntryAnimations();
                } else {
                    mainContent.style.opacity = '';
                    mainContent.style.transform = '';
                }

                /* Update sidebar */
                updateSidebarActive(url);
                closeSidebarOnMobile();

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
        };

        /* Keep navigation functional if the optional animation CDN is down. */
        if (hasGsap()) {
            gsap.killTweensOf(mainContent);
            gsap.to(mainContent, {
                opacity: 0,
                y: -10,
                duration: 0.18,
                ease: 'power2.in',
                onComplete: completeSwap
            });
        } else {
            completeSwap();
        }
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
            // A page-level AJAX widget (search, filter, etc.) may already have
            // handled this submit. Do not replace its targeted update with a
            // full main-content swap.
            if (e.defaultPrevented) return;

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

    function initAjaxPaginationControls() {
        document.addEventListener('click', (e) => {
            const fetchResults = window.adminAjaxPaginationFetch;
            if (typeof fetchResults !== 'function') return;

            const pageBtn = e.target.closest('.ajax-page-btn');
            if (pageBtn) {
                e.preventDefault();
                const limit = new URLSearchParams(window.location.search).get('limit') || '';
                fetchResults(pageBtn.dataset.page, limit);
                return;
            }

            const limitBtn = e.target.closest('.limit-btn');
            if (limitBtn) {
                e.preventDefault();
                fetchResults(1, limitBtn.dataset.limit);
                return;
            }

            const trigger = e.target.closest('.active-limit-trigger');
            if (trigger) {
                const popover = trigger.nextElementSibling;
                if (popover?.classList.contains('limit-options-popover')) {
                    popover.classList.toggle('active');
                }
                return;
            }

            if (!e.target.closest('.limit-popover-container')) {
                document.querySelectorAll('.limit-options-popover.active').forEach(popover => {
                    popover.classList.remove('active');
                });
            }
        });
    }

    /* =====================================================
       INITIALIZATION
       ===================================================== */

    window.addEventListener('DOMContentLoaded', () => {
        runSidebarAnimations();
        runEntryAnimations();
        initCursorGlows();
        initSidebarToggle();
        initModals();
        initModalScrollLock();
        initAlerts();
        // initRouter(); // Replaced by HTMX
        // initAjaxPaginationControls(); // Replaced by HTMX
        initAdminChat();
    });

    /* =====================================================
       ADMIN CHAT SYSTEM JS
       ===================================================== */
    let chatPollInterval = null;
    let chatUnreadInterval = null;
    let activeRoomId = 'global'; // 'global' or integer user ID

    function chatApiUrl(action, params = {}) {
        const url = new URL(`${APP_URL}/admin/chat-api.php`, window.location.origin);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([key, value]) => {
            url.searchParams.set(key, value ?? '');
        });
        return url.toString();
    }

    function setChatFeedState(message, type = 'muted') {
        const feed = document.getElementById('chatMessagesFeed');
        if (!feed) return;

        feed.innerHTML = `<div class="chat-empty-state chat-empty-state--${escapeHtml(type)}">${escapeHtml(message)}</div>`;
    }

    async function parseChatResponse(res) {
        const data = await res.json().catch(() => null);
        if (!res.ok || !data?.success) {
            throw new Error(data?.error || 'Chat request failed.');
        }
        return data;
    }

    async function fetchChatMessages() {
        if (document.hidden) return;
        const receiverId = activeRoomId === 'global' ? '' : activeRoomId;
        const feed = document.getElementById('chatMessagesFeed');
        if (!feed) return;

        try {
            const res = await fetch(chatApiUrl('get_messages', { receiver_id: receiverId }), {
                headers: AJAX_HEADER,
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await parseChatResponse(res);
            if (Array.isArray(data.messages)) {
                let html = '';
                let maxMsgId = 0;
                data.messages.forEach(msg => {
                    const isMe = msg.is_me ? 'me' : '';
                    html += `
                        <div class="chat-msg ${isMe}">
                            ${!msg.is_me ? `<img class="chat-msg-avatar" src="${escapeHtml(msg.sender_avatar)}" alt="${escapeHtml(msg.sender_name)}">` : ''}
                            <div class="chat-msg-content">
                                ${!msg.is_me ? `<span class="chat-sender-name">${escapeHtml(msg.sender_name)}</span>` : ''}
                                <div class="chat-bubble">
                                    ${escapeHtml(msg.message)}
                                </div>
                                <span class="chat-meta">${msg.time}</span>
                            </div>
                        </div>
                    `;
                    maxMsgId = Math.max(maxMsgId, parseInt(msg.id, 10));
                });

                const oldHtml = feed.innerHTML;
                feed.innerHTML = html || '<div class="chat-empty-state">No messages yet. Say hello!</div>';

                if (oldHtml !== feed.innerHTML) {
                    feed.scrollTop = feed.scrollHeight;
                }

                if (maxMsgId > 0) {
                    localStorage.setItem('chat_last_seen_' + activeRoomId, String(maxMsgId));
                    if (activeRoomId === 'global') {
                        const badge = document.getElementById('chatUnreadCount');
                        if (badge) badge.style.display = 'none';
                    }
                }
            }
        } catch (e) {
            console.error('Chat poll failed:', e);
            setChatFeedState(e.message || 'Unable to load chat messages.', 'error');
        }
    }

    async function loadChatUsers() {
        const container = document.getElementById('chatUsersListContainer');
        if (!container) return;

        try {
            const res = await fetch(chatApiUrl('get_users'), {
                headers: AJAX_HEADER,
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await parseChatResponse(res);
            if (Array.isArray(data.users)) {
                let html = '';
                data.users.forEach(user => {
                    const isActive = activeRoomId === parseInt(user.id, 10) ? 'active' : '';
                    html += `
                        <div class="chat-user-item ${isActive}" data-user-id="${user.id}" data-room-type="direct">
                            <img class="chat-item-avatar" src="${escapeHtml(user.avatar)}" alt="${escapeHtml(user.full_name || user.username)}">
                            <div class="chat-item-details">
                                <span class="chat-item-name">${escapeHtml(user.full_name || user.username)}</span>
                                <span class="chat-item-status">${escapeHtml(user.status)}</span>
                            </div>
                        </div>
                    `;
                });
                container.innerHTML = html;

                container.querySelectorAll('.chat-user-item').forEach(item => {
                    item.addEventListener('click', function() {
                        const userId = parseInt(this.dataset.userId, 10);
                        switchChatRoom(userId, this.querySelector('.chat-item-name').textContent);
                    });
                });
            }
        } catch (e) {
            console.error('Failed to load chat users:', e);
            container.innerHTML = '<div class="chat-user-empty">Unable to load users</div>';
        }
    }

    function switchChatRoom(roomId, roomName) {
        activeRoomId = roomId;
        const receiverInput = document.getElementById('chatActiveReceiverId');
        if (receiverInput) receiverInput.value = roomId === 'global' ? '' : String(roomId);
        
        const activeRoomNameEl = document.getElementById('chatActiveRoomName');
        if (activeRoomNameEl) activeRoomNameEl.textContent = roomName;

        document.querySelectorAll('.chat-user-item').forEach(item => {
            if (roomId === 'global' && item.id === 'chatRoomGlobal') {
                item.classList.add('active');
            } else if (roomId !== 'global' && parseInt(item.dataset.userId, 10) === roomId) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });

        const feed = document.getElementById('chatMessagesFeed');
        if (feed) feed.innerHTML = '';
        fetchChatMessages();
    }

    async function checkUnreadMessages() {
        if (document.hidden) return;
        if (document.getElementById('globalChatModal')?.classList.contains('active') && activeRoomId === 'global') {
            return;
        }
        try {
            const lastSeen = parseInt(localStorage.getItem('chat_last_seen_global') || '0', 10);
            const res = await fetch(chatApiUrl('get_unread_count', { after_id: lastSeen }), {
                headers: AJAX_HEADER,
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await parseChatResponse(res);
            if (Number.isInteger(data.unread_count)) {
                const badge = document.getElementById('chatUnreadCount');
                if (badge) {
                    if (data.unread_count > 0) {
                        badge.textContent = String(data.unread_count);
                        badge.style.display = 'inline-block';
                    } else {
                        badge.style.display = 'none';
                    }
                }
            }
        } catch (e) {
            console.error('Failed to check unread chat messages:', e);
        }
    }

    function initAdminChat() {
        const chatOpenBtn = document.getElementById('sidebarChatBtn');
        const chatCloseBtn = document.getElementById('closeChatModalBtn');
        const chatOverlay = document.getElementById('globalChatModal');
        const chatForm = document.getElementById('chatMessageForm');
        const chatGlobalRoom = document.getElementById('chatRoomGlobal');

        if (!chatOpenBtn || !chatOverlay) return;

        // Open chat modal
        chatOpenBtn.addEventListener('click', (e) => {
            e.preventDefault();
            switchChatRoom(activeRoomId || 'global', activeRoomId === 'global' ? 'Global Lounge' : (document.getElementById('chatActiveRoomName')?.textContent || 'Direct Message'));
            chatOverlay.classList.add('active');
            chatOverlay.setAttribute('aria-hidden', 'false');
            
            // GSAP slide-in
            if (typeof window.gsap !== 'undefined') {
                window.gsap.fromTo('#globalChatModal .chat-modal-container', 
                    { x: '100%' }, 
                    { x: '0%', duration: 0.45, ease: 'power3.out' }
                );
            }

            loadChatUsers();
            fetchChatMessages();

            // Start polling loop (every 3 seconds)
            clearInterval(chatPollInterval);
            chatPollInterval = setInterval(fetchChatMessages, 3000);
        });

        // Close chat modal
        const closeChat = () => {
            clearInterval(chatPollInterval);
            if (typeof window.gsap !== 'undefined') {
                window.gsap.to('#globalChatModal .chat-modal-container', {
                    x: '100%', 
                    duration: 0.35, 
                    ease: 'power2.in',
                    onComplete: () => {
                        chatOverlay.classList.remove('active');
                        chatOverlay.setAttribute('aria-hidden', 'true');
                        checkUnreadMessages();
                    }
                });
            } else {
                chatOverlay.classList.remove('active');
                chatOverlay.setAttribute('aria-hidden', 'true');
                checkUnreadMessages();
            }
        };

        if (chatCloseBtn) chatCloseBtn.addEventListener('click', closeChat);
        chatOverlay.addEventListener('click', (e) => {
            if (e.target === chatOverlay) closeChat();
        });

        // Switch to global room
        if (chatGlobalRoom) {
            chatGlobalRoom.addEventListener('click', () => {
                switchChatRoom('global', 'Global Lounge');
            });
        }

        // Send message form handler
        if (chatForm) {
            chatForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const input = document.getElementById('chatInputMessage');
                const sendBtn = chatForm.querySelector('.chat-send-btn');
                if (!input) return;
                const messageText = input.value.trim();
                if (messageText === '') return;

                const formData = new FormData(chatForm);
                formData.append('action', 'send_message');

                try {
                    input.value = '';
                    input.disabled = true;
                    if (sendBtn) sendBtn.disabled = true;
                    const res = await fetch(`${APP_URL}/admin/chat-api.php`, {
                        method: 'POST',
                        body: formData,
                        headers: AJAX_HEADER,
                        credentials: 'same-origin'
                    });
                    await parseChatResponse(res);
                    await fetchChatMessages();
                } catch (err) {
                    console.error('Failed to send chat message:', err);
                    setChatFeedState(err.message || 'Unable to send message.', 'error');
                    input.value = messageText;
                } finally {
                    input.disabled = false;
                    if (sendBtn) sendBtn.disabled = false;
                    input.focus();
                }
            });
        }

        // Start background unread polling (every 8 seconds)
        clearInterval(chatUnreadInterval);
        checkUnreadMessages();
        chatUnreadInterval = setInterval(checkUnreadMessages, 8000);
    }

    /* Expose for pages that need to call openModal / closeModal */
    window.openModal = function (id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.classList.add('active');
        syncModalScrollLock();
        gsap.fromTo(
            modal.querySelector('.modal-box'),
            { y: 50, opacity: 0, scale: 0.95 },
            { y: 0, opacity: 1, scale: 1, duration: 0.45, ease: 'power3.out' }
        );
    };

    window.closeModal = function (id) {
        const modal = document.getElementById(id);
        if (modal) modal.classList.remove('active');
        syncModalScrollLock();
    };


    /* =====================================================
       HTMX INTEGRATION FOR SPA NAVIGATION
       ===================================================== */
    if (typeof htmx !== 'undefined') {
        // Configure HTMX to send X-Requested-With header to reuse backend optimizations
        htmx.config.headers = htmx.config.headers || {};
        htmx.config.headers['X-Requested-With'] = 'XMLHttpRequest';

        // Listen to HTMX shouldProcess event to ignore data-ajax-ignore and certain paths
        document.addEventListener('htmx:shouldProcess', (evt) => {
            const el = evt.detail.elt;
            if (el.hasAttribute('data-ajax-ignore') || el.closest('[data-ajax-ignore]')) {
                evt.preventDefault();
                return;
            }
            const url = el.href || el.action;
            if (url) {
                if (url.includes('/tv/') || url.includes('/auth/') || url.includes('/utilities/')) {
                    evt.preventDefault();
                    return;
                }
                // If it is an anchor, ensure it belongs to the admin workspace
                if (el.tagName === 'A' && !isAdminUrl(url)) {
                    evt.preventDefault();
                }
            }
        });

        // Handle show loader and dispatch cleanup events
        document.addEventListener('htmx:beforeRequest', (evt) => {
            showLoader();
            window.dispatchEvent(new CustomEvent('admin:before-content-swap'));
        });

        // Hide loader after request
        document.addEventListener('htmx:afterRequest', (evt) => {
            hideLoader();
        });

        // Intercept JSON redirects returned by admin_redirect()
        document.addEventListener('htmx:beforeSwap', (evt) => {
            const xhr = evt.detail.xhr;
            if (xhr.getResponseHeader('Content-Type')?.includes('application/json')) {
                try {
                    const data = JSON.parse(xhr.responseText);
                    if (data.redirect) {
                        evt.preventDefault(); // Stop the current swap
                        htmx.ajax('GET', data.redirect, { target: '.main-content' });
                    }
                } catch (e) {
                    console.error('[HTMX] JSON redirect parsing failed:', e);
                }
            }
        });

        // Re-initialize layout scripts and animations after page content swap
        document.addEventListener('htmx:afterSwap', (evt) => {
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                // Ensure no nested sidebar or nav header exists inside mainContent
                mainContent.querySelectorAll('.sidebar-vertical, .sidebar, .nav-container-wrapper, .event-top-nav').forEach(el => el.remove());
                
                // Clear previously injected AJAX inline styles to avoid conflicts
                document.querySelectorAll('style[data-ajax-injected]').forEach(el => el.remove());
                
                // Re-initialize UI widgets
                initModals();
                initAlerts();
                initCursorGlows();

                // Dispatch content-swapped event for analytics and other hooks
                const url = evt.detail.pathInfo?.requestPath || location.href;
                window.dispatchEvent(new CustomEvent('admin:content-swapped', {
                    detail: { url, container: mainContent }
                }));

                // Animate in the new content
                if (hasGsap()) {
                    mainContent.style.opacity = '0';
                    runEntryAnimations();
                } else {
                    mainContent.style.opacity = '';
                    mainContent.style.transform = '';
                }

                // Update active states and close mobile drawer
                updateSidebarActive(url);
                closeSidebarOnMobile();

                // Scroll to top of main content
                mainContent.scrollTo({ top: 0, behavior: 'smooth' });
            }
        });

        // Handle page titles dynamically if a title element is returned
        document.addEventListener('htmx:afterOnLoad', (evt) => {
            const mainContent = document.querySelector('.main-content');
            if (mainContent) {
                const titleEl = mainContent.querySelector('.page-title');
                if (titleEl) {
                    document.title = titleEl.textContent.trim() + ' — Kauzariyya Musabaqa';
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.main-content .sidebar-vertical, .main-content .sidebar').forEach(el => el.remove());
        const sidebars = document.querySelectorAll('.sidebar-vertical');
        if (sidebars.length > 1) {
            for (let i = 1; i < sidebars.length; i++) {
                sidebars[i].remove();
            }
        }
        updateSidebarActive(location.href);
    });

})();
