/* Premium Hybrid Workspace Sidebar Behavior JavaScript */
(() => {
    'use strict';

    // Global selector helpers
    const getSidebar = () => document.getElementById('adminVerticalSidebar');
    const isCollapsed = () => document.body.classList.contains('sidebar-collapsed');

    // Tooltip management instance
    let tooltipEl = null;

    function initTooltip() {
        if (!tooltipEl) {
            tooltipEl = document.createElement('div');
            tooltipEl.className = 'sidebar-tooltip';
            document.body.appendChild(tooltipEl);
        }
    }

    // Initialize sidebar behaviors
    function initSidebar() {
        const sidebar = getSidebar();
        if (!sidebar) return;

        initTooltip();

        // 1. Recover collapsed/expanded sidebar mode from localStorage
        const collapsedState = localStorage.getItem('sidebar_collapsed_mode') === 'true';
        document.body.classList.toggle('sidebar-collapsed', collapsedState);

        // 2. Recover expanded workspace section from localStorage
        const activeGroup = localStorage.getItem('sidebar_expanded_group');
        document.querySelectorAll('.sidebar-group').forEach(group => {
            const groupId = group.id;
            if (activeGroup && groupId === activeGroup) {
                group.classList.remove('collapsed');
            } else {
                group.classList.add('collapsed');
            }
        });

        // 3. Setup Accordion Workspace toggling
        document.querySelectorAll('.sidebar-group-header').forEach(header => {
            header.addEventListener('click', function(e) {
                // Ignore click if sidebar is collapsed on desktop (submenus always open as hover panels/expanded)
                if (window.innerWidth > 920 && isCollapsed()) return;

                const currentGroup = this.closest('.sidebar-group');
                const isCurrentCollapsed = currentGroup.classList.contains('collapsed');

                // Accordion behavior: collapse all other groups first
                document.querySelectorAll('.sidebar-group').forEach(g => {
                    g.classList.add('collapsed');
                });

                if (isCurrentCollapsed) {
                    currentGroup.classList.remove('collapsed');
                    localStorage.setItem('sidebar_expanded_group', currentGroup.id);
                } else {
                    localStorage.removeItem('sidebar_expanded_group');
                }
            });
        });

        // 4. Setup Sidebar Collapse / Expand Trigger Button
        const collapseTrigger = document.querySelector('.sidebar-collapse-trigger');
        if (collapseTrigger) {
            collapseTrigger.addEventListener('click', function(e) {
                e.stopPropagation();
                const willCollapse = !isCollapsed();
                document.body.classList.toggle('sidebar-collapsed', willCollapse);
                localStorage.setItem('sidebar_collapsed_mode', String(willCollapse));
                
                // Clear any lingering tooltips on mode switch
                if (tooltipEl) tooltipEl.classList.remove('visible');
            });
        }

        // 5. Setup Collapsed Mode Tooltips (hovering icons only)
        document.querySelectorAll('.sidebar-vertical-link').forEach(link => {
            link.addEventListener('mouseenter', function() {
                // Only show tooltips if sidebar is collapsed on desktop, or in tablet viewport (width between 768px and 1024px)
                const isTablet = (window.innerWidth >= 768 && window.innerWidth <= 1024);
                if (isCollapsed() || isTablet) {
                    const label = this.querySelector('.sidebar-label')?.textContent || '';
                    if (!label) return;

                    initTooltip();
                    tooltipEl.textContent = label;
                    tooltipEl.classList.add('visible');

                    // Position tooltip relative to hovered link bounding rectangle
                    const rect = this.getBoundingClientRect();
                    tooltipEl.style.top = `${rect.top + (rect.height - tooltipEl.offsetHeight) / 2}px`;
                    tooltipEl.style.left = `${rect.right + 12}px`;
                }
            });

            link.addEventListener('mouseleave', () => {
                if (tooltipEl) tooltipEl.classList.remove('visible');
            });
        });

        // 6. Setup Command Palette Modal Controls
        const cmdPalette = document.getElementById('sidebarCommandPalette');
        const searchInput = document.getElementById('sidebarCommandInput');
        const openTrigger = document.getElementById('sidebarSearchLauncher');
        const closeBtn = document.getElementById('sidebarCommandClose');

        function openPalette() {
            if (cmdPalette) {
                cmdPalette.classList.add('visible');
                if (searchInput) {
                    searchInput.value = '';
                    searchInput.focus();
                }
                filterCommandItems('');
            }
        }

        function closePalette() {
            if (cmdPalette) {
                cmdPalette.classList.remove('visible');
            }
        }

        if (openTrigger) openTrigger.addEventListener('click', openPalette);
        if (closeBtn) closeBtn.addEventListener('click', closePalette);

        if (cmdPalette) {
            // Close palette if clicking backdrop
            cmdPalette.addEventListener('click', function(e) {
                if (e.target === this) closePalette();
            });
        }

        // 7. Keydown listeners for Command Palette search results navigation
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                filterCommandItems(this.value);
            });

            searchInput.addEventListener('keydown', function(e) {
                const resultsContainer = document.getElementById('sidebarCommandResults');
                if (!resultsContainer) return;

                const items = Array.from(resultsContainer.querySelectorAll('.sidebar-command-item'));
                if (!items.length) return;

                const activeIdx = items.findIndex(item => item.classList.contains('selected'));

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    let nextIdx = activeIdx + 1;
                    if (nextIdx >= items.length) nextIdx = 0;
                    
                    items.forEach(it => it.classList.remove('selected'));
                    items[nextIdx].classList.add('selected');
                    items[nextIdx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    let prevIdx = activeIdx - 1;
                    if (prevIdx < 0) prevIdx = items.length - 1;

                    items.forEach(it => it.classList.remove('selected'));
                    items[prevIdx].classList.add('selected');
                    items[prevIdx].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    e.preventDefault();
                    const selectedItem = items[activeIdx] || items[0];
                    if (selectedItem) {
                        closePalette();
                        selectedItem.click(); // Trigger navigation link click
                    }
                }
            });
        }

        // Global Shortcut CTRL + K or CMD + K
        window.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                openPalette();
            }
            if (e.key === 'Escape') {
                closePalette();
                // Escape key also closes mobile sidebar drawer if open
                if (window.innerWidth <= 920 && document.body.classList.contains('sidebar-open')) {
                    document.body.classList.remove('sidebar-open');
                }
            }
        });

        // Initialize Lucide SVG icons rendering
        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    // Match links and filter search results inside Command Palette modal
    function filterCommandItems(query) {
        const resultsContainer = document.getElementById('sidebarCommandResults');
        if (!resultsContainer) return;

        const term = query.trim().toLowerCase();
        
        // Fetch all navigation items from the sidebar HTML markup directly
        const sourceLinks = Array.from(document.querySelectorAll('.sidebar-vertical-link'));
        resultsContainer.innerHTML = '';

        if (!sourceLinks.length) {
            resultsContainer.innerHTML = '<div class="sidebar-command-empty">No items configured.</div>';
            return;
        }

        const filtered = sourceLinks.filter(link => {
            const text = link.querySelector('.sidebar-label')?.textContent.toLowerCase() || '';
            const href = link.getAttribute('href') || '';
            return text.includes(term) || href.includes(term);
        });

        if (!filtered.length) {
            resultsContainer.innerHTML = '<div class="sidebar-command-empty">No matching links found.</div>';
            return;
        }

        // Render matched links into command list (re-using matching layout styles)
        filtered.forEach((link, idx) => {
            const text = link.querySelector('.sidebar-label')?.textContent || '';
            const href = link.getAttribute('href') || '';
            const iconTag = link.querySelector('.sidebar-icon')?.cloneNode(true);
            const iconHtml = iconTag ? iconTag.outerHTML : '<i data-lucide="link-2"></i>';
            const groupHeader = link.closest('.sidebar-group')?.querySelector('.sidebar-group-header')?.textContent.trim() || 'Links';

            const item = document.createElement('a');
            item.className = `sidebar-command-item ${idx === 0 ? 'selected' : ''}`;
            item.setAttribute('href', href);
            
            // Respect SPA transitions (use native AJAX interception) unless target _blank
            if (link.getAttribute('target') === '_blank') {
                item.setAttribute('target', '_blank');
                item.setAttribute('data-ajax-ignore', 'true');
            }

            item.innerHTML = `
                ${iconHtml}
                <span class="sidebar-command-item-label">${text}</span>
                <span class="sidebar-command-item-category">${groupHeader}</span>
            `;

            // Close dialog overlay on link click
            item.addEventListener('click', () => {
                const cmdPalette = document.getElementById('sidebarCommandPalette');
                if (cmdPalette) cmdPalette.classList.remove('visible');
            });

            resultsContainer.appendChild(item);
        });

        // Initialize Lucide icons on newly generated result cards
        if (window.lucide) {
            window.lucide.createIcons({
                attrs: {
                    class: 'sidebar-icon'
                }
            });
        }
    }

    // Auto boot sidebar on initial page ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }

    // Re-initialize sidebar bindings and Lucide icons after AJAX SPA page swaps
    document.addEventListener('admin:content-swapped', () => {
        initSidebar();
    });

})();
