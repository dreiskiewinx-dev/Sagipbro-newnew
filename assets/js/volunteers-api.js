(() => {
    'use strict';

    const config = window.sagipbroVolunteerApi;
    const tableBody = document.querySelector('#volunteersTable tbody');
    const addForm = document.getElementById('addVolunteerForm');
    const editForm = document.getElementById('editVolunteerForm');
    if (!config || !tableBody || !addForm || !editForm) return;

    let volunteers = [];
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
    const render = () => {
        tableBody.innerHTML = volunteers.map((volunteer) => `<tr data-row data-status="${escapeHtml(volunteer.status)}" data-search="${escapeHtml(`${volunteer.full_name} ${volunteer.skills} ${volunteer.assignment || ''}`)}">
            <td><span class="table-avatar">${escapeHtml(volunteer.full_name.slice(0, 2).toUpperCase())}</span><span class="d-inline-block align-middle"><span class="table-primary-text">${escapeHtml(volunteer.full_name)}</span><span class="table-secondary-text">VOL-${String(volunteer.id).padStart(4, '0')}</span></span></td>
            <td>${escapeHtml(volunteer.skills)}</td><td>${escapeHtml(volunteer.assignment || 'Unassigned')}</td><td>${escapeHtml(volunteer.contact)}</td><td>${escapeHtml(volunteer.availability)}</td>
            <td><span class="status-badge ${volunteer.status === 'Active' ? 'status-success' : (volunteer.status === 'Deployed' ? 'status-info' : 'status-neutral')}">${escapeHtml(volunteer.status)}</span></td>
            <td class="text-end"><div class="table-actions"><button class="btn btn-light btn-icon" type="button" title="View" aria-label="View ${escapeHtml(volunteer.full_name)}" data-record-json="${escapeHtml(JSON.stringify(volunteer))}" data-bs-toggle="modal" data-bs-target="#viewVolunteerModal"><i class="bi bi-eye"></i></button><button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit ${escapeHtml(volunteer.full_name)}" data-volunteer-edit="${volunteer.id}" data-bs-toggle="modal" data-bs-target="#editVolunteerModal"><i class="bi bi-pencil"></i></button><button class="btn btn-light btn-icon text-danger" type="button" title="Deactivate" aria-label="Deactivate ${escapeHtml(volunteer.full_name)}" data-volunteer-delete="${volunteer.id}"><i class="bi bi-person-x"></i></button></div></td>
        </tr>`).join('');
        tableBody.querySelectorAll('[data-volunteer-edit]').forEach((button) => button.addEventListener('click', () => fillEdit(Number(button.dataset.volunteerEdit))));
        tableBody.querySelectorAll('[data-volunteer-delete]').forEach((button) => button.addEventListener('click', () => deactivate(Number(button.dataset.volunteerDelete))));

        const values = document.querySelectorAll('.stat-grid .stat-value');
        const active = volunteers.filter((item) => item.status === 'Active').length;
        const deployed = volunteers.filter((item) => item.status === 'Deployed').length;
        if (values[0]) values[0].textContent = volunteers.length;
        if (values[1]) values[1].textContent = active;
        if (values[2]) values[2].textContent = deployed;
        if (values[3]) values[3].textContent = volunteers.length ? `${Math.round((active + deployed) / volunteers.length * 100)}%` : '0%';
        const resultCount = document.querySelector('.filter-results');
        const summary = document.querySelector('#volunteersTable')?.closest('.data-card')?.querySelector('.record-summary > span');
        if (resultCount) resultCount.textContent = `Showing ${volunteers.length} volunteer records`;
        if (summary) summary.textContent = `Showing ${volunteers.length} of ${volunteers.length} volunteers`;
    };
    const fillEdit = (id) => {
        const volunteer = volunteers.find((item) => Number(item.id) === id);
        if (!volunteer) return;
        editForm.dataset.id = id;
        editForm.dataset.original = JSON.stringify(volunteer);
        editForm.elements.assignment.value = volunteer.assignment || 'Unassigned reserve';
        editForm.elements.availability.value = volunteer.availability;
        editForm.elements.status.value = volunteer.status;
    };
    const submit = async (form, method, body) => {
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;
        try {
            await request(method, body);
            await load();
            window.bootstrap?.Modal.getOrCreateInstance(form.closest('.modal')).hide();
            form.reset();
            window.sagipbroToast?.('Volunteer saved to the database.', 'Action complete');
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Request failed');
        } finally {
            if (button) button.disabled = false;
        }
    };
    document.addEventListener('sagipbro:record-viewed', (event) => {
        if (event.detail.modal.id === 'viewVolunteerModal') fillEdit(Number(event.detail.record.id));
    });
    const deactivate = async (id) => {
        if (!window.confirm('Deactivate this volunteer?')) return;
        try {
            await request('DELETE', { id });
            await load();
            window.sagipbroToast?.('Volunteer deactivated.', 'Action complete');
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Request failed');
        }
    };
    addForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (addForm.reportValidity()) submit(addForm, 'POST', Object.fromEntries(new FormData(addForm).entries()));
    });
    editForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!editForm.reportValidity()) return;
        const original = JSON.parse(editForm.dataset.original || '{}');
        const data = Object.fromEntries(new FormData(editForm).entries());
        submit(editForm, 'PUT', { ...original, ...data, id: editForm.dataset.id });
    });
    const load = async () => {
        try {
            volunteers = (await request()).data || [];
            render();
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Unable to load volunteers');
        }
    };
    load();
})();
