(() => {
    'use strict';

    const config = window.sagipbroCenterApi;
    const tableBody = document.querySelector('#centersTable tbody');
    const addForm = document.getElementById('addCenterForm');
    const editForm = document.getElementById('editCenterForm');
    const deleteForm = document.getElementById('deleteCenterForm');
    if (!config || !tableBody || !addForm || !editForm || !deleteForm) return;

    let centers = [];
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
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
    const displayStatus = (center) => {
        const percent = Number(center.occupants) / Math.max(1, Number(center.capacity)) * 100;
        if (center.status === 'Closed') return 'Closed';
        if (percent >= 100) return 'Full';
        if (percent >= 85) return 'Near capacity';
        return 'Open';
    };
    const render = () => {
        tableBody.innerHTML = centers.map((center) => {
            const percent = Math.min(100, Math.round(Number(center.occupants) / Math.max(1, Number(center.capacity)) * 100));
            const status = displayStatus(center);
            const statusCss = status === 'Open' ? 'status-success' : (status === 'Full' ? 'status-danger' : (status === 'Near capacity' ? 'status-warning' : 'status-neutral'));
            return `<tr data-row data-status="${escapeHtml(status)}" data-area="Bonuan Binloc" data-search="${escapeHtml(`${center.name} ${center.location} ${center.contact || ''}`)}">
                <td><span class="table-primary-text">${escapeHtml(center.name)}</span><span class="table-secondary-text">EC-${String(center.id).padStart(3, '0')}</span></td>
                <td><i class="bi bi-geo-alt text-success me-1"></i>${escapeHtml(center.location)}</td>
                <td><div class="occupancy-cell"><div class="d-flex justify-content-between gap-2"><strong>${Number(center.occupants).toLocaleString()} / ${Number(center.capacity).toLocaleString()}</strong><span>${percent}%</span></div><div class="progress"><div class="progress-bar ${percent >= 100 ? 'danger' : (percent >= 85 ? 'warning' : '')}" style="width:${percent}%"></div></div><span class="table-secondary-text">${Math.max(0, Number(center.capacity) - Number(center.occupants)).toLocaleString()} spaces available</span></div></td>
                <td><span class="status-badge ${statusCss}">${escapeHtml(status)}</span></td>
                <td><span class="table-primary-text">${escapeHtml(center.contact || 'Not recorded')}</span><span class="table-secondary-text">${escapeHtml(center.phone || '')}</span></td>
                <td>${escapeHtml(center.updated_at || '')}</td>
                <td class="text-end"><div class="table-actions">
                    <button class="btn btn-light btn-icon" type="button" title="View" aria-label="View ${escapeHtml(center.name)}" data-record-json="${escapeHtml(JSON.stringify(center))}" data-bs-toggle="modal" data-bs-target="#viewCenterModal"><i class="bi bi-eye"></i></button>
                    <button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit ${escapeHtml(center.name)}" data-center-edit="${center.id}" data-bs-toggle="modal" data-bs-target="#editCenterModal"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-light btn-icon text-danger" type="button" title="Close" aria-label="Close ${escapeHtml(center.name)}" data-center-delete="${center.id}" data-bs-toggle="modal" data-bs-target="#deleteCenterModal"><i class="bi bi-x-circle"></i></button>
                </div></td>
            </tr>`;
        }).join('');
        tableBody.querySelectorAll('[data-center-edit]').forEach((button) => button.addEventListener('click', () => fillEdit(Number(button.dataset.centerEdit))));
        tableBody.querySelectorAll('[data-center-delete]').forEach((button) => button.addEventListener('click', () => { deleteForm.dataset.id = button.dataset.centerDelete; }));

        const full = centers.filter((center) => displayStatus(center) === 'Full').length;
        const badge = document.querySelector('#centersTable')?.closest('.data-card')?.querySelector('.data-card-header .status-badge');
        const summary = document.querySelector('#centersTable')?.closest('.data-card')?.querySelector('.record-summary > span');
        const resultCount = document.querySelector('[data-filter-results]');
        if (badge) badge.textContent = `${full} centers at full capacity`;
        if (summary) summary.textContent = `Showing ${centers.length} of ${centers.length} centers`;
        if (resultCount) resultCount.textContent = `${centers.length} registered centers`;
    };
    const fillEdit = (id) => {
        const center = centers.find((item) => Number(item.id) === id);
        if (!center) return;
        editForm.dataset.id = id;
        editForm.elements.name.value = center.name || '';
        editForm.elements.location.value = center.location || '';
        editForm.elements.capacity.value = center.capacity;
        editForm.elements.occupants.value = center.occupants;
        editForm.elements.contact_person.value = center.contact || '';
        editForm.elements.contact_number.value = center.phone || '';
        editForm.elements.notes.value = center.notes || '';
        editForm.elements.status.value = center.status;
    };
    const submit = async (form, method, body) => {
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;
        try {
            await request(method, body);
            await load();
            window.bootstrap?.Modal.getOrCreateInstance(form.closest('.modal')).hide();
            form.reset();
            window.sagipbroToast?.('Evacuation center saved to the database.', 'Action complete');
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Request failed');
        } finally {
            if (button) button.disabled = false;
        }
    };
    document.addEventListener('sagipbro:record-viewed', (event) => {
        if (event.detail.modal.id === 'viewCenterModal') fillEdit(Number(event.detail.record.id));
    });
    addForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (addForm.reportValidity()) submit(addForm, 'POST', Object.fromEntries(new FormData(addForm).entries()));
    });
    editForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (editForm.reportValidity()) submit(editForm, 'PUT', { ...Object.fromEntries(new FormData(editForm).entries()), id: editForm.dataset.id });
    });
    deleteForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submit(deleteForm, 'DELETE', { id: deleteForm.dataset.id });
    });
    const load = async () => {
        try {
            centers = (await request()).data || [];
            render();
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Unable to load centers');
        }
    };
    load();
})();
