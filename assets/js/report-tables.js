(() => {
    'use strict';
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (character) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[character]));
    const history = document.getElementById('recentReportsTable');
    const date = (value) => new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' });
    if (history) {
        window.sagipbroReportHistoryPager = window.SagipbroPagination.create({
            table: history,
            endpoint: history.dataset.endpoint,
            render: (result) => {
                history.tBodies[0].innerHTML = result.data.map((record) => `<tr data-report-history>
                    <td><span class="table-primary-text">${escapeHtml(record.title)}</span><span class="table-secondary-text">${escapeHtml(record.coverage || 'All categories')}</span></td>
                    <td>${escapeHtml(date(record.from))}–${escapeHtml(date(record.to))}</td><td>${escapeHtml(record.generated_by)}</td>
                    <td>${escapeHtml(new Date(record.created_at.replace(' ', 'T')).toLocaleString('en-PH'))}</td>
                    <td><span class="status-badge status-info">${escapeHtml(record.format)}</span></td>
                    <td class="text-end"><a class="btn btn-light btn-icon" href="${escapeHtml(record.download_url)}" title="Download ${escapeHtml(record.title)}" aria-label="Download ${escapeHtml(record.title)}" download><i class="bi bi-download" aria-hidden="true"></i></a></td>
                </tr>`).join('');
                const badge = document.querySelector('[data-report-count]');
                if (badge) badge.textContent = `${Number(result.pagination.total).toLocaleString()} files`;
            },
        });
    }
    const preview = document.getElementById('reportResourcesTable');
    if (preview) window.SagipbroPagination.create({
        table: preview,
        endpoint: preview.dataset.endpoint,
        render: (result) => {
            preview.tBodies[0].innerHTML = result.data.map((record) => `<tr>
                <td>${escapeHtml(record.name)}</td><td>${escapeHtml(record.category)}</td><td>${Number(record.stock).toLocaleString()} ${escapeHtml(record.unit)}</td>
                <td>${Number(record.low_stock_threshold).toLocaleString()}</td><td><span class="status-badge ${record.stock_status === 'In stock' ? 'status-success' : record.stock_status === 'Low stock' ? 'status-warning' : 'status-danger'}">${escapeHtml(record.stock_status)}</span></td>
            </tr>`).join('');
            const count = document.querySelector('[data-report-category-count]');
            if (count) count.textContent = Number(result.stats.categories).toLocaleString();
        },
    });
})();
