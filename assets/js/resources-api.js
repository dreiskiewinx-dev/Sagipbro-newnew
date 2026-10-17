(() => {
    'use strict';

    const config = window.sagipbroResourceApi;
    const tableBody = document.querySelector('#resourcesTable tbody');
    const addForm = document.getElementById('addResourceForm');
    const editForm = document.getElementById('editResourceForm');
    const deleteForm = document.getElementById('deleteResourceForm');
    const archiveView = document.getElementById('resourceArchiveView');
    let resources = [];

    if (!config || !tableBody) return;

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>'"]/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    }[character]));

    const request = async (method = 'GET', body = null) => {
        const options = { method, headers: { Accept: 'application/json' } };
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = config.csrfToken;
            options.body = JSON.stringify(body);
        }
        const response = await fetch(config.endpoint, options);
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.error || 'Unable to complete the request.');
        return result;
    };

    const statusInfo = (resource) => {
        if (Number(resource.stock) === 0) return ['Out of stock', 'status-danger', 'danger'];
        if (Number(resource.stock) <= Number(resource.low_stock_threshold)) return ['Low stock', 'status-warning', 'warning'];
        return ['In stock', 'status-success', ''];
    };

    const render = () => {
        tableBody.innerHTML = resources.map((resource) => {
            const [status, statusClass, barClass] = statusInfo(resource);
            const percent = Math.min(100, Math.round((Number(resource.stock) / Math.max(1, Number(resource.low_stock_threshold) * 2)) * 100));
            const archived = Number(resource.is_archived) === 1 || resource.is_archived === true;
            const actions = archived
                ? `<button class="btn btn-light btn-icon text-success" type="button" title="Restore" aria-label="Restore ${escapeHtml(resource.name)}" data-resource-restore="${resource.id}"><i class="bi bi-arrow-counterclockwise"></i></button>`
                : `<button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit ${escapeHtml(resource.name)}" data-resource-edit="${resource.id}" data-bs-toggle="modal" data-bs-target="#editResourceModal"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-light btn-icon text-danger" type="button" title="Archive" aria-label="Archive ${escapeHtml(resource.name)}" data-resource-delete="${resource.id}" data-bs-toggle="modal" data-bs-target="#deleteResourceModal"><i class="bi bi-archive"></i></button>`;
            return `<tr data-row data-category="${escapeHtml(resource.category)}" data-status="${escapeHtml(status)}">
                <td><span class="table-primary-text">${escapeHtml(resource.name)}</span><span class="table-secondary-text">#${resource.id}</span></td>
                <td>${escapeHtml(resource.category)}</td>
                <td><div class="stock-cell"><div class="stock-cell-top"><strong>${Number(resource.stock).toLocaleString()} ${escapeHtml(resource.unit)}</strong><span>Min. ${Number(resource.low_stock_threshold).toLocaleString()}</span></div><div class="progress" role="progressbar" aria-valuenow="${percent}" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar ${barClass}" style="width: ${percent}%"></div></div></div></td>
                <td><span class="table-primary-text">${escapeHtml(resource.location || 'Not specified')}</span></td>
                <td>${archived ? '<span class="status-badge status-neutral">Archived</span>' : `<span class="status-badge ${statusClass}">${status}</span>`}</td>
                <td>${escapeHtml(resource.updated_at || resource.created_at || '')}</td>
                <td class="text-end"><div class="table-actions" role="group" aria-label="Actions for ${escapeHtml(resource.name)}">
                    <button class="btn btn-light btn-icon" type="button" title="View" aria-label="View ${escapeHtml(resource.name)}" data-record-json="${escapeHtml(JSON.stringify(resource))}" data-bs-toggle="modal" data-bs-target="#viewResourceModal"><i class="bi bi-eye"></i></button>
                    ${actions}
                </div></td>
            </tr>`;
        }).join('');

        tableBody.querySelectorAll('[data-resource-edit]').forEach((button) => {
            button.addEventListener('click', () => fillEditForm(Number(button.dataset.resourceEdit)));
        });
        tableBody.querySelectorAll('[data-resource-delete]').forEach((button) => {
            button.addEventListener('click', () => deleteForm.dataset.resourceId = button.dataset.resourceDelete);
        });
        tableBody.querySelectorAll('[data-resource-restore]').forEach((button) => {
            button.addEventListener('click', async () => {
                button.disabled = true;
                try {
                    await request('PUT', { id: button.dataset.resourceRestore, action: 'restore' });
                    await load();
                    window.sagipbroToast?.('Resource restored to active inventory.', 'Action complete');
                } catch (error) {
                    window.sagipbroToast?.(error.message, 'Request failed');
                    button.disabled = false;
                }
            });
        });
        const attention = resources.filter((resource) => Number(resource.stock) <= Number(resource.low_stock_threshold)).length;
        const badge = document.querySelector('#resourcesTable')?.closest('.data-card')?.querySelector('.data-card-header .status-badge');
        const summary = document.querySelector('#resourcesTable')?.closest('.data-card')?.querySelector('.record-summary > span');
        const resultCount = document.querySelector('[data-filter-results]');
        if (badge) badge.innerHTML = `<i class="bi bi-exclamation-triangle" aria-hidden="true"></i> ${attention} need attention`;
        if (summary) summary.textContent = `Showing ${resources.length} of ${resources.length} resources`;
        if (resultCount) resultCount.textContent = `${resources.length} resource records`;
    };

    const fillEditForm = (id) => {
        const resource = resources.find((item) => Number(item.id) === id);
        if (!resource) return;
        editForm.dataset.resourceId = id;
        ['name', 'category', 'unit', 'location'].forEach((field) => {
            editForm.elements[field].value = resource[field] || '';
        });
        editForm.elements.stock.value = resource.stock;
        editForm.elements.low_stock_threshold.value = resource.low_stock_threshold;
        editForm.elements.notes.value = resource.notes || '';
    };

    const formData = (form) => Object.fromEntries(new FormData(form).entries());
    document.addEventListener('sagipbro:record-viewed', (event) => {
        if (event.detail.modal.id === 'viewResourceModal') {
            const archived = Number(event.detail.record.is_archived) === 1 || event.detail.record.is_archived === true;
            const editButton = document.querySelector('[data-edit-resource-from-view]');
            if (editButton) editButton.hidden = archived;
            if (!archived) fillEditForm(Number(event.detail.record.id));
        }
    });
    const closeModal = (form) => window.bootstrap?.Modal.getOrCreateInstance(form.closest('.modal')).hide();
    const save = async (form, method, body) => {
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;
        try {
            await request(method, body);
            await load();
            form.reset();
            closeModal(form);
            const message = method === 'DELETE' ? 'Resource archived.' : (method === 'POST' ? 'Resource added.' : 'Resource updated.');
            window.sagipbroToast?.(message, 'Action complete');
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Request failed');
        } finally {
            if (button) button.disabled = false;
        }
    };

    addForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!addForm.reportValidity()) return;
        save(addForm, 'POST', formData(addForm));
    });

    editForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!editForm.reportValidity()) return;
        save(editForm, 'PUT', { ...formData(editForm), id: editForm.dataset.resourceId });
    });

    deleteForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        save(deleteForm, 'DELETE', { id: deleteForm.dataset.resourceId });
    });

    const load = async () => {
        try {
            const view = archiveView?.value === 'archived' ? 'archived' : 'active';
            const result = await fetch(`${config.endpoint}?view=${view}`, { headers: { Accept: 'application/json' } });
            const payload = await result.json().catch(() => ({}));
            if (!result.ok) throw new Error(payload.error || 'Unable to load resources.');
            resources = payload.data || [];
            render();
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Unable to load resources');
        }
    };

    archiveView?.addEventListener('change', load);

    load();
})();
