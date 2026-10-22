(() => {
    'use strict';

    const config = window.sagipbroUserApi;
    const tableBody = document.querySelector('#usersTable tbody');
    const addForm = document.getElementById('addUserForm');
    const editForm = document.getElementById('editUserForm');
    const resetForm = document.getElementById('resetPasswordForm');
    const deactivateForm = document.getElementById('deactivateUserForm');
    if (!config || !tableBody || !addForm || !editForm || !resetForm || !deactivateForm) return;

    let users = [];
    let initializing = true;
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
    }[character]));
    const roleLabel = (role) => ({ admin: 'Administrator', official: 'Barangay Official', volunteer: 'Volunteer', resident: 'Resident' }[role] || role);
    const roleValue = (role) => ({ Administrator: 'admin', 'Barangay Official': 'official', Volunteer: 'volunteer', Resident: 'resident' }[role] || role);
    const formatDateTime = (value) => {
        if (!value) return 'Never';
        const date = new Date(String(value).replace(' ', 'T') + '+08:00');
        return Number.isNaN(date.getTime()) ? value : date.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    };
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
    const render = (result) => {
        users = result.data || [];
        tableBody.innerHTML = users.map((user) => `<tr data-row data-role="${escapeHtml(roleLabel(user.role))}" data-status="${escapeHtml(user.status)}" data-presence="${Number(user.is_online) === 1 ? 'Online' : 'Offline'}" data-search="${escapeHtml(`${user.full_name} ${user.username} ${user.email || ''}`)}">
            <td><span class="table-avatar" aria-hidden="true">${escapeHtml((user.full_name || user.username || '?').slice(0, 2).toUpperCase())}</span><span class="d-inline-block align-middle"><span class="table-primary-text">${escapeHtml(user.full_name)}</span><span class="table-secondary-text">USR-${String(user.id).padStart(4, '0')}</span></span></td>
            <td>${escapeHtml(user.username)}</td>
            <td><span class="status-badge status-info">${escapeHtml(roleLabel(user.role))}</span></td>
            <td>${escapeHtml(user.email || 'Not recorded')}</td>
            <td>${escapeHtml(formatDateTime(user.last_login_at))}</td>
            <td><span class="status-badge ${Number(user.is_online) === 1 ? 'status-success' : 'status-neutral'}"><i class="bi bi-circle-fill me-1" aria-hidden="true"></i>${Number(user.is_online) === 1 ? 'Online' : 'Offline'}</span></td>
            <td><span class="status-badge ${user.status === 'Active' ? 'status-info' : 'status-danger'}">${user.status === 'Active' ? 'Enabled' : 'Disabled'}</span></td>
            <td class="text-end"><div class="table-actions">
                <button class="btn btn-light btn-icon" type="button" title="View" aria-label="View ${escapeHtml(user.full_name)}" data-record-json="${escapeHtml(JSON.stringify(user))}" data-bs-toggle="modal" data-bs-target="#viewUserModal"><i class="bi bi-eye"></i></button>
                <button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit ${escapeHtml(user.full_name)}" data-user-edit="${user.id}" data-bs-toggle="modal" data-bs-target="#editUserModal"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-light btn-icon" type="button" title="Reset password" aria-label="Reset password for ${escapeHtml(user.full_name)}" data-user-reset="${user.id}" data-bs-toggle="modal" data-bs-target="#resetPasswordModal"><i class="bi bi-key"></i></button>
                <button class="btn btn-light btn-icon text-danger" type="button" title="Deactivate" aria-label="Deactivate ${escapeHtml(user.full_name)}" data-user-delete="${user.id}" data-bs-toggle="modal" data-bs-target="#deactivateUserModal"><i class="bi bi-person-x"></i></button>
            </div></td>
        </tr>`).join('');
        tableBody.querySelectorAll('[data-user-edit]').forEach((button) => button.addEventListener('click', () => fillEdit(Number(button.dataset.userEdit))));
        tableBody.querySelectorAll('[data-user-reset]').forEach((button) => button.addEventListener('click', () => { resetForm.dataset.id = button.dataset.userReset; }));
        tableBody.querySelectorAll('[data-user-delete]').forEach((button) => button.addEventListener('click', () => { deactivateForm.dataset.id = button.dataset.userDelete; }));

        const values = document.querySelectorAll('.stat-grid .stat-value');
        const stats = result.stats || {};
        if (values[0]) values[0].textContent = stats.total ?? 0;
        if (values[1]) values[1].textContent = stats.privileged ?? 0;
        if (values[2]) values[2].textContent = stats.online ?? 0;
        if (values[3]) values[3].textContent = stats.inactive ?? 0;
    };
    const fillEdit = (id) => {
        const user = users.find((item) => Number(item.id) === id);
        if (!user) return;
        editForm.dataset.id = id;
        editForm.elements.full_name.value = user.full_name || '';
        editForm.elements.email.value = user.email || '';
        editForm.elements.role.value = roleLabel(user.role);
        editForm.elements.status.value = user.status;
    };
    const submit = async (form, method, body, message = 'User account saved to the database.') => {
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;
        try {
            await request(method, body);
            await load();
            window.bootstrap?.Modal.getOrCreateInstance(form.closest('.modal')).hide();
            form.reset();
            window.sagipbroToast?.(message, 'Action complete');
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Request failed');
        } finally {
            if (button) button.disabled = false;
        }
    };

    addForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!addForm.reportValidity()) return;
        const data = Object.fromEntries(new FormData(addForm).entries());
        if (data.password !== data.confirm_password) {
            window.sagipbroToast?.('Passwords do not match.', 'Request failed');
            return;
        }
        submit(addForm, 'POST', {
            full_name: data.full_name, email: data.email, username: data.username, password: data.password,
            role: roleValue(data.role), force_password_change: data.force_password_change === 'on',
        });
    });
    editForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!editForm.reportValidity()) return;
        const data = Object.fromEntries(new FormData(editForm).entries());
        submit(editForm, 'PUT', { id: editForm.dataset.id, full_name: data.full_name, email: data.email, role: roleValue(data.role), status: data.status });
    });
    resetForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!resetForm.reportValidity()) return;
        submit(resetForm, 'PUT', { id: resetForm.dataset.id, action: 'reset_password', password: resetForm.elements.temporary_password.value }, 'Temporary password issued.');
    });
    deactivateForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submit(deactivateForm, 'DELETE', { id: deactivateForm.dataset.id }, 'User account deactivated.');
    });
    const pager = window.SagipbroPagination.create({ table: '#usersTable', endpoint: config.endpoint, render, autoLoad: false });
    const load = () => pager.load();
    const initialize = async () => {
        const requestedId = new URLSearchParams(window.location.search).get('view');
        if (requestedId && /^\d+$/.test(requestedId)) {
            try {
                const url = new URL(config.endpoint, window.location.href);
                url.searchParams.set('id', requestedId);
                url.searchParams.set('per_page', String(pager.state.perPage));
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                const result = await response.json();
                if (response.ok && result.record_page) pager.state.page = result.record_page;
            } catch (_) { /* The normal request below reports connection failures. */ }
        }
        await load();
    };
    initialize().catch((error) => window.sagipbroToast?.(error.message, 'Unable to load users')).finally(() => { initializing = false; });
    window.setInterval(() => {
        if (!document.hidden && !initializing && !pager.state.loading) pager.load({ background: true }).catch((error) => window.sagipbroToast?.(error.message, 'Unable to load users'));
    }, 5000);
})();
