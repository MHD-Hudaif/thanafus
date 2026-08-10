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

        // 6. Setup Operations Workspace Trigger
        const openTrigger = document.getElementById('sidebarSearchLauncher');

        if (openTrigger) {
            openTrigger.addEventListener('click', function(e) {
                e.preventDefault();
                if (typeof openCmdPalette === 'function') {
                    openCmdPalette();
                }
            });
        }

        // Global Shortcut CTRL + K or CMD + K
        window.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                e.preventDefault();
                if (typeof openCmdPalette === 'function') {
                    openCmdPalette();
                }
            }
            if (e.key === 'Escape') {
                if (typeof closeCmdPalette === 'function') {
                    closeCmdPalette();
                }
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
