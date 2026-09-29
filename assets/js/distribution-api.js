(() => {
    'use strict';

    const config = window.sagipbroDistributionApi;
    const tableBody = document.querySelector('#distributionsTable tbody');
    const addForm = document.getElementById('addDistributionForm');
    const editForm = document.getElementById('editDistributionForm');
    const deleteForm = document.getElementById('deleteDistributionForm');
    if (!config || !tableBody || !addForm || !editForm || !deleteForm) return;

    let distributions = [];
    let resources = [];
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[character]));
    const request = async (endpoint, method = 'GET', body = null) => {
        const options = { method, headers: { Accept: 'application/json' } };
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = config.csrfToken;
            options.body = JSON.stringify(body);
        }
        const response = await fetch(endpoint, options);
        const result = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(result.error || 'Unable to complete the request.');
        return result;
    };
    const localDateTime = (value) => value ? value.replace(' ', 'T').slice(0, 16) : '';
    const statusClass = (status) => status === 'Completed' ? 'status-success' : 'status-warning';

    const fillResourceOptions = () => {
        document.querySelectorAll('select[name="resource"], select[name="resource_id"]').forEach((select) => {
            const selected = select.value;
            const isAddForm = select.form === addForm;
            if (!resources.length) {
                select.innerHTML = '<option value="">No resources available - add inventory first</option>';
                select.disabled = false;
                return;
            }
            select.disabled = false;
            select.innerHTML = '<option value="">Select resource</option>' + resources.map((resource) => {
                const stock = Number(resource.stock);
                const unavailable = isAddForm && stock < 1 ? ' disabled' : '';
                const stockLabel = stock > 0 ? `${stock.toLocaleString()} ${escapeHtml(resource.unit)}` : 'Out of stock';
                return `<option value="${resource.id}"${unavailable}>${escapeHtml(resource.name)} (${stockLabel})</option>`;
            }).join('');
            select.value = selected;
        });
        syncAddFormState();
    };
    const syncAddFormState = () => {
        const resourceSelect = addForm.elements.resource;
        const quantity = addForm.elements.quantity;
        const submitButton = addForm.querySelector('[data-record-distribution-action]');
        const inventoryAction = addForm.querySelector('[data-add-inventory-action]');
        const resourceHelp = document.getElementById('addDistributionResourceHelp');
        const quantityHelp = document.getElementById('addDistributionQuantityHelp');
        const hasStockedResource = resources.some((resource) => Number(resource.stock) > 0);
        const selectedResource = resources.find((resource) => String(resource.id) === String(resourceSelect?.value || ''));
        const availableStock = selectedResource ? Number(selectedResource.stock) : 0;
        const canEnterQuantity = Boolean(selectedResource && Number.isFinite(availableStock) && availableStock > 0);

        inventoryAction?.classList.toggle('d-none', hasStockedResource);
        submitButton?.classList.toggle('d-none', !hasStockedResource);

        if (resourceHelp) {
            resourceHelp.textContent = !resources.length
                ? 'No inventory records yet. Add a resource to continue.'
                : (!hasStockedResource
                    ? 'All resources are out of stock. Update inventory to continue.'
                    : 'Choose an in-stock resource for this release.');
        }

        if (quantity) {
            quantity.disabled = !canEnterQuantity;
            if (canEnterQuantity) {
                quantity.max = String(availableStock);
            } else {
                quantity.value = '';
                quantity.removeAttribute('max');
            }
        }

        const enteredQuantity = quantity?.value.trim() || '';
        const quantityValue = Number(enteredQuantity);
        const validQuantity = canEnterQuantity
            && enteredQuantity !== ''
            && Number.isInteger(quantityValue)
            && quantityValue >= 1
            && quantityValue <= availableStock;

        if (quantityHelp) {
            if (!resources.length) {
                quantityHelp.textContent = 'Add inventory before entering a quantity.';
            } else if (!hasStockedResource) {
                quantityHelp.textContent = 'Restock a resource before entering a quantity.';
            } else if (!selectedResource) {
                quantityHelp.textContent = 'Select an in-stock resource first.';
            } else if (quantityValue > availableStock) {
                quantityHelp.textContent = `Only ${availableStock.toLocaleString()} ${selectedResource.unit} available.`;
            } else {
                quantityHelp.textContent = `Available stock: ${availableStock.toLocaleString()} ${selectedResource.unit}.`;
            }
        }

        if (submitButton) submitButton.disabled = !validQuantity;
    };
    addForm.elements.resource?.addEventListener('change', syncAddFormState);
    addForm.elements.quantity?.addEventListener('input', syncAddFormState);
    const payload = (form) => {
        const data = Object.fromEntries(new FormData(form).entries());
        return {
            resource_id: data.resource || data.resource_id,
            quantity: data.quantity,
            recipient_name: data.recipient || data.recipient_name,
            recipient_reference: data.recipient_id || data.recipient_reference || '',
            location: data.location || '',
            distributed_at: data.distributed_at || '',
            status: data.status || 'Completed',
            remarks: data.notes || data.remarks || '',
        };
    };
    const render = () => {
        const today = new Date().toISOString().slice(0, 10);
        tableBody.innerHTML = distributions.map((distribution) => {
            const resource = resources.find((item) => Number(item.id) === Number(distribution.resource_id));
            const status = distribution.status || 'Completed';
            const date = distribution.distributed_at || '';
            return `<tr data-row data-category="${escapeHtml(resource?.category || '')}" data-status="${escapeHtml(status)}" data-period="${date.slice(0, 10) === today ? 'today' : 'previous'}" data-search="${escapeHtml(`${distribution.resource_name} ${distribution.recipient_name} ${distribution.recipient_reference || ''} ${distribution.location || ''}`)}">
                <td><span class="table-primary-text">${escapeHtml(distribution.resource_name)}</span><span class="table-secondary-text">DST-${String(distribution.id).padStart(6, '0')}</span></td>
                <td><span class="table-primary-text">${escapeHtml(distribution.recipient_name)}</span><span class="table-secondary-text">${escapeHtml(distribution.recipient_reference || 'No reference')}</span></td>
                <td><strong>${Number(distribution.quantity).toLocaleString()} ${escapeHtml(distribution.resource_unit || '')}</strong></td>
                <td>${escapeHtml(distribution.location || 'Not recorded')}</td>
                <td>${escapeHtml(date)}</td>
                <td>${escapeHtml(distribution.distributed_by_name || '')}</td>
                <td><span class="status-badge ${statusClass(status)}">${escapeHtml(status)}</span></td>
                <td class="text-end" data-export-ignore><div class="table-actions">
                    <button class="btn btn-light btn-icon" type="button" title="View" aria-label="View distribution" data-record-json="${escapeHtml(JSON.stringify(distribution))}" data-bs-toggle="modal" data-bs-target="#viewDistributionModal"><i class="bi bi-eye"></i></button>
                    <button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit distribution" data-distribution-edit="${distribution.id}" data-bs-toggle="modal" data-bs-target="#editDistributionModal"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-light btn-icon text-danger" type="button" title="Reverse" aria-label="Reverse distribution" data-distribution-delete="${distribution.id}" data-bs-toggle="modal" data-bs-target="#deleteDistributionModal"><i class="bi bi-arrow-counterclockwise"></i></button>
                </div></td>
            </tr>`;
        }).join('');

        tableBody.querySelectorAll('[data-distribution-edit]').forEach((button) =>
            button.addEventListener('click', () => fillEdit(Number(button.dataset.distributionEdit))));
        tableBody.querySelectorAll('[data-distribution-delete]').forEach((button) =>
            button.addEventListener('click', () => { deleteForm.dataset.id = button.dataset.distributionDelete; }));
        const pending = distributions.filter((item) => item.status === 'Pending review').length;
        const badge = document.querySelector('#distributionsTable')?.closest('.data-card')?.querySelector('.data-card-header .status-badge');
        const summary = document.querySelector('#distributionsTable')?.closest('.data-card')?.querySelector('.record-summary > span');
        const resultCount = document.querySelector('[data-filter-results]');
        if (badge) badge.textContent = `${pending} awaiting review`;
        if (summary) summary.textContent = `Showing ${distributions.length} of ${distributions.length} distributions`;
        if (resultCount) resultCount.textContent = `${distributions.length} distribution records`;
    };
    const fillEdit = (id) => {
        const distribution = distributions.find((item) => Number(item.id) === id);
        if (!distribution) return;
        editForm.dataset.id = id;
        editForm.elements.resource.value = distribution.resource_id;
        editForm.elements.quantity.value = distribution.quantity;
        editForm.elements.recipient.value = distribution.recipient_name;
        editForm.elements.recipient_id.value = distribution.recipient_reference || '';
        editForm.elements.location.value = distribution.location || '';
        editForm.elements.distributed_at.value = localDateTime(distribution.distributed_at);
        editForm.elements.distributed_by.value = distribution.distributed_by_name || '';
        const status = distribution.status || 'Completed';
        editForm.querySelectorAll('input[name="status"]').forEach((input) => {
            input.checked = input.value === status;
        });
        editForm.elements.notes.value = distribution.remarks || '';
    };
    const submit = async (form, method, body) => {
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;
        try {
            await request(config.endpoint, method, body);
            await load();
            window.bootstrap?.Modal.getOrCreateInstance(form.closest('.modal')).hide();
            form.reset();
            window.sagipbroToast?.('Distribution saved to the database.', 'Action complete');
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Request failed');
        } finally {
            if (form === addForm) syncAddFormState();
            else if (button) button.disabled = false;
        }
    };
    document.addEventListener('sagipbro:record-viewed', (event) => {
        if (event.detail.modal.id === 'viewDistributionModal') fillEdit(Number(event.detail.record.id));
    });

    addForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (addForm.reportValidity()) submit(addForm, 'POST', payload(addForm));
    });
    editForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (editForm.reportValidity()) submit(editForm, 'PUT', { ...payload(editForm), id: editForm.dataset.id });
    });
    deleteForm.addEventListener('submit', (event) => {
        event.preventDefault();
        const data = Object.fromEntries(new FormData(deleteForm).entries());
        submit(deleteForm, 'DELETE', { id: deleteForm.dataset.id, reason: data.reason });
    });
    const load = async () => {
        try {
            const [distributionResult, resourceResult] = await Promise.all([
                request(config.endpoint), request(config.resourcesEndpoint),
            ]);
            distributions = distributionResult.data || [];
            resources = resourceResult.data || [];
            fillResourceOptions();
            render();
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Unable to load distributions');
        }
    };
    load();
})();
