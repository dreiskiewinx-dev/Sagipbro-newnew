(() => {
    'use strict';

    const instances = new Map();
    const archived = (record) => record.status === 'Archived' || record.status === 'Inactive' || Number(record.is_archived) === 1;
    const action = (label, verb, help, accepts = () => true) => ({ label, verb, help, accepts });
    // Only durable, editable registers belong here. Reports and audit logs stay read-only.
    const modules = {
        resourcesTable: { module: 'resources', noun: 'resource', actions: {
            archive: action('Archive selected', 'Archive', 'Resources leave active inventory, but quantities and distribution history are retained. They can be restored.', (record) => !archived(record)),
            restore: action('Restore selected', 'Restore', 'Resources return to active inventory with their saved quantities and history.', archived),
        } },
        centersTable: { module: 'centers', noun: 'evacuation center', actions: {
            close: action('Close selected', 'Close', 'Centers will be marked Closed. Occupants, capacity, and history are retained; centers can be reopened.', (record) => record.status !== 'Closed'),
            reopen: action('Reopen selected', 'Reopen', 'Centers return to service. Capacity and current occupants are preserved; full centers remain full.', (record) => record.status === 'Closed'),
        } },
        distributionsTable: { module: 'distributions', noun: 'distribution', actions: {
            complete: action('Mark completed', 'Complete', 'Pending records will be marked Completed. This does not release supplies again, change stock, or remove distribution history.', (record) => record.status === 'Pending review'),
        } },
        residentsTable: { module: 'residents', noun: 'resident record', actions: {
            deactivate: action('Deactivate selected', 'Deactivate', 'Records become Inactive and remain available for reference. They can be activated again.', (record) => record.status !== 'Inactive'),
            activate: action('Activate selected', 'Activate', 'Records return to Active status. Existing household details are preserved.', (record) => record.status === 'Inactive'),
        } },
        volunteersTable: { module: 'volunteers', noun: 'volunteer', actions: {
            deactivate: action('Deactivate selected', 'Deactivate', 'Volunteers become Inactive. Their saved assignments and history are retained; they can be activated again. Deployed volunteers must finish deployment first.', (record) => record.status === 'Active'),
            activate: action('Activate selected', 'Activate', 'Volunteers return to Active status. Existing assignments and history are preserved.', (record) => record.status === 'Inactive'),
        } },
        announcementsTable: { module: 'announcements', noun: 'announcement', actions: {
            archive: action('Archive selected', 'Archive', 'Announcements leave the public feed but remain in the register. Archived announcements can be restored as drafts.', (record) => record.status !== 'Archived'),
            restore: action('Restore selected', 'Restore', 'Archived announcements return to Draft status. They will not be published automatically.', (record) => record.status === 'Archived'),
            delete: action('Delete selected', 'Permanently delete', 'This permanently removes the selected announcements from the database and cannot be undone. Their activity history is retained.'),
        } },
        messagesTable: { module: 'messages', noun: 'message', actions: {
            mark_read: action('Mark read', 'Mark as read', 'Messages are retained in the inbox. Their status can be changed again.', (record) => record.status !== 'Read'),
            mark_unread: action('Mark unread', 'Mark as unread', 'Messages are retained in the inbox. Their status can be changed again.', (record) => record.status !== 'Unread'),
            resolve: action('Resolve selected', 'Resolve', 'Messages will be marked Resolved, not deleted. Their contents and contact details remain available.', (record) => record.status !== 'Resolved'),
        } },
        usersTable: { module: 'users', noun: 'user account', actions: {
            deactivate: action('Deactivate selected', 'Deactivate', 'Accounts become Inactive, not deleted, and can be activated again. Your own account and the last active administrator are protected.', (record) => record.status !== 'Inactive'),
            activate: action('Activate selected', 'Activate', 'Accounts return to Active status with their existing roles. Your own account cannot be bulk-managed.', (record) => record.status === 'Inactive'),
        } },
    };
    const element = (tag, className = '', text = '') => {
        const node = document.createElement(tag);
        node.className = className;
        node.textContent = text;
        return node;
    };

    const attach = ({ table, pagination }) => {
        const config = window.sagipbroBulkApi;
        const module = modules[table.id];
        if (!config?.enabled || config.enabled !== true || !module || !table.tHead || !table.tBodies[0]) return null;
        if (instances.has(table)) return instances.get(table);
        const legacy = module.module === 'announcements';
        const prefix = table.id;
        const card = table.closest('.data-card') || table.parentElement;
        const body = table.tBodies[0];
        const selector = `#${table.id}`;
        const search = document.querySelector(`[data-table-search="${selector}"]`);
        const filters = [...document.querySelectorAll(`[data-filter-select="${selector}"], [data-filter-table="${selector}"]`)];
        const selected = new Set();
        const disabledControls = new Map();
        let records = new Map();
        let stale = true;
        let busy = false;
        let pending = null;
        let allowHide = false;

        table.classList.add('admin-bulk-table');
        table.dataset.bulkModule = module.module;
        let selectAll = legacy ? document.getElementById('announcementSelectAll') : null;
        if (!selectAll) {
            const heading = element('th', 'admin-bulk-selection-cell');
            heading.scope = 'col';
            const label = element('label', 'admin-bulk-selection-control');
            selectAll = element('input', 'form-check-input');
            selectAll.type = 'checkbox';
            selectAll.id = `${prefix}SelectAll`;
            label.append(selectAll, element('span', 'visually-hidden', 'Select all eligible records on this page'));
            heading.append(label);
            table.tHead.rows[0].prepend(heading);
        }
        selectAll.dataset.bulkSelectAll = '';
        selectAll.closest('th').classList.add('admin-bulk-selection-cell');
        selectAll.closest('label')?.classList.add('admin-bulk-selection-control');

        let toolbar = legacy ? document.getElementById('announcementBulkToolbar') : null;
        if (!toolbar) {
            toolbar = element('div', 'admin-bulk-toolbar');
            toolbar.id = `${prefix}BulkToolbar`;
            const summary = element('div', 'admin-bulk-summary');
            const count = element('strong');
            count.dataset.bulkCount = '';
            count.setAttribute('role', 'status');
            count.setAttribute('aria-live', 'polite');
            count.setAttribute('aria-atomic', 'true');
            summary.append(count, element('span', '', 'Selection applies to this page only. Choose records with a compatible status.'));
            const actions = element('div', 'admin-bulk-actions');
            actions.setAttribute('role', 'group');
            actions.setAttribute('aria-label', 'Selected record actions');
            for (const [name, definition] of Object.entries(module.actions)) {
                const button = element('button', `btn btn-sm ${name === 'delete' ? 'btn-danger' : 'btn-brand'}`, definition.label);
                button.type = 'button';
                button.dataset.bulkAction = name;
                actions.append(button);
            }
            const clear = element('button', 'btn btn-sm btn-light', 'Clear selection');
            clear.type = 'button';
            clear.dataset.bulkClear = '';
            actions.append(clear);
            toolbar.append(summary, actions);
            (table.closest('.table-responsive') || table).before(toolbar);
        }
        toolbar.classList.add('admin-bulk-toolbar');
        toolbar.dataset.bulkToolbar = '';
        toolbar.hidden = true;
        const count = legacy ? document.getElementById('announcementSelectionCount') : toolbar.querySelector('[data-bulk-count]');
        const clear = legacy ? document.getElementById('announcementClearSelection') : toolbar.querySelector('[data-bulk-clear]');
        count.dataset.bulkCount = '';
        clear.dataset.bulkClear = '';
        toolbar.querySelectorAll('[data-announcement-bulk-action]').forEach((button) => { button.dataset.bulkAction = button.dataset.announcementBulkAction; });
        const actionButtons = [...toolbar.querySelectorAll('[data-bulk-action]')];

        let modal = legacy ? document.getElementById('bulkAnnouncementModal') : null;
        if (!modal) {
            modal = element('div', 'modal fade');
            modal.id = `${prefix}BulkModal`;
            modal.tabIndex = -1;
            modal.setAttribute('aria-hidden', 'true');
            modal.setAttribute('aria-labelledby', `${prefix}BulkTitle`);
            modal.setAttribute('aria-describedby', `${prefix}BulkMessage ${prefix}BulkHelp`);
            modal.innerHTML = `<div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="${prefix}BulkForm"><div class="modal-header"><h2 class="modal-title" id="${prefix}BulkTitle">Confirm record action</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="mb-2" id="${prefix}BulkMessage"></p><p class="small text-muted mb-0" id="${prefix}BulkHelp"></p><div class="alert alert-danger mt-3 mb-0" id="${prefix}BulkError" role="alert" hidden></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" id="${prefix}BulkConfirm" type="submit" disabled>Confirm</button></div></form></div>`;
            document.body.append(modal);
        }
        modal.dataset.bulkModal = '';
        const form = modal.querySelector('form');
        const title = modal.querySelector('.modal-title');
        const message = document.getElementById(legacy ? 'bulkAnnouncementMessage' : `${prefix}BulkMessage`);
        const help = document.getElementById(legacy ? 'bulkAnnouncementHelp' : `${prefix}BulkHelp`);
        const error = document.getElementById(legacy ? 'bulkAnnouncementError' : `${prefix}BulkError`);
        const confirm = modal.querySelector('[type="submit"]');
        form.dataset.bulkForm = '';
        message.dataset.bulkMessage = '';
        help.dataset.bulkHelp = '';
        error.dataset.bulkError = '';
        confirm.dataset.bulkConfirm = '';

        const boxes = () => [...body.querySelectorAll('[data-bulk-select]')];
        const eligibleBoxes = () => boxes().filter((box) => !box.dataset.bulkBlocked);
        const selectionRecords = (ids = [...selected]) => ids.map((id) => records.get(id));
        const available = (name, ids = [...selected]) => !!module.actions[name] && ids.length > 0 && ids.every((id) => records.has(id)) && selectionRecords(ids).every(module.actions[name].accepts);
        const sync = () => {
            const visible = new Set(eligibleBoxes().map((box) => Number(box.dataset.bulkSelect)));
            for (const id of selected) if (!visible.has(id)) selected.delete(id);
            const blocked = stale || busy || table.getAttribute('aria-busy') === 'true';
            boxes().forEach((box) => {
                box.checked = selected.has(Number(box.dataset.bulkSelect));
                box.disabled = blocked || !!box.dataset.bulkBlocked;
                box.closest('tr').classList.toggle('is-selected', box.checked);
            });
            const eligible = eligibleBoxes();
            selectAll.checked = eligible.length > 0 && selected.size === eligible.length;
            selectAll.indeterminate = selected.size > 0 && selected.size < eligible.length;
            selectAll.disabled = blocked || eligible.length === 0;
            toolbar.hidden = selected.size === 0;
            count.textContent = `${selected.size} ${module.noun}${selected.size === 1 ? '' : 's'} selected`;
            actionButtons.forEach((button) => {
                button.hidden = selected.size > 0 && !available(button.dataset.bulkAction);
                button.disabled = blocked || !available(button.dataset.bulkAction);
            });
            clear.disabled = blocked || selected.size === 0;
            confirm.disabled = busy || !pending;
        };
        const closeConfirmation = () => {
            pending = null;
            if (!busy || allowHide) window.bootstrap?.Modal.getInstance(modal)?.hide();
        };
        const clearSelection = () => {
            selected.clear();
            closeConfirmation();
            sync();
        };
        const invalidate = () => { stale = true; clearSelection(); };
        const disableControls = () => {
            const controls = [search, ...filters, ...card.querySelectorAll('button, input, select'), ...modal.querySelectorAll('button')];
            controls.filter(Boolean).forEach((control) => {
                if (!disabledControls.has(control)) disabledControls.set(control, control.disabled);
                control.disabled = true;
            });
        };
        const setBusy = (value) => {
            busy = value;
            if (busy) disableControls();
            else {
                disabledControls.forEach((disabled, control) => { control.disabled = disabled; });
                disabledControls.clear();
            }
            form.setAttribute('aria-busy', String(busy));
            sync();
        };
        const open = (name, ids = [...selected]) => {
            const frozenIds = [...new Set(ids.map(Number))];
            if (busy || stale || table.getAttribute('aria-busy') === 'true' || !window.bootstrap?.Modal
                || modal.classList.contains('show') || window.bootstrap.Modal.getInstance(modal)?._isTransitioning
                || !frozenIds.length || frozenIds.length > 100 || frozenIds.length !== ids.length
                || frozenIds.some((id) => !Number.isSafeInteger(id) || id < 1 || (module.module === 'users' && id === Number(config.currentUserId)))
                || !available(name, frozenIds)) return false;
            pending = Object.freeze({ action: name, ids: Object.freeze(frozenIds) });
            allowHide = false;
            const definition = module.actions[name];
            const names = `${frozenIds.length} ${module.noun}${frozenIds.length === 1 ? '' : 's'}`;
            title.textContent = `${definition.verb} ${module.noun}${frozenIds.length === 1 ? '' : 's'}?`;
            message.textContent = `${definition.verb} ${names}${name === 'restore' && legacy ? ' as draft' : ''}?`;
            help.textContent = definition.help;
            confirm.textContent = `${definition.verb} ${names}`;
            confirm.classList.toggle('btn-danger', name === 'delete');
            confirm.classList.toggle('btn-brand', name !== 'delete');
            error.textContent = '';
            error.hidden = true;
            sync();
            window.bootstrap.Modal.getOrCreateInstance(modal).show();
            return true;
        };
        const render = (result) => {
            clearSelection();
            records = new Map();
            const data = Array.isArray(result.data) ? result.data : [];
            const rows = [...body.rows].filter((row) => row.matches('[data-row], [data-message-id]'));
            // Never guess IDs when a renderer's record order or row structure differs.
            const pairs = rows.map((row, index) => {
                let id = Number(row.dataset.messageId);
                if (!row.dataset.messageId) {
                    try { id = Number(JSON.parse(row.querySelector('[data-record-json]')?.dataset.recordJson || 'null')?.id); } catch (_) { id = 0; }
                }
                return { row, record: data[index], id };
            });
            if (rows.length !== data.length || data.length > 100 || pairs.some(({ id, record }) => !Number.isSafeInteger(id) || id < 1 || id !== Number(record?.id)) || new Set(pairs.map((pair) => pair.id)).size !== data.length) {
                stale = true;
                sync();
                return;
            }
            pairs.forEach(({ row, record, id }) => {
                row.querySelector('[data-bulk-selection-cell]')?.remove();
                const cell = element('td', `admin-bulk-selection-cell${legacy ? ' announcement-selection-cell' : ''}`);
                cell.dataset.bulkSelectionCell = '';
                cell.dataset.exportIgnore = 'true';
                cell.setAttribute('data-export-ignore', '');
                const label = element('label', `admin-bulk-selection-control${legacy ? ' announcement-selection-control' : ''}`);
                const box = element('input', 'form-check-input');
                box.type = 'checkbox';
                box.dataset.bulkSelect = String(id);
                if (legacy) box.dataset.announcementSelect = String(id);
                const name = record.title || record.full_name || record.name || record.subject || `${record.first_name || ''} ${record.last_name || ''}`.trim() || `${module.noun} ${id}`;
                const ownAccount = module.module === 'users' && id === Number(config.currentUserId);
                const reason = ownAccount ? 'Your current account cannot be bulk-managed.' : '';
                if (ownAccount) {
                    box.dataset.bulkBlocked = 'self';
                    label.title = reason;
                }
                label.append(box, element('span', 'visually-hidden', `Select ${name}${reason ? `. ${reason}` : ''}`));
                cell.append(label);
                row.prepend(cell);
                row.dataset.bulkRecordId = String(id);
                records.set(id, record);
            });
            if (!data.length && body.rows[0]?.cells.length === 1) body.rows[0].cells[0].colSpan = table.tHead.rows[0].cells.length;
            stale = false;
            sync();
            if (busy) disableControls();
        };

        selectAll.addEventListener('change', () => {
            if (stale || busy || table.getAttribute('aria-busy') === 'true') return sync();
            eligibleBoxes().forEach((box) => {
                const id = Number(box.dataset.bulkSelect);
                if (selectAll.checked) selected.add(id); else selected.delete(id);
            });
            sync();
        });
        body.addEventListener('change', (event) => {
            const box = event.target.closest('[data-bulk-select]');
            if (!box) return;
            if (stale || busy || box.dataset.bulkBlocked || table.getAttribute('aria-busy') === 'true') return sync();
            const id = Number(box.dataset.bulkSelect);
            if (box.checked) selected.add(id); else selected.delete(id);
            sync();
        });
        body.addEventListener('click', (event) => {
            const restore = event.target.closest('[data-announcement-restore]');
            const permanentDelete = event.target.closest('[data-announcement-permanent-delete]');
            if (restore && legacy) open('restore', [Number(restore.dataset.announcementRestore)]);
            if (permanentDelete && legacy) open('delete', [Number(permanentDelete.dataset.announcementPermanentDelete)]);
        });
        actionButtons.forEach((button) => button.addEventListener('click', () => open(button.dataset.bulkAction)));
        clear.addEventListener('click', clearSelection);
        table.tHead.addEventListener('click', (event) => {
            if (event.target.closest('[data-sort], [data-sort-field], [data-table-sort]')) invalidate();
        }, true);
        new MutationObserver(() => { if (busy) disableControls(); }).observe(card, { childList: true, subtree: true });
        modal.addEventListener('hide.bs.modal', (event) => {
            if (busy && !allowHide) event.preventDefault(); else pending = null;
        });
        modal.addEventListener('shown.bs.modal', () => {
            if (!pending && (!busy || allowHide)) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
        });
        modal.addEventListener('hidden.bs.modal', () => { allowHide = false; pending = null; sync(); });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (busy || stale || !pending || table.getAttribute('aria-busy') === 'true') return;
            const operation = pending;
            let committed = false;
            let result;
            error.textContent = '';
            error.hidden = true;
            setBusy(true);
            try {
                const response = await fetch(config.endpoint, {
                    method: 'POST', credentials: 'same-origin', cache: 'no-store',
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-Token': config.csrfToken },
                    body: JSON.stringify({ module: module.module, action: operation.action, ids: operation.ids }),
                });
                result = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const failedIds = Array.isArray(result.failed_ids) ? result.failed_ids.filter((id) => Number.isSafeInteger(Number(id)) && Number(id) > 0).map(Number) : [];
                    const reason = result.error || 'Unable to update selected records. Please try again.';
                    throw new Error(`${reason}${failedIds.length ? ` Blocked record IDs: ${failedIds.join(', ')}.` : ''}${result.rolled_back === true ? ' No selected records changed.' : ''}`);
                }
                committed = true;
                allowHide = true;
                closeConfirmation();
                await pagination.load();
                const counts = Number.isSafeInteger(Number(result.affected_count)) && Number.isSafeInteger(Number(result.unchanged_count))
                    ? ` ${Number(result.affected_count)} changed; ${Number(result.unchanged_count)} already up to date.` : '';
                window.sagipbroToast?.(`${result.message || 'Selected records updated.'}${counts}`, 'Action complete');
            } catch (failure) {
                if (committed) window.sagipbroToast?.(`${result.message || 'Selected records updated.'} The list could not be refreshed. Please reload this page.`, 'Refresh needed');
                else {
                    error.textContent = failure.message || 'Unable to update selected records. Please try again.';
                    error.hidden = false;
                }
            } finally { setBusy(false); }
        });
        const instance = { render, invalidate, sync, open,
            get hasSelection() { return selected.size > 0; },
            get busy() { return busy; },
            get confirming() { return !!pending || modal.classList.contains('show'); },
        };
        instances.set(table, instance);
        sync();
        return instance;
    };
    window.SagipbroBulk = { attach, get: (table) => instances.get(typeof table === 'string' ? document.querySelector(table) : table) };
})();
