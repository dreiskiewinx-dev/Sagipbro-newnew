(() => {
    'use strict';

    const config = window.sagipbroNotifications;
    const toggle = document.querySelector('[data-notification-toggle]');
    const count = document.querySelector('[data-notification-count]');
    const summary = document.querySelector('[data-notification-summary]');
    const list = document.querySelector('[data-notification-list]');
    const readAll = document.querySelector('[data-notification-read-all]');
    if (!config || !toggle || !count || !summary || !list || !readAll) return;

    let timer = null;
    let busy = false;
    let unreadCount = 0;

    const relativeTime = (value) => {
        const date = new Date(String(value).replace(' ', 'T') + '+08:00');
        if (Number.isNaN(date.getTime())) return value;
        const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
        if (seconds < 60) return 'Just now';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} min ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} hr ago`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)} day${seconds < 172800 ? '' : 's'} ago`;
        return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: date.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
    };

    const request = async (method = 'GET', body = null, keepalive = false) => {
        const options = { method, credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } };
        options.keepalive = keepalive;
        if (body) {
            options.headers['Content-Type'] = 'application/json';
            options.headers['X-CSRF-Token'] = config.csrfToken;
            options.body = JSON.stringify(body);
        }
        const response = await fetch(config.endpoint, options);
        const payload = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(payload.error || 'Unable to load notifications.');
        return payload;
    };

    const updateUnreadDisplay = (unread) => {
        unreadCount = Math.max(0, unread);
        count.hidden = unreadCount === 0;
        count.textContent = unreadCount > 99 ? '99+' : String(unreadCount);
        toggle.setAttribute('aria-label', unreadCount ? `Notifications, ${unreadCount} unread` : 'Notifications, none unread');
        summary.textContent = unreadCount ? `${unreadCount} unread update${unreadCount === 1 ? '' : 's'}` : 'You are all caught up';
        readAll.hidden = unreadCount === 0;
    };

    const emptyState = (icon, message) => {
        const wrapper = document.createElement('div');
        wrapper.className = 'notification-empty';
        const symbol = document.createElement('i');
        symbol.className = `bi ${icon}`;
        symbol.setAttribute('aria-hidden', 'true');
        const text = document.createElement('span');
        text.textContent = message;
        wrapper.append(symbol, text);
        return wrapper;
    };

    const render = (notifications, unread) => {
        updateUnreadDisplay(unread);
        list.replaceChildren();

        if (!notifications.length) {
            list.append(emptyState('bi-bell-slash', 'No notifications yet'));
        } else {
            for (const notification of notifications) {
                const link = document.createElement('a');
                link.className = `notification-item${notification.unread ? ' is-unread' : ''}`;
                link.href = notification.url;
                link.dataset.notificationId = notification.id;

                const icon = document.createElement('span');
                icon.className = 'notification-icon';
                const iconGlyph = document.createElement('i');
                iconGlyph.className = `bi ${notification.icon || 'bi-bell'}`;
                iconGlyph.setAttribute('aria-hidden', 'true');
                icon.append(iconGlyph);

                const copy = document.createElement('span');
                copy.className = 'notification-copy';
                const title = document.createElement('strong');
                title.textContent = notification.title;
                const description = document.createElement('span');
                description.textContent = notification.description;
                const time = document.createElement('time');
                time.dateTime = notification.created_at;
                time.textContent = relativeTime(notification.created_at);
                copy.append(title, description, time);

                link.append(icon, copy);
                if (notification.unread) {
                    const dot = document.createElement('span');
                    dot.className = 'notification-unread-dot';
                    dot.setAttribute('aria-label', 'Unread');
                    link.append(dot);
                }
                list.append(link);
            }
        }
        list.setAttribute('aria-busy', 'false');
    };

    const markItemRead = (link) => {
        if (!link.classList.contains('is-unread')) return false;
        link.classList.remove('is-unread');
        link.querySelector('.notification-unread-dot')?.remove();
        updateUnreadDisplay(unreadCount - 1);
        return true;
    };

    const refresh = async () => {
        if (busy || document.hidden) return;
        busy = true;
        clearTimeout(timer);
        try {
            const payload = await request();
            render(Array.isArray(payload.data) ? payload.data : [], Number(payload.unread || 0));
        } catch (error) {
            summary.textContent = 'Unable to refresh';
            list.replaceChildren(emptyState('bi-exclamation-circle', error.message));
            list.setAttribute('aria-busy', 'false');
        } finally {
            busy = false;
            if (!document.hidden) timer = setTimeout(refresh, 15000);
        }
    };

    readAll.addEventListener('click', async () => {
        readAll.disabled = true;
        try {
            await request('POST', { action: 'mark_all_read' });
            await refresh();
        } catch (error) {
            window.sagipbroToast?.(error.message, 'Notifications');
        } finally {
            readAll.disabled = false;
        }
    });
    list.addEventListener('click', (event) => {
        const link = event.target.closest('[data-notification-id]');
        if (!link || !markItemRead(link)) return;

        const beaconData = new FormData();
        beaconData.set('action', 'mark_read');
        beaconData.set('notification_id', link.dataset.notificationId);
        beaconData.set('csrf_token', config.csrfToken);
        const queued = typeof navigator.sendBeacon === 'function'
            && navigator.sendBeacon(config.endpoint, beaconData);
        if (!queued) {
            request(
                'POST',
                { action: 'mark_read', notification_id: link.dataset.notificationId },
                true
            ).catch(() => {});
        }
    });
    toggle.addEventListener('show.bs.dropdown', refresh);
    toggle.addEventListener('click', refresh);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) clearTimeout(timer);
        else refresh();
    });
    window.addEventListener('online', refresh);
    refresh();
})();
