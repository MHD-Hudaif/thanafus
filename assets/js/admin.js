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

    function setSidebarOpen(isOpen) {
        const toggle = document.querySelector('[data-sidebar-toggle]');
        document.body.classList.toggle('sidebar-open', isOpen);

        if (toggle) {
            toggle.setAttribute('aria-expanded', String(isOpen));
            toggle.setAttribute('aria-label', isOpen ? 'Close sidebar menu' : 'Open sidebar menu');
        }
    }

    function closeSidebarOnMobile() {
        if (window.matchMedia('(max-width: 920px)').matches) {
            setSidebarOpen(false);
        }
    }

    function initSidebarToggle() {
        const toggle = document.querySelector('[data-sidebar-toggle]');
        const overlay = document.querySelector('[data-sidebar-overlay]');

        if (toggle) {
            toggle.addEventListener('click', () => {
                setSidebarOpen(!document.body.classList.contains('sidebar-open'));
            });
        }

        if (overlay) {
            overlay.addEventListener('click', () => setSidebarOpen(false));
        }

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                setSidebarOpen(false);
            }
        });

        document.addEventListener('click', (e) => {
            const sidebarLink = e.target.closest('.sidebar a');
            if (sidebarLink) {
                closeSidebarOnMobile();
            }
        });

        window.addEventListener('resize', () => {
            if (!window.matchMedia('(max-width: 920px)').matches) {
                setSidebarOpen(false);
            }
        });
    }

    /* =====================================================
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

        document.querySelectorAll('[data-modal-open]').forEach(button => {
            button.addEventListener('click', () => {
                const target = button.getAttribute('data-modal-open');
                const modal = document.querySelector(target);
                if (!modal) return;
                modal.classList.add('active');
                if (hasGsap()) {
                    gsap.fromTo(
                        modal.querySelector('.modal-box'),
                        { y: 50, opacity: 0, scale: 0.95 },
                        { y: 0, opacity: 1, scale: 1, duration: 0.45, ease: 'power3.out' }
                    );
                }
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
                        if (replayDomReady && type === 'DOMContentLoaded' && document.readyState !== 'loading') {
                            if (!signal.aborted) {
                                if (typeof listener === 'function') {
                                    listener.call(object, new Event('DOMContentLoaded'));
                                } else {
                                    listener?.handleEvent?.call(listener, new Event('DOMContentLoaded'));
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

                const value = Reflect.get(object, property, object);
                return typeof value === 'function' ? value.bind(object) : value;
            },
            set(object, property, value) {
                return Reflect.set(object, property, value, object);
            }
        });
    }

    function executeInlineScripts(container, signal) {
        const scopeKey = `__adminPageScriptScope${++pageScriptScopeSequence}`;
        window[scopeKey] = {
            document: createPageEventTarget(document, signal, true),
            window: createPageEventTarget(window, signal)
        };
        signal.addEventListener('abort', () => delete window[scopeKey], { once: true });

        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
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
                    : `((document, window) => {\n${source}\n})(window[${JSON.stringify(scopeKey)}].document, window[${JSON.stringify(scopeKey)}].window);`;
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
        const normalize = (u) => new URL(u, location.origin).pathname.replace(/\.php$/i, '').replace(/\/$/, '');
        const path = normalize(url);
        document.querySelectorAll('.sidebar-link').forEach(link => {
            const linkPath = normalize(link.href);
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
        /* Event workspaces use a different shell and page-specific styles. */
        if (url.includes('/admin/event/')) return false;
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

                pageScriptController?.abort();
                pageScriptController = new AbortController();
                window.adminAjaxPaginationFetch = null;

                mainContent.innerHTML = newContent.innerHTML;
                mainContent.className = newContent.className;

                /*
                 * Some PHP pages emit their initializer, pagination helper or
                 * page style immediately after .main-content. DOM-only swaps
                 * used to discard those nodes, leaving a page that looked
                 * loaded but had no working controls until a full refresh.
                 */
                if (newContent !== doc.body) {
                    Array.from(doc.body.children).forEach(sibling => {
                        if (sibling !== newContent && !sibling.contains(newContent)) {
                            mainContent.appendChild(sibling.cloneNode(true));
                        }
                    });
                }

                /* Execute inline scripts */
                executeInlineScripts(mainContent, pageScriptController.signal);

                /* Re-init modals */
                initModals();
                initAlerts();

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
        initSidebarToggle();
        initModals();
        initAlerts();
        initRouter();
        initAjaxPaginationControls();
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
        if (document.getElementById('globalChatModal')?.classList.contains('active') && activeRoomId === 'global') {
            return;
        }
        try {
            const res = await fetch(chatApiUrl('get_messages', { receiver_id: '' }), {
                headers: AJAX_HEADER,
                credentials: 'same-origin',
                cache: 'no-store'
            });
            const data = await parseChatResponse(res);
            if (Array.isArray(data.messages)) {
                const lastSeen = parseInt(localStorage.getItem('chat_last_seen_global') || '0', 10);
                let unreadCount = 0;
                data.messages.forEach(msg => {
                    if (!msg.is_me && parseInt(msg.id, 10) > lastSeen) {
                        unreadCount++;
                    }
                });

                const badge = document.getElementById('chatUnreadCount');
                if (badge) {
                    if (unreadCount > 0) {
                        badge.textContent = String(unreadCount);
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
