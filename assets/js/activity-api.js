(() => {
    'use strict';
    const table = document.querySelector('#activityLogsTable, #dashboardActivityTable');
    if (!table) return;
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
    const endpoint = table.dataset.endpoint;
    const pager = window.SagipbroPagination.create({
        table,
        endpoint,
        autoLoad: false,
        render: (result) => {
            if (table.id === 'dashboardActivityTable') {
                table.tBodies[0].innerHTML = result.data.map((record) => `<tr>
                    <td><div class="activity-cell"><span class="activity-icon"><i class="bi bi-activity" aria-hidden="true"></i></span><span><span class="table-primary-text">${escapeHtml(record.event)} ${escapeHtml(record.module.toLowerCase())}</span><span class="table-secondary-text">Database activity recorded</span></span></div></td>
                    <td>${escapeHtml(record.user)}</td><td><span class="status-badge status-info">${escapeHtml(record.module)}</span></td><td>${escapeHtml(record.date)} ${escapeHtml(record.time)}</td>
                </tr>`).join('');
                return;
            }
            table.tBodies[0].innerHTML = result.data.map((record) => `<tr id="activity-log-${record.id}" data-row data-activity-id="${record.id}">
                <td class="position-relative"><span class="activity-log-target" id="activity-log-target-${record.id}" aria-hidden="true"></span><div class="activity-cell"><span class="activity-icon ${record.tone === 'warning' ? 'warning' : ''}"><i class="bi bi-activity" aria-hidden="true"></i></span><span><span class="table-primary-text">${escapeHtml(record.event)}</span><span class="table-secondary-text">${escapeHtml(record.reference)}</span></span></div></td>
                <td><span class="table-avatar" aria-hidden="true">${escapeHtml(record.initials)}</span><span class="table-primary-text d-inline">${escapeHtml(record.user)}</span></td>
                <td><span class="status-badge status-${escapeHtml(record.tone)}">${escapeHtml(record.module)}</span></td>
                <td>${escapeHtml(record.details)}</td><td><code class="small text-body-secondary">${escapeHtml(record.source)}</code></td>
                <td><span class="table-primary-text">${escapeHtml(record.date)}</span><span class="table-secondary-text">${escapeHtml(record.time)}</span></td>
                <td class="text-end"><button class="btn btn-light btn-icon" type="button" title="Review event" aria-label="Review ${escapeHtml(record.reference)}" data-record-json="${escapeHtml(JSON.stringify(record))}" data-activity-review data-bs-toggle="modal" data-bs-target="#activityDetailModal"><i class="bi bi-eye" aria-hidden="true"></i></button></td>
            </tr>`).join('');
        },
    });
    const start = async () => {
        const target = new URLSearchParams(window.location.search).get('view');
        if (target && /^\d+$/.test(target)) {
            const lookup = new URL(endpoint, window.location.href);
            lookup.searchParams.set('id', target);
            lookup.searchParams.set('per_page', String(pager.state.perPage));
            const response = await fetch(lookup, { headers: { Accept: 'application/json' } });
            if (response.ok) pager.state.page = Number((await response.json()).record_page) || 1;
        }
        await pager.load();
    };
    start().catch((error) => window.sagipbroToast?.(error.message, 'Unable to load activity'));
})();
