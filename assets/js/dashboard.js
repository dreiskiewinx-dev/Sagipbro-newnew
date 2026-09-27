(() => {
    'use strict';

    const body = document.body;
    const sidebar = document.getElementById('adminSidebar');
    const openButton = document.querySelector('.sidebar-toggle');
    const closeButton = document.querySelector('.sidebar-close');
    const backdrop = document.querySelector('.sidebar-backdrop');

    const setSidebar = (open) => {
        body.classList.toggle('sidebar-open', open);
        openButton?.setAttribute('aria-expanded', String(open));
        if (open) closeButton?.focus();
    };

    openButton?.addEventListener('click', () => setSidebar(true));
    closeButton?.addEventListener('click', () => setSidebar(false));
    backdrop?.addEventListener('click', () => setSidebar(false));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && body.classList.contains('sidebar-open')) {
            setSidebar(false);
            openButton?.focus();
        }
    });
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 992) setSidebar(false);
    });

    const sidebarNav = sidebar?.querySelector('.sidebar-nav');
    if (sidebarNav) {
        const storageKey = `sagipbro:sidebar-scroll:${sidebar.dataset.sidebarRole || 'default'}`;
        let savedPosition = null;

        try {
            const storedPosition = window.sessionStorage.getItem(storageKey);
            if (storedPosition !== null) {
                const parsedPosition = Number.parseInt(storedPosition, 10);
                if (Number.isFinite(parsedPosition) && parsedPosition >= 0) {
                    savedPosition = parsedPosition;
                    if (sidebarNav.dataset.scrollRestored !== 'true') {
                        sidebarNav.scrollTop = parsedPosition;
                    }
                }
            }
        } catch (error) {
            // Navigation still works when browser storage is unavailable.
        }

        if (savedPosition === null) {
            const activeLink = sidebarNav.querySelector('.sidebar-link.active');
            if (activeLink) {
                const navBounds = sidebarNav.getBoundingClientRect();
                const linkBounds = activeLink.getBoundingClientRect();
                if (linkBounds.top < navBounds.top) {
                    sidebarNav.scrollTop -= navBounds.top - linkBounds.top;
                } else if (linkBounds.bottom > navBounds.bottom) {
                    sidebarNav.scrollTop += linkBounds.bottom - navBounds.bottom;
                }
            }
        }

        let saveFrame = null;
        const saveSidebarPosition = () => {
            try {
                window.sessionStorage.setItem(storageKey, String(Math.round(sidebarNav.scrollTop)));
            } catch (error) {
                // Ignore storage errors without affecting sidebar navigation.
            }
        };
        const scheduleSidebarSave = () => {
            if (saveFrame !== null) return;
            saveFrame = window.requestAnimationFrame(() => {
                saveFrame = null;
                saveSidebarPosition();
            });
        };

        sidebarNav.addEventListener('scroll', scheduleSidebarSave, { passive: true });
        sidebarNav.querySelectorAll('a.sidebar-link').forEach((link) => {
            link.addEventListener('click', saveSidebarPosition);
        });
        window.addEventListener('pagehide', saveSidebarPosition);
    }

    const normalize = (value) => String(value || '').trim().toLowerCase();
    const searchInputs = document.querySelectorAll('[data-table-search]');
    searchInputs.forEach((input) => {
        const selector = input.dataset.tableSearch;
        const table = selector ? document.querySelector(selector) : input.closest('.admin-content')?.querySelector('table');
        if (!table) return;
        const relatedFilters = [
            ...document.querySelectorAll(`[data-filter-table="${selector}"], [data-filter-select="${selector}"]`),
        ];
        const counter = document.querySelector(`[data-table-count="${selector}"]`) || document.querySelector('[data-filter-results]');
        const empty = document.querySelector(`[data-table-empty="${selector}"]`);

        const run = () => {
            const rows = [...table.querySelectorAll('tbody tr[data-row]')];
            const term = normalize(input.value);
            let shown = 0;
            rows.forEach((row) => {
                const searchMatch = !term || normalize(row.dataset.search || row.textContent).includes(term);
                const filterMatch = relatedFilters.every((filter) => {
                    const value = normalize(filter.value);
                    if (!value || value === 'all') return true;
                    const field = filter.dataset.filterField;
                    return field ? normalize(row.dataset[field]) === value : normalize(row.textContent).includes(value);
                });
                const visible = searchMatch && filterMatch;
                row.hidden = !visible;
                shown += visible ? 1 : 0;
            });
            if (counter) counter.textContent = `${shown} of ${rows.length} records`;
            if (empty) empty.hidden = shown !== 0;
        };

        input.addEventListener('input', run);
        relatedFilters.forEach((filter) => filter.addEventListener('change', run));
    });

    const confirmModal = document.getElementById('confirmActionModal');
    if (confirmModal) {
        confirmModal.addEventListener('show.bs.modal', (event) => {
            const trigger = event.relatedTarget;
            const name = trigger?.dataset.recordName || 'this record';
            const action = trigger?.dataset.actionLabel || 'archive';
            const nameNode = confirmModal.querySelector('[data-confirm-name]');
            const actionNode = confirmModal.querySelector('[data-confirm-label]');
            if (nameNode) nameNode.textContent = name;
            if (actionNode) actionNode.textContent = action;
        });
        confirmModal.querySelector('[data-confirm-submit]')?.addEventListener('click', () => {
            window.bootstrap?.Modal.getOrCreateInstance(confirmModal).hide();
            window.sagipbroToast?.('The selected record was updated in this UI preview.', 'Action complete');
        });
    }

    document.querySelectorAll('[data-edit-record]').forEach((button) => {
        button.addEventListener('click', () => {
            const modalSelector = button.dataset.bsTarget;
            const modal = modalSelector ? document.querySelector(modalSelector) : null;
            if (!modal) return;
            const record = button.dataset.recordName || 'Selected record';
            const label = modal.querySelector('[data-editing-name]');
            if (label) label.textContent = record;
        });
    });

    document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));
    document.querySelectorAll('[data-export]').forEach((button) => {
        button.addEventListener('click', () => {
            const source = document.querySelector(button.dataset.export || '');
            const table = source?.matches('table') ? source : source?.querySelector('table');
            const filename = `${button.dataset.exportName || 'sagipbro-report'}.csv`;
            if (table) {
                const rows = [...table.querySelectorAll('tr')].filter((row) => !row.hidden);
                const csv = rows.map((row) => [...row.querySelectorAll('th,td')].map((cell) => `"${cell.innerText.trim().replaceAll('"', '""')}"`).join(',')).join('\r\n');
                const link = document.createElement('a');
                link.href = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(link.href), 500);
                window.sagipbroToast?.(`${filename} has been prepared.`, 'Export ready');
                return;
            }
            window.sagipbroToast?.('This report export is ready for backend data integration.', 'Export prepared');
        });
    });

    document.querySelectorAll('[data-confirm-action]:not([type="submit"])').forEach((button) => {
        button.addEventListener('click', () => window.sagipbroToast?.(button.dataset.confirmAction, 'Action complete'));
    });

    const clock = document.querySelector('[data-live-time]');
    if (clock) {
        const updateClock = () => {
            clock.textContent = new Intl.DateTimeFormat('en-PH', { hour: 'numeric', minute: '2-digit', hour12: true }).format(new Date());
        };
        updateClock();
        window.setInterval(updateClock, 60000);
    }

    const dashboardStats = document.querySelectorAll('[data-dashboard-stat]');
    if (dashboardStats.length) {
        const refreshDashboardStats = async () => {
            try {
                const response = await fetch('../api/reports.php?report=summary', { headers: { Accept: 'application/json' } });
                if (!response.ok) return;
                const result = await response.json();
                const summary = result.data?.[0] || {};
                dashboardStats.forEach((node) => {
                    const key = node.dataset.dashboardStat;
                    if (Object.prototype.hasOwnProperty.call(summary, key)) node.textContent = Number(summary[key] || 0).toLocaleString();
                });
            } catch (error) {
                // Keep the last server-rendered values when the refresh is unavailable.
            }
        };
        window.setInterval(refreshDashboardStats, 30000);
    }
})();
