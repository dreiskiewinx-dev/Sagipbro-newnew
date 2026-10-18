(() => {
    'use strict';

    const config = window.sagipbroReports || {};
    const tableBody = document.querySelector('#recentReportsTable tbody');
    const countBadge = document.querySelector('[data-report-count]');

    if (!tableBody || !countBadge) return;


    const responseError = async (response) => {
        try {
            const data = await response.json();
            return data.error || 'The report could not be generated.';
        } catch (_) {
            return 'The report could not be generated.';
        }
    };

    const suggestedFilename = (exportUrl) => {
        const url = new URL(exportUrl, window.location.href);
        const report = url.searchParams.get('report') || 'summary';
        const today = new Date();
        const date = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
        if (report === 'overview') return `sagipbro-report-overview-${date}.csv`;
        if (report === 'custom') return `sagipbro-custom-report-${date}.csv`;
        return `sagipbro-${report}-report-${date}.csv`;
    };

    const waitForDownloadDialog = () => new Promise((resolve) => {
        let lostFocus = false;
        let fallbackTimer;

        const cleanup = () => {
            window.removeEventListener('blur', onBlur);
            window.removeEventListener('focus', onFocus);
            window.clearTimeout(fallbackTimer);
        };
        const finish = () => {
            cleanup();
            window.setTimeout(resolve, 250);
        };
        const onBlur = () => {
            lostFocus = true;
            window.clearTimeout(fallbackTimer);
        };
        const onFocus = () => {
            if (lostFocus) finish();
        };

        window.addEventListener('blur', onBlur);
        window.addEventListener('focus', onFocus);
        fallbackTimer = window.setTimeout(finish, 1500);
    });

    const downloadExport = async (exportUrl) => {
        const filename = suggestedFilename(exportUrl);
        const untrackedUrl = new URL(exportUrl, window.location.href);
        untrackedUrl.searchParams.set('track', '0');
        const response = await fetch(untrackedUrl, {
            credentials: 'same-origin',
            headers: { Accept: 'text/csv' }
        });
        if (!response.ok) throw new Error(await responseError(response));

        const blob = await response.blob();
        const objectUrl = URL.createObjectURL(blob);
        const download = document.createElement('a');
        download.href = objectUrl;
        download.download = filename;
        download.hidden = true;
        document.body.append(download);
        const dialogClosed = waitForDownloadDialog();
        download.click();
        download.remove();
        window.setTimeout(() => URL.revokeObjectURL(objectUrl), 1000);
        await dialogClosed;
        return true;
    };

    const recordSavedExport = async (exportUrl, title) => {
        const url = new URL(exportUrl, window.location.href);
        const response = await fetch(url.pathname, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': config.csrfToken || '',
                Accept: 'application/json'
            },
            body: JSON.stringify({
                action: 'record_export',
                report: url.searchParams.get('report'),
                title,
                from: url.searchParams.get('from'),
                to: url.searchParams.get('to'),
                coverage: url.searchParams.get('coverage') || '',
                sections: url.searchParams.getAll('sections[]')
            })
        });
        if (!response.ok) throw new Error(await responseError(response));
    };

    const runExport = async (exportUrl, title, control, modal = null) => {
        if (control.dataset.exporting === 'true') return;
        control.dataset.exporting = 'true';
        const originalHtml = control.innerHTML;
        control.classList.add('disabled');
        control.setAttribute('aria-disabled', 'true');
        if (control.tagName === 'BUTTON') control.disabled = true;
        control.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Preparing...';

        try {
            const saved = await downloadExport(exportUrl);
            if (!saved) {
                window.sagipbroToast?.('The report was not added because the download was canceled.', 'Download canceled');
                return;
            }
            await recordSavedExport(exportUrl, title);
            await window.sagipbroReportHistoryPager?.load();
            if (modal && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
            window.sagipbroToast?.('The saved report was added to Recent Reports.', 'Report ready');
        } catch (error) {
            window.sagipbroToast?.(error.message || 'The report could not be generated.', 'Export failed');
        } finally {
            delete control.dataset.exporting;
            control.classList.remove('disabled');
            control.removeAttribute('aria-disabled');
            if (control.tagName === 'BUTTON') control.disabled = false;
            control.innerHTML = originalHtml;
        }
    };

    document.querySelectorAll('[data-report-export]').forEach((control) => {
        control.addEventListener('click', () => {
            runExport(control.dataset.reportUrl, control.dataset.reportTitle || 'SAGIPBRO report', control);
        });
    });

    const customReportForm = document.querySelector('[data-report-export-form]');
    const sectionInputs = customReportForm
        ? [...customReportForm.querySelectorAll('input[name="sections[]"]')]
        : [];
    const sectionStateKey = 'sagipbro.custom-report.sections';
    const navigation = performance.getEntriesByType('navigation')[0];

    try {
        if (navigation?.type === 'reload') {
            const savedSections = JSON.parse(sessionStorage.getItem(sectionStateKey) || 'null');
            if (Array.isArray(savedSections)) {
                sectionInputs.forEach((input) => {
                    input.checked = savedSections.includes(input.value);
                });
            }
        } else {
            sessionStorage.removeItem(sectionStateKey);
        }

        sectionInputs.forEach((input) => {
            input.addEventListener('change', () => {
                const checkedSections = sectionInputs.filter((section) => section.checked).map((section) => section.value);
                sessionStorage.setItem(sectionStateKey, JSON.stringify(checkedSections));
            });
        });
    } catch (_) {
        // The form still uses its server-rendered defaults when tab storage is unavailable.
    }

    document.querySelectorAll('[data-report-export-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!form.reportValidity()) return;
            if (!form.querySelector('input[name="sections[]"]:checked')) {
                window.sagipbroToast?.('Select at least one report section.', 'Report required');
                return;
            }

            const params = new URLSearchParams(new FormData(form));
            const exportUrl = `${form.action}?${params.toString()}`;
            const title = params.get('title') || 'Custom operations summary';
            const submit = form.querySelector('[type="submit"]');
            if (submit) runExport(exportUrl, title, submit, form.closest('.modal'));
        });
    });
})();
