(() => {
    'use strict';

    const controllers = new Map();
    const create = (options) => {
        const table = typeof options.table === 'string' ? document.querySelector(options.table) : options.table;
        if (!table) throw new Error('Pagination table was not found.');
        if (controllers.has(table)) return controllers.get(table);
        table.dataset.serverPagination = 'true';

        const selector = `#${table.id}`;
        const search = document.querySelector(`[data-table-search="${selector}"]`);
        const filters = [...document.querySelectorAll(`[data-filter-select="${selector}"], [data-filter-table="${selector}"]`)];
        const card = table.closest('.data-card') || table.parentElement;
        let footer = card.querySelector('.data-card-footer');
        if (!footer) {
            footer = document.createElement('div');
            card.appendChild(footer);
        }
        footer.classList.add('data-card-footer', 'record-summary', 'server-pagination-footer');
        footer.hidden = false;
        footer.replaceChildren();

        const summary = document.createElement('span');
        summary.className = 'pagination-summary';
        summary.dataset.paginationSummary = '';
        summary.setAttribute('role', 'status');
        summary.textContent = 'Loading records…';
        const sizeLabel = document.createElement('label');
        sizeLabel.className = 'pagination-size';
        sizeLabel.htmlFor = `${table.id}PageSize`;
        sizeLabel.append(document.createTextNode('Records per page'));
        const size = document.createElement('select');
        size.id = `${table.id}PageSize`;
        size.className = 'form-select form-select-sm';
        [10, 25, 50, 100].forEach((value) => size.add(new Option(String(value), String(value))));
        sizeLabel.append(size);

        const navigation = document.createElement('nav');
        navigation.className = 'data-pagination-nav';
        navigation.setAttribute('aria-label', `${table.dataset.pagination || 'Records'} pagination`);
        const buttons = document.createElement('ul');
        buttons.className = 'pagination pagination-sm';
        navigation.append(buttons);
        footer.append(summary, sizeLabel, navigation);

        const state = { page: 1, perPage: 10, total: 0, totalPages: 1, loading: false };
        let requestNumber = 0;
        let abortController = null;
        let searchTimer = null;
        let controlsSignature = '';

        const url = (overrides = {}) => {
            const result = new URL(options.endpoint, window.location.href);
            result.searchParams.set('paginate', '1');
            result.searchParams.set('page', String(state.page));
            result.searchParams.set('per_page', String(state.perPage));
            if (search?.value.trim()) result.searchParams.set('q', search.value.trim());
            else result.searchParams.delete('q');
            filters.forEach((filter) => {
                const key = filter.dataset.filterField;
                if (!key) return;
                if (filter.value && filter.value.toLowerCase() !== 'all') result.searchParams.set(key, filter.value);
                else result.searchParams.delete(key);
            });
            const extras = typeof options.params === 'function' ? options.params() : options.params;
            Object.entries({ ...extras, ...overrides }).forEach(([key, value]) => {
                if (value === null || value === undefined || value === '') result.searchParams.delete(key);
                else result.searchParams.set(key, String(value));
            });
            return result;
        };

        const reportError = (error) => {
            if (error?.name === 'AbortError') return;
            summary.textContent = 'Unable to load records. Change a filter or use the page controls to retry.';
            window.sagipbroToast?.(error.message || 'Unable to load records.', 'Request failed');
        };
        const navigate = (page) => {
            state.page = page;
            load().catch(reportError);
        };
        const renderControls = () => {
            const signature = `${state.page}:${state.totalPages}`;
            if (signature === controlsSignature) return;
            controlsSignature = signature;
            const focusedLabel = navigation.contains(document.activeElement) ? document.activeElement.getAttribute('aria-label') : null;
            buttons.replaceChildren();
            const addButton = (label, page, disabled, active = false, ariaLabel = label) => {
                const item = document.createElement('li');
                item.className = `page-item${disabled ? ' disabled' : ''}${active ? ' active' : ''}`;
                const button = document.createElement('button');
                button.className = 'page-link';
                button.type = 'button';
                button.textContent = label;
                button.disabled = disabled;
                button.setAttribute('aria-label', ariaLabel);
                if (active) button.setAttribute('aria-current', 'page');
                button.addEventListener('click', () => navigate(page));
                item.append(button);
                buttons.append(item);
            };
            addButton('Previous', Math.max(1, state.page - 1), state.page <= 1);
            const visible = new Set([1, state.totalPages]);
            for (let page = Math.max(1, state.page - 1); page <= Math.min(state.totalPages, state.page + 1); page++) visible.add(page);
            if (state.page <= 3) [2, 3, 4].filter((page) => page <= state.totalPages).forEach((page) => visible.add(page));
            if (state.page >= state.totalPages - 2) [state.totalPages - 3, state.totalPages - 2, state.totalPages - 1].filter((page) => page > 0).forEach((page) => visible.add(page));
            let previous = 0;
            [...visible].sort((a, b) => a - b).forEach((page) => {
                if (previous && page > previous + 1) {
                    const gap = document.createElement('li');
                    gap.className = 'page-item disabled pagination-gap';
                    const text = document.createElement('span');
                    text.className = 'page-link';
                    text.textContent = '…';
                    gap.append(text);
                    buttons.append(gap);
                }
                addButton(String(page), page, false, page === state.page, `Page ${page}`);
                previous = page;
            });
            addButton('Next', Math.min(state.totalPages, state.page + 1), state.page >= state.totalPages);
            if (focusedLabel) {
                [...buttons.querySelectorAll('button')].find((button) => button.getAttribute('aria-label') === focusedLabel && !button.disabled)?.focus({ preventScroll: true });
            }
        };

        const load = async ({ reset = false } = {}) => {
            if (reset) state.page = 1;
            const thisRequest = ++requestNumber;
            abortController?.abort();
            abortController = new AbortController();
            const requestAbort = abortController;
            let timedOut = false;
            const requestTimeout = setTimeout(() => { timedOut = true; requestAbort.abort(); }, 15000);
            state.loading = true;
            table.setAttribute('aria-busy', 'true');
            renderControls();
            try {
                const response = await fetch(url(), { signal: requestAbort.signal, credentials: 'same-origin', headers: { Accept: 'application/json' } });
                const result = await response.json();
                if (!response.ok) throw new Error(result.error || 'Unable to load records.');
                if (thisRequest !== requestNumber) return null;
                if (!result.pagination) throw new Error('The server did not return pagination details.');
                const pagination = result.pagination;
                state.page = Number(pagination.page);
                state.perPage = Number(pagination.per_page);
                state.total = Number(pagination.total);
                state.totalPages = Number(pagination.total_pages);
                await options.render?.(result);
                if (thisRequest !== requestNumber) return null;
                if (!result.data?.length) {
                    const body = table.tBodies[0];
                    if (body && !body.rows.length) {
                        const row = body.insertRow();
                        const cell = row.insertCell();
                        cell.colSpan = table.tHead?.rows[0]?.cells.length || 1;
                        cell.className = 'text-center text-body-secondary py-4';
                        cell.textContent = 'No records match your search or filters.';
                    }
                }
                summary.textContent = `Showing ${Number(pagination.from).toLocaleString()}–${Number(pagination.to).toLocaleString()} of ${state.total.toLocaleString()} records`;
                size.value = String(state.perPage);
                const counter = document.querySelector(`[data-table-count="${selector}"]`) || card.parentElement?.querySelector('[data-filter-results]');
                if (counter) counter.textContent = `${state.total.toLocaleString()} matching records`;
                document.dispatchEvent(new CustomEvent('sagipbro:pagination-loaded', { detail: { table, result } }));
                return result;
            } catch (error) {
                if (timedOut) throw new Error('Loading records timed out. Please try again.');
                if (error.name === 'AbortError') return null;
                throw error;
            } finally {
                clearTimeout(requestTimeout);
                if (thisRequest === requestNumber) {
                    state.loading = false;
                    table.setAttribute('aria-busy', 'false');
                    renderControls();
                    window.sagipbroRestorePagePosition?.();
                }
            }
        };

        search?.addEventListener('input', () => {
            clearTimeout(searchTimer);
            // Invalidate an in-flight page immediately, before the search debounce.
            requestNumber++;
            abortController?.abort();
            searchTimer = setTimeout(() => load({ reset: true }).catch(reportError), 250);
        });
        filters.forEach((filter) => filter.addEventListener('change', () => {
            clearTimeout(searchTimer);
            load({ reset: true }).catch(reportError);
        }));
        size.addEventListener('change', () => {
            state.perPage = Number(size.value);
            load({ reset: true }).catch(reportError);
        });
        const controller = { load, state, url };
        controllers.set(table, controller);
        renderControls();
        if (options.autoLoad !== false) queueMicrotask(() => load().catch(reportError));
        return controller;
    };
    window.SagipbroPagination = { create, get: (table) => controllers.get(typeof table === 'string' ? document.querySelector(table) : table) };
})();
