(() => {
    'use strict';
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-record-json]');
        if (!trigger) return;
        const target = document.querySelector(trigger.dataset.bsTarget || '');
        if (!target) return;
        let record;
        try { record = JSON.parse(trigger.dataset.recordJson); } catch (_) { return; }
        document.dispatchEvent(new CustomEvent('sagipbro:record-viewed', { detail: { record, modal: target } }));
        const title = target.querySelector('.modal-title');
        const body = target.querySelector('.modal-body');
        if (title) title.textContent = record.title || record.name || record.full_name || record.resource_name || 'Record details';
        if (!body) return;
        const rows = Object.entries(record).filter(([key]) => !['password_hash'].includes(key));
        body.innerHTML = `<dl class="row small mb-0">${rows.map(([key, value]) => {
            const displayValue = value === null || value === undefined || value === '' ? 'Not recorded' : value;
            return `<dt class="col-sm-4 text-body-secondary">${escapeHtml(key.replaceAll('_', ' '))}</dt><dd class="col-sm-8">${escapeHtml(displayValue)}</dd>`;
        }).join('')}</dl>`;
    });
})();
