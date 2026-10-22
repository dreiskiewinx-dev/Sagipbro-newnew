(() => {
    const config = window.sagipbroMessagesApi;
    const inbox = document.querySelector('[data-messages-inbox]');
    if (!inbox || !config || !window.SagipbroPagination) return;
    const status = inbox.querySelector('[data-messages-live-status]');
    const body = inbox.querySelector('tbody');
    const count = inbox.querySelector('[data-message-count]');
    let timer;
    let busy = false;
    let stopped = false;
    let initializing = true;
    let lastData = '';
    const updating = new Set();

    const statusClass = (value) => ({
        Unread: 'status-warning', Read: 'status-info', Resolved: 'status-success'
    }[value] || 'status-neutral');

    const actionButton = (message, nextStatus) => {
        const markRead = nextStatus === 'Read';
        const resolve = nextStatus === 'Resolved';
        const button = document.createElement('button');
        button.type = 'button';
        button.className = `btn btn-light btn-icon${resolve ? ' text-success' : ''}`;
        button.dataset.messageStatus = nextStatus;
        button.disabled = updating.has(Number(message.id));
        button.title = resolve ? 'Resolve' : (markRead ? 'Mark as read' : 'Mark as unread');
        button.setAttribute('aria-label', `${button.title}: ${message.subject}`);
        const icon = document.createElement('i');
        icon.className = `bi ${resolve ? 'bi-check2-circle' : (markRead ? 'bi-envelope-open' : 'bi-envelope')}`;
        icon.setAttribute('aria-hidden', 'true');
        button.append(icon);
        return button;
    };

    function render(result) {
        const messages = result.data || [];
        const unread = Number(result.summary?.unread || 0);
        const total = Number(result.summary?.total || 0);
        count.textContent = `${unread} unread · ${total} total`;
        count.className = `status-badge ${unread ? 'status-warning' : 'status-success'}`;
        const signature = JSON.stringify(messages);
        if (signature === lastData) return;
        const rows = document.createDocumentFragment();
        const element = (tag, text, className = '') => {
            const node = document.createElement(tag);
            node.textContent = text;
            node.className = className;
            return node;
        };
        for (const message of messages) {
            const row = document.createElement('tr');
            row.dataset.messageId = message.id;
            row.classList.toggle('message-row-unread', message.status === 'Unread');
            const sender = document.createElement('td');
            sender.append(element('span', message.name, 'table-primary-text'), element('span', message.email, 'table-secondary-text'));
            row.append(sender);
            for (const value of [message.sitio || 'Not provided', message.phone || 'Not provided', message.subject]) {
                row.append(element('td', value));
            }
            const content = element('td', message.message);
            content.style.maxWidth = '420px';
            content.style.whiteSpace = 'pre-line';
            row.append(content, element('td', message.created_at));
            const state = document.createElement('td');
            state.append(element('span', message.status, `status-badge ${statusClass(message.status)}`));
            row.append(state);
            const actionCell = document.createElement('td');
            actionCell.className = 'text-end';
            const actions = document.createElement('div');
            actions.className = 'table-actions';
            actions.append(actionButton(message, message.status === 'Unread' ? 'Read' : 'Unread'));
            if (message.status !== 'Resolved') actions.append(actionButton(message, 'Resolved'));
            actionCell.append(actions);
            row.append(actionCell);
            rows.append(row);
        }
        if (!messages.length) {
            const row = document.createElement('tr');
            const empty = element('td', 'No messages match your search or filters.', 'text-center text-body-secondary py-4');
            empty.colSpan = 8;
            row.append(empty);
            rows.append(row);
        }
        body.replaceChildren(rows);
        lastData = signature;
    }

    const pagination = window.SagipbroPagination.create({
        table: '#messagesTable', endpoint: config.endpoint, render, autoLoad: false
    });

    async function updateMessage(id, nextStatus, row) {
        if (updating.has(id)) return;
        clearTimeout(timer);
        updating.add(id);
        row.querySelectorAll('[data-message-status]').forEach((button) => { button.disabled = true; });
        try {
            const response = await fetch(config.endpoint, {
                method: 'PUT',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': config.csrfToken,
                },
                body: JSON.stringify({ id, status: nextStatus }),
            });
            const payload = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(payload.error || 'Unable to update the message.');
            lastData = '';
            await pagination.load();
            window.sagipbroToast?.(payload.message || `Message marked ${nextStatus}.`, 'Message updated');
        } catch (error) {
            row.querySelectorAll('[data-message-status]').forEach((button) => { button.disabled = false; });
            window.sagipbroToast?.(error.message, 'Update failed');
        } finally {
            updating.delete(id);
            body.querySelector(`[data-message-id="${id}"]`)?.querySelectorAll('[data-message-status]').forEach((button) => { button.disabled = false; });
            if (!stopped && !document.hidden) timer = setTimeout(refresh, 1000);
        }
    }

    body.addEventListener('click', (event) => {
        const button = event.target.closest('[data-message-status]');
        if (!button) return;
        const row = button.closest('[data-message-id]');
        const id = Number(row?.dataset.messageId);
        if (!row || !Number.isInteger(id) || id < 1) return;
        updateMessage(id, button.dataset.messageStatus, row);
    });

    async function refresh() {
        clearTimeout(timer);
        if (initializing || busy || updating.size || stopped || document.hidden) return;
        if (pagination.state.loading) {
            timer = setTimeout(refresh, 1000);
            return;
        }
        busy = true;
        try {
            await pagination.load({ background: true });
            status.textContent = 'Messages update automatically.';
        } catch (error) {
            if (/Authentication required|not authorized/i.test(error.message)) stopped = true;
            status.textContent = stopped ? error.message : 'Unable to check new messages. Reconnecting automatically…';
        } finally {
            busy = false;
            if (!stopped && !document.hidden) timer = setTimeout(refresh, 1000);
        }
    }
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else refresh();
    });
    window.addEventListener('online', refresh);
    const initialize = async () => {
        const requestedId = new URLSearchParams(window.location.search).get('view');
        if (requestedId && /^\d+$/.test(requestedId)) {
            try {
                const url = new URL(config.endpoint, window.location.href);
                url.searchParams.set('id', requestedId);
                url.searchParams.set('per_page', '10');
                const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });
                const result = await response.json();
                if (response.ok && result.record_page) pagination.state.page = result.record_page;
            } catch (_) { /* The normal polling request reports connection failures. */ }
        }
        initializing = false;
        await refresh();
    };
    initialize();
})();
