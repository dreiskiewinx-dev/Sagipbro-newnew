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
    if (!preview) return;
    const modal = document.getElementById('reportPreviewModal');
    const heading = modal.querySelector('[data-preview-heading]');
    const coverage = modal.querySelector('[data-preview-coverage]');
    const metrics = modal.querySelector('[data-preview-metrics]');
    const status = modal.querySelector('[data-preview-status]');
    const exportButton = modal.querySelector('[data-report-export]');
    const printButton = modal.querySelector('[data-print]');
    const reportTitles = { resources: 'Resource stock', distribution: 'Distribution summary', evacuation: 'Evacuation capacity', residents: 'Resident summary' };
    const numbers = new Set(['stock', 'low_stock_threshold', 'quantity_distributed', 'transactions', 'capacity', 'occupants', 'available_capacity', 'total', 'male', 'female', 'other', 'vulnerable']);
    let activeReport = 'resources';
    let previewRequest = 0;
    const pager = window.SagipbroPagination.create({
        table: preview,
        endpoint: preview.dataset.endpoint,
        params: () => ({ report: activeReport }),
        render: (result) => {
            if (result.report !== activeReport) throw new Error('The selected report could not be loaded. Please try again.');
            const columns = Object.entries(result.columns);
            preview.tHead.innerHTML = `<tr>${columns.map(([, label]) => `<th scope="col">${escapeHtml(label)}</th>`).join('')}</tr>`;
            preview.tBodies[0].innerHTML = result.data.map((record) => `<tr>${columns.map(([key]) => {
                const value = record[key];
                if (key === 'stock_status' || key === 'status') {
                    const tone = { 'In stock': 'success', 'Low stock': 'warning', 'Out of stock': 'danger', Open: 'success', Closed: 'neutral' }[value] || 'neutral';
                    return `<td><span class="status-badge status-${tone}">${escapeHtml(value)}</span></td>`;
                }
                return `<td>${escapeHtml(numbers.has(key) ? Number(value).toLocaleString('en-PH') : value)}</td>`;
            }).join('')}</tr>`).join('');
            metrics.innerHTML = result.metrics.map((metric) => `<div><strong>${escapeHtml(Number(metric.value).toLocaleString('en-PH'))}</strong><span>${escapeHtml(metric.label)}</span></div>`).join('');
            status.hidden = true;
            exportButton.disabled = false;
            printButton.disabled = false;
        },
    });
    modal.addEventListener('show.bs.modal', (event) => {
        const report = event.relatedTarget?.dataset.reportPreview || activeReport;
        if (!Object.hasOwn(reportTitles, report)) return;
        const request = ++previewRequest;
        activeReport = report;
        modal.dataset.report = report;
        const title = reportTitles[report];
        modal.querySelector('.modal-title').textContent = `${title} preview`;
        heading.textContent = title;
        preview.caption.textContent = `${title} report preview`;
        const category = new URL(preview.dataset.endpoint, window.location.href).searchParams.get('coverage');
        coverage.textContent = report === 'resources' || report === 'distribution'
            ? `${category || 'All categories'} within the selected period`
            : `${report === 'evacuation' ? 'Centers' : 'Active residents'} updated within the selected period`;
        const exportUrl = new URL(exportButton.dataset.reportUrl, window.location.href);
        exportUrl.searchParams.set('report', report);
        exportButton.dataset.reportUrl = exportUrl.href;
        exportButton.dataset.reportTitle = title;
        exportButton.disabled = true;
        printButton.disabled = true;
        // Clear the previous report before fetching, including when the next request fails.
        preview.tHead.replaceChildren();
        preview.tBodies[0].innerHTML = '<tr><td class="text-center text-body-secondary py-4">Loading report…</td></tr>';
        metrics.replaceChildren();
        status.textContent = `Loading ${title.toLowerCase()}…`;
        status.hidden = false;
        pager.load({ reset: true }).catch((error) => {
            if (request !== previewRequest) return;
            status.textContent = error.message || 'Unable to load this report. Please try again.';
            status.hidden = false;
            preview.tBodies[0].innerHTML = '<tr><td class="text-center text-body-secondary py-4">This report is temporarily unavailable.</td></tr>';
        });
    });
    document.querySelectorAll('[data-report-print-url]').forEach((button) => {
        button.addEventListener('click', () => window.open(button.dataset.reportPrintUrl, '_blank', 'noopener'));
    });
})();
