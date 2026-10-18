(() => {
    'use strict';

    const body = document.body;
    const sidebar = document.getElementById('adminSidebar');
    const openButton = document.querySelector('.sidebar-toggle');
    const closeButton = document.querySelector('.sidebar-close');
    const backdrop = document.querySelector('.sidebar-backdrop');

    // Keep admin dialogs at the same DOM level as Bootstrap's body-level
    // backdrop so forms remain interactive in every browser and viewport.
    document.querySelectorAll('.admin-main .modal').forEach((modal) => {
        const dialog = modal.querySelector('.modal-dialog');
        if (dialog) {
            dialog.classList.add('modal-dialog-centered', 'modal-dialog-scrollable', 'modal-fullscreen-sm-down');
        }
        document.body.appendChild(modal);
    });

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

    const normalize = (value) => String(value || '').trim().toLowerCase();
    const paginatedTables = new Map();
    const tableSearchRunners = new Map();
    const paginatedRows = (table) => [...table.tBodies].flatMap((tbody) => [...tbody.rows])
        .filter((row) => row.matches('[data-row], [data-report-history], [data-message-id]'));

    document.querySelectorAll('table[data-pagination]').forEach((table) => {
        if (table.hasAttribute('data-server-pagination')) return;
        const pageSize = Math.max(1, Number(table.dataset.pageSize) || 10);
        const card = table.closest('.data-card') || table.parentElement;
        let footer = card.querySelector('.data-card-footer');
        if (!footer) {
            footer = document.createElement('div');
            footer.className = 'data-card-footer record-summary';
            card.appendChild(footer);
        }
        footer.querySelector('nav[aria-label$="pagination"], nav[aria-label*="pages"]')?.remove();
        const nav = document.createElement('nav');
        nav.setAttribute('aria-label', `${table.dataset.pagination} pagination`);
        nav.className = 'data-pagination-nav';
        const list = document.createElement('ul');
        list.className = 'pagination pagination-sm';
        nav.appendChild(list);
        footer.replaceChildren(nav);

        const state = { page: 1, pageSize, table, list, footer, nav };
        paginatedTables.set(table, state);
        const refresh = (reset = false) => {
            const rows = paginatedRows(table);
            const filtered = rows.filter((row) => row.dataset.filterHidden !== 'true');
            const pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
            if (reset) state.page = 1;
            state.page = Math.min(state.page, pageCount);
            rows.forEach((row) => { row.hidden = row.dataset.filterHidden === 'true'; });
            filtered.slice((state.page - 1) * pageSize, state.page * pageSize).forEach((row) => { row.hidden = false; });

            list.replaceChildren();
            const addButton = (label, page, disabled = false, ariaLabel = '') => {
                const item = document.createElement('li');
                item.className = `page-item${disabled ? ' disabled' : ''}`;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'page-link';
                button.innerHTML = label;
                if (ariaLabel) button.setAttribute('aria-label', ariaLabel);
                button.disabled = disabled;
                button.addEventListener('click', () => { state.page = page; refresh(); });
                item.appendChild(button);
                list.appendChild(item);
            };
            addButton('<i class="bi bi-chevron-left" aria-hidden="true"></i>', Math.max(1, state.page - 1), state.page === 1, 'Previous page');
            const pageIndicator = document.createElement('li');
            pageIndicator.className = 'page-item';
            const pageLabel = document.createElement('span');
            pageLabel.className = 'page-link pagination-page-indicator';
            pageLabel.textContent = `Page ${state.page} of ${pageCount}`;
            pageLabel.setAttribute('aria-live', 'polite');
            pageIndicator.appendChild(pageLabel);
            list.appendChild(pageIndicator);
            addButton('<i class="bi bi-chevron-right" aria-hidden="true"></i>', Math.min(pageCount, state.page + 1), state.page === pageCount, 'Next page');
            nav.hidden = pageCount <= 1;
            footer.hidden = pageCount <= 1;
        };
        state.refresh = refresh;
        refresh();
        const observer = new MutationObserver(() => {
            const runSearch = tableSearchRunners.get(table);
            if (runSearch) runSearch();
            else refresh(true);
        });
        table.tBodies.forEach((tbody) => observer.observe(tbody, { childList: true, subtree: true }));
    });

    const searchInputs = document.querySelectorAll('[data-table-search]');
    searchInputs.forEach((input) => {
        const selector = input.dataset.tableSearch;
        const table = selector ? document.querySelector(selector) : input.closest('.admin-content')?.querySelector('table');
        if (!table) return;
        if (table.hasAttribute('data-server-pagination')) return;
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
                row.dataset.filterHidden = String(!visible);
                shown += visible ? 1 : 0;
            });
            if (counter) counter.textContent = `${shown} of ${rows.length} records`;
            if (empty) empty.hidden = shown !== 0;
            paginatedTables.get(table)?.refresh(true);
        };

        tableSearchRunners.set(table, run);
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

    document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => {
        const source = document.querySelector(button.dataset.print || '');
        const table = source?.matches('table') ? source : source?.querySelector('table');
        const pager = table && window.SagipbroPagination?.get(table);
        if (pager) {
            window.open(pager.url({ export: 'print', page: null, per_page: null }), '_blank', 'noopener');
            return;
        }
        window.print();
    }));
    document.querySelectorAll('[data-export]').forEach((button) => {
        button.addEventListener('click', async () => {
            const source = document.querySelector(button.dataset.export || '');
            const table = source?.matches('table') ? source : source?.querySelector('table');
            const filename = `${button.dataset.exportName || 'sagipbro-report'}.csv`;
            const pager = table && window.SagipbroPagination?.get(table);
            if (pager) {
                button.disabled = true;
                try {
                    const response = await fetch(pager.url({ export: 'csv', page: null, per_page: null }), { credentials: 'same-origin', headers: { Accept: 'text/csv' } });
                    if (!response.ok || !response.headers.get('Content-Type')?.includes('text/csv')) throw new Error('Unable to export the matching records.');
                    const objectUrl = URL.createObjectURL(await response.blob());
                    const link = document.createElement('a');
                    link.href = objectUrl;
                    link.download = filename;
                    document.body.appendChild(link);
                    link.click();
                    link.remove();
                    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
                    window.sagipbroToast?.(`${filename} includes all matching records.`, 'Export ready');
                } catch (error) {
                    window.sagipbroToast?.(error.message, 'Export failed');
                } finally {
                    button.disabled = false;
                }
                return;
            }
            if (table) {
                const rows = [...table.querySelectorAll('tr')].filter((row) => !row.hidden);
                const csv = rows.map((row) => [...row.querySelectorAll('th,td')]
                    .filter((cell) => !cell.matches('[data-export-ignore]'))
                    .map((cell) => {
                        let value = cell.innerText.trim().replaceAll('"', '""');
                        // Prevent spreadsheet programs from treating user-entered
                        // recipient/location values as formulas when opening CSV.
                        if (/^[=+\-@]/.test(value)) value = `'${value}`;
                        return `"${value}"`;
                    }).join(',')).join('\r\n');
                const link = document.createElement('a');
                const objectUrl = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
                link.href = objectUrl;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.setTimeout(() => URL.revokeObjectURL(objectUrl), 500);
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
