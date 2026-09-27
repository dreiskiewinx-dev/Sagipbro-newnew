(() => {
    'use strict';

    const config = window.sagipbroAnnouncementApi;
    const tableBody = document.querySelector('#announcementsTable tbody');
    const addForm = document.getElementById('addAnnouncementForm');
    const editForm = document.getElementById('editAnnouncementForm');
    const deleteForm = document.getElementById('deleteAnnouncementForm');
    const publishedCount = document.querySelector('[data-announcement-published]');
    const summary = document.querySelector('[data-announcement-summary]');
    if (!config || !tableBody || !addForm || !editForm || !deleteForm) return;

    let announcements = [];
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
    const statusClass = (status) => status === 'Published' ? 'status-success' : (status === 'Draft' ? 'status-warning' : 'status-neutral');
    const render = () => {
        const published = announcements.filter((announcement) => announcement.status === 'Published').length;
        if (publishedCount) publishedCount.textContent = `${published} currently published`;
        if (summary) summary.textContent = `Showing ${announcements.length} of ${announcements.length} announcements`;
        const resultCount = document.querySelector('[data-filter-results]');
        if (resultCount) resultCount.textContent = `${announcements.length} announcements`;
        if (summary) summary.textContent = `Showing ${announcements.length ? 1 : 0}–${announcements.length} of ${announcements.length} announcements`;
        tableBody.innerHTML = announcements.map((announcement) => {
            const sourceDate = announcement.published_at || announcement.created_at;
            const date = sourceDate ? new Date(sourceDate.replace(' ', 'T')) : null;
            return `<tr data-row data-category="${escapeHtml(announcement.category)}" data-status="${escapeHtml(announcement.status)}" data-audience="${escapeHtml(announcement.audience)}" data-search="${escapeHtml(`${announcement.title} ${announcement.content} ${announcement.author}`)}">
                <td style="min-width: 280px; max-width: 420px;"><span class="table-primary-text">${escapeHtml(announcement.title)}</span><span class="table-secondary-text text-truncate" style="max-width: 390px;">${escapeHtml(announcement.content)}</span><span class="table-secondary-text">ANN-${String(announcement.id).padStart(4, '0')}</span></td>
                <td><span class="d-inline-flex align-items-center gap-2"><i class="bi bi-megaphone-fill text-success" aria-hidden="true"></i>${escapeHtml(announcement.category)}</span></td>
                <td>${escapeHtml(announcement.audience)}</td>
                <td><span class="status-badge ${statusClass(announcement.status)}">${escapeHtml(announcement.status)}</span></td>
                <td><span class="table-primary-text">${date ? escapeHtml(date.toLocaleDateString('en-PH')) : '-'}</span><span class="table-secondary-text">${date ? escapeHtml(date.toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' })) : ''}</span></td>
                <td>${escapeHtml(announcement.author)}</td>
                <td class="text-end"><div class="table-actions" role="group" aria-label="Actions for ${escapeHtml(announcement.title)}"><button class="btn btn-light btn-icon" type="button" title="View" data-record-json="${escapeHtml(JSON.stringify(announcement))}" data-bs-toggle="modal" data-bs-target="#viewAnnouncementModal"><i class="bi bi-eye"></i></button><button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit ${escapeHtml(announcement.title)}" data-announcement-edit="${announcement.id}" data-bs-toggle="modal" data-bs-target="#editAnnouncementModal"><i class="bi bi-pencil"></i></button><button class="btn btn-light btn-icon text-danger" type="button" title="Archive" aria-label="Archive ${escapeHtml(announcement.title)}" data-announcement-delete="${announcement.id}" data-bs-toggle="modal" data-bs-target="#deleteAnnouncementModal"><i class="bi bi-archive"></i></button></div></td>
            </tr>`;
        }).join('');
        tableBody.querySelectorAll('[data-announcement-edit]').forEach((button) => button.addEventListener('click', () => fillEdit(Number(button.dataset.announcementEdit))));
        tableBody.querySelectorAll('[data-announcement-delete]').forEach((button) => button.addEventListener('click', () => { deleteForm.dataset.id = button.dataset.announcementDelete; }));
    };
    const fillEdit = (id) => {
        const announcement = announcements.find((item) => Number(item.id) === id);
        if (!announcement) return;
        editForm.dataset.id = id;
        editForm.elements.title.value = announcement.title;
        editForm.elements.content.value = announcement.content;
        editForm.elements.category.value = announcement.category;
        editForm.elements.audience.value = announcement.audience;
        editForm.elements.status.value = announcement.status;
        editForm.elements.published_at.value = announcement.published_at ? announcement.published_at.replace(' ', 'T').slice(0, 16) : '';
    };
    const submit = async (form, method, body) => {
        const button = form.querySelector('[type="submit"]');
        if (button) button.disabled = true;
        try {
            await request(method, body);
            await load();
            window.bootstrap?.Modal.getOrCreateInstance(form.closest('.modal')).hide();
            window.sagipbroToast?.('Announcement saved to the database.', 'Action complete');
            form.reset();
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Request failed');
        } finally {
            if (button) button.disabled = false;
        }
    };
    document.addEventListener('sagipbro:record-viewed', (event) => {
        if (event.detail.modal.id === 'viewAnnouncementModal') fillEdit(Number(event.detail.record.id));
    });
    addForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!addForm.reportValidity()) return;
        const data = Object.fromEntries(new FormData(addForm).entries());
        const status = event.submitter?.value === 'publish' ? 'Published' : (event.submitter?.value === 'draft' ? 'Draft' : data.status);
        submit(addForm, 'POST', { title: data.title, body: data.content, category: data.category, audience: data.audience, status, published_at: data.published_at });
    });
    editForm.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!editForm.reportValidity()) return;
        const data = Object.fromEntries(new FormData(editForm).entries());
        submit(editForm, 'PUT', { id: editForm.dataset.id, title: data.title, body: data.content, category: data.category, audience: data.audience, status: data.status, published_at: data.published_at });
    });
    deleteForm.addEventListener('submit', (event) => {
        event.preventDefault();
        submit(deleteForm, 'DELETE', { id: deleteForm.dataset.id });
    });
    const load = async () => {
        try {
            announcements = (await request()).data || [];
            render();
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Unable to load announcements');
        }
    };
    load();
})();
