(() => {
    'use strict';
    const config = window.sagipbroSession;
    const element = document.getElementById('sessionExpiryModal');
    if (!config || !element || !window.bootstrap?.Modal) return;
    const modal = window.bootstrap.Modal.getOrCreateInstance(element);
    const button = element.querySelector('[data-session-continue]');
    const error = element.querySelector('[data-session-error]');
    const message = element.querySelector('#sessionExpiryMessage');
    const countdown = element.querySelector('[data-session-countdown]');
    let timing = config.timing;
    let offset = timing.server_now * 1000 - Date.now();
    let busy = false, checking = false, expired = false, pendingActivity = false;
    let lastSent = Date.now();
    const now = () => (Date.now() + offset) / 1000;
    const warningVisible = () => element.classList.contains('show');
    const expire = () => {
        if (expired) return;
        expired = true;
        const target = new URL(config.loginUrl, window.location.href);
        target.searchParams.set('error', 'Your session expired. Please sign in again.');
        window.location.assign(target.href);
    };
    const apply = (data) => {
        if (!Number.isFinite(data.idle_expires_at) || !Number.isFinite(data.absolute_expires_at) || !Number.isFinite(data.server_now)) throw new Error('Session status is unavailable.');
        timing = data;
        offset = data.server_now * 1000 - Date.now();
        if (data.password_change_required && window.location.pathname !== new URL(config.passwordChangeUrl, window.location.href).pathname) {
            window.location.assign(config.passwordChangeUrl);
        }
    };
    const request = async (method) => {
        const response = await fetch(config.endpoint, {
            method, credentials: 'same-origin', cache: 'no-store',
            headers: { Accept: 'application/json', ...(method === 'POST' ? { 'X-CSRF-Token': config.csrfToken } : {}) },
        });
        if (response.status === 401) { expire(); return false; }
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || 'Session status is unavailable.');
        apply(data);
        return true;
    };
    const touch = async (explicit = false) => {
        if (busy || expired || (!explicit && warningVisible())) return;
        busy = true;
        button.disabled = true;
        error.hidden = true;
        try {
            if (await request('POST')) {
                lastSent = Date.now();
                pendingActivity = false;
                if (timing.absolute_expires_at - now() > 120) modal.hide();
            }
        } catch (_) {
            error.textContent = 'Unable to extend your session. Check your connection and try again.';
            error.hidden = false;
        } finally {
            busy = false;
            button.disabled = timing.absolute_expires_at - now() <= 120;
        }
    };
    const status = async () => {
        if (checking || busy || expired) return;
        checking = true;
        try { await request('GET'); } catch (_) { /* Never extend a deadline after a failed status request. */ }
        finally { checking = false; }
    };
    button.addEventListener('click', () => touch(true));
    for (const name of ['pointerdown', 'pointermove', 'keydown', 'wheel', 'touchstart']) {
        document.addEventListener(name, (event) => {
            if (!event.isTrusted || document.hidden || warningVisible() || expired) return;
            pendingActivity = true;
            if (Date.now() - lastSent >= 60000) touch();
        }, { passive: true });
    }
    const tick = () => {
        const remaining = Math.ceil(Math.min(timing.idle_expires_at, timing.absolute_expires_at) - now());
        if (remaining <= 0) { expire(); return; }
        if (remaining <= 120) {
            const absolute = timing.absolute_expires_at - now() <= 120;
            message.textContent = absolute ? 'Your eight-hour session limit is approaching. Save your work and sign in again.' : 'You have been inactive. Choose Stay signed in to continue working.';
            button.disabled = busy || absolute;
            countdown.textContent = `${Math.floor(remaining / 60)}:${String(remaining % 60).padStart(2, '0')}`;
            modal.show();
        } else if (warningVisible()) modal.hide();
        if (pendingActivity && !document.hidden && Date.now() - lastSent >= 60000) touch();
    };
    document.addEventListener('visibilitychange', () => { if (!document.hidden) { status(); tick(); } });
    window.addEventListener('online', status);
    window.setInterval(tick, 1000);
    window.setInterval(status, 30000);
    tick();
})();
