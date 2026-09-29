(() => {
    'use strict';
    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
    const prepareRecordModal = (trigger) => {
        const target = document.querySelector(trigger.dataset.bsTarget || '');
        if (!target) return null;
        let record;
        try { record = JSON.parse(trigger.dataset.recordJson); } catch (_) { return null; }
        document.dispatchEvent(new CustomEvent('sagipbro:record-viewed', { detail: { record, modal: target } }));
        const title = target.querySelector('.modal-title');
        const body = target.querySelector('.modal-body');
        if (title) title.textContent = record.title || record.name || record.full_name || record.resource_name || record.reference || 'Record details';
        if (body) {
            const rows = Object.entries(record).filter(([key]) => !['password_hash'].includes(key));
            body.innerHTML = `<dl class="row small mb-0">${rows.map(([key, value]) => {
                const displayValue = value === null || value === undefined || value === '' ? 'Not recorded' : value;
                return `<dt class="col-sm-4 text-body-secondary">${escapeHtml(key.replaceAll('_', ' '))}</dt><dd class="col-sm-8">${escapeHtml(displayValue)}</dd>`;
            }).join('')}</dl>`;
        }
        return target;
    };

    document.addEventListener('click', (event) => {
        const trigger = event.target.closest('[data-record-json]');
        if (trigger) prepareRecordModal(trigger);
    });

    const requestedRecordId = new URLSearchParams(window.location.search).get('view');
    if (!requestedRecordId || !/^\d+$/.test(requestedRecordId)) return;

    let openedRequestedRecord = false;
    const consumeRequestedRecord = () => {
        const cleanUrl = new URL(window.location.href);
        cleanUrl.searchParams.delete('view');
        cleanUrl.hash = '';
        window.history.replaceState(null, '', `${cleanUrl.pathname}${cleanUrl.search}${cleanUrl.hash}`);
    };
    const scrollVerticallyTo = (element) => {
        const tableScroller = element.closest('.table-responsive');
        if (tableScroller) tableScroller.scrollLeft = 0;

        const topbarHeight = document.querySelector('.admin-topbar')?.offsetHeight || 0;
        const bounds = element.getBoundingClientRect();
        const usableHeight = Math.max(0, window.innerHeight - topbarHeight);
        const centeredOffset = Math.max(16, (usableHeight - bounds.height) / 2);
        const top = Math.max(0, window.scrollY + bounds.top - topbarHeight - centeredOffset);
        const root = document.documentElement;
        const previousBehavior = root.style.getPropertyValue('scroll-behavior');
        const previousPriority = root.style.getPropertyPriority('scroll-behavior');
        root.style.setProperty('scroll-behavior', 'auto', 'important');
        window.scrollTo(0, top);
        window.requestAnimationFrame(() => {
            if (previousBehavior) root.style.setProperty('scroll-behavior', previousBehavior, previousPriority);
            else root.style.removeProperty('scroll-behavior');
        });
    };
    const highlightTarget = (row) => {
        if (!row) return;
        row.classList.add('notification-target-row');
        window.setTimeout(() => row.classList.remove('notification-target-row'), 1200);
    };

    const revealRequestedRecord = () => {
        if (openedRequestedRecord) return true;

        for (const button of document.querySelectorAll('[data-record-json]')) {
            let record;
            try { record = JSON.parse(button.dataset.recordJson); } catch (_) { continue; }
            if (String(record.id) !== requestedRecordId) continue;

            openedRequestedRecord = true;
            const row = button.closest('tr');
            highlightTarget(row);
            const isActivityTarget = button.matches('[data-activity-review]');
            if (isActivityTarget) {
                const tableScroller = row?.closest('.table-responsive');
                if (tableScroller) tableScroller.scrollLeft = 0;
                if (document.readyState === 'complete') consumeRequestedRecord();
                else window.addEventListener('load', consumeRequestedRecord, { once: true });
            } else {
                consumeRequestedRecord();
                if (row) scrollVerticallyTo(row);
            }

            if (!isActivityTarget) {
                const modal = prepareRecordModal(button);
                if (modal && window.bootstrap?.Modal) {
                    window.bootstrap.Modal.getOrCreateInstance(modal).show();
                }
            }
            return true;
        }

        const messageRow = Array.from(document.querySelectorAll('[data-message-id]'))
            .find((row) => row.dataset.messageId === requestedRecordId);
        if (messageRow) {
            openedRequestedRecord = true;
            consumeRequestedRecord();
            highlightTarget(messageRow);
            scrollVerticallyTo(messageRow);
            return true;
        }
        return false;
    };

    if (!revealRequestedRecord()) {
        const observer = new MutationObserver(() => {
            if (revealRequestedRecord()) observer.disconnect();
        });
        observer.observe(document.body, { childList: true, subtree: true });
        window.setTimeout(() => observer.disconnect(), 10000);
    }
})();
