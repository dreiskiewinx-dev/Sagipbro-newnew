(() => {
    'use strict';

    document.querySelectorAll('[data-public-print]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    document.querySelectorAll('[data-current-year]').forEach((node) => {
        node.textContent = new Date().getFullYear();
    });

    const toastElement = document.getElementById('appToast');
    const showToast = (message, title = 'SAGIPBRO') => {
        if (!toastElement || !window.bootstrap) return;
        const titleNode = toastElement.querySelector('.toast-header strong');
        const bodyNode = toastElement.querySelector('.toast-body');
        if (titleNode) titleNode.textContent = title;
        if (bodyNode) bodyNode.textContent = message;
        window.bootstrap.Toast.getOrCreateInstance(toastElement, { delay: 3200 }).show();
    };
    window.sagipbroToast = showToast;

    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.dataset.passwordToggle);
            if (!target) return;
            const reveal = target.type === 'password';
            target.type = reveal ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(reveal));
            button.setAttribute('aria-label', reveal ? 'Hide password' : 'Show password');
            const icon = button.querySelector('i');
            if (icon) icon.className = reveal ? 'bi bi-eye-slash' : 'bi bi-eye';
        });
    });

    document.querySelectorAll('form[data-demo-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            event.stopPropagation();
            form.classList.add('was-validated');
            if (!form.checkValidity()) return;

            const submit = form.querySelector('[type="submit"]');
            if (submit) {
                submit.disabled = true;
                const original = submit.innerHTML;
                submit.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Saving...';
                window.setTimeout(() => {
                    submit.disabled = false;
                    submit.innerHTML = original;
                    form.reset();
                    form.classList.remove('was-validated');
                    const modal = form.closest('.modal');
                    if (modal && window.bootstrap) window.bootstrap.Modal.getOrCreateInstance(modal).hide();
                    showToast(form.dataset.successMessage || form.dataset.demoMessage || form.dataset.toastMessage || 'Your information has been saved.', 'Action complete');
                }, 450);
            }
        });
    });

    const resourceSearch = document.querySelector('[data-resource-search]');
    const resourceCategory = document.querySelector('[data-resource-filter]');
    const resourceStatus = document.querySelector('[data-resource-status]');
    const resourceCards = [...document.querySelectorAll('[data-resource-card]')];
    const resourceCount = document.querySelector('[data-resource-count]');
    const noResources = document.querySelector('[data-resource-empty]');

    const filterResources = () => {
        if (!resourceCards.length) return;
        const term = (resourceSearch?.value || '').trim().toLowerCase();
        const category = resourceCategory?.value || 'all';
        const status = resourceStatus?.value || 'all';
        let visible = 0;
        resourceCards.forEach((card) => {
            const matchesTerm = !term || card.textContent.toLowerCase().includes(term);
            const cardCategory = card.dataset.category || card.dataset.resourceCategory || '';
            const cardStatus = card.dataset.status || card.dataset.resourceStatus || '';
            const matchesCategory = category === 'all' || cardCategory === category;
            const matchesStatus = status === 'all' || cardStatus === status;
            const show = matchesTerm && matchesCategory && matchesStatus;
            card.hidden = !show;
            if (show) visible += 1;
        });
        if (resourceCount) resourceCount.textContent = resourceCount.tagName === 'STRONG' ? String(visible) : `${visible} item${visible === 1 ? '' : 's'} shown`;
        if (noResources) noResources.hidden = visible !== 0;
    };

    [resourceSearch, resourceCategory, resourceStatus].forEach((control) => {
        control?.addEventListener(control === resourceSearch ? 'input' : 'change', filterResources);
    });

    document.querySelectorAll('[data-copy-value]').forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.dataset.copyValue || '';
            try {
                await navigator.clipboard.writeText(value);
                showToast('Contact information copied to your clipboard.');
            } catch (_) {
                showToast(value, 'Contact information');
            }
        });
    });

    document.querySelectorAll('[data-scroll-to]').forEach((trigger) => {
        trigger.addEventListener('click', () => {
            const target = document.querySelector(trigger.dataset.scrollTo);
            target?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    const logoutModalElement = document.getElementById('logoutConfirmModal');
    const logoutConfirmButton = logoutModalElement?.querySelector('[data-confirm-logout-submit]');
    let pendingLogoutForm = null;

    document.querySelectorAll('form[data-confirm-logout]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!logoutModalElement || !window.bootstrap) {
                if (window.confirm('Are you sure you want to log out?')) form.submit();
                return;
            }
            pendingLogoutForm = form;
            window.bootstrap.Modal.getOrCreateInstance(logoutModalElement).show();
        });
    });

    logoutConfirmButton?.addEventListener('click', () => {
        if (!pendingLogoutForm) return;
        logoutConfirmButton.disabled = true;
        logoutConfirmButton.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> Logging out…';
        pendingLogoutForm.submit();
    });

    logoutModalElement?.addEventListener('hidden.bs.modal', () => {
        pendingLogoutForm = null;
    });

    const presence = window.sagipbroPresence;
    let presenceTimer = null;
    const sendPresence = async () => {
        clearTimeout(presenceTimer);
        if (!presence) return;
        try {
            await fetch(presence.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                keepalive: true,
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': presence.csrfToken,
                },
                body: '{}',
            });
        } catch (_) {
            // Presence is best-effort and will retry while the page remains open.
        } finally {
            presenceTimer = window.setTimeout(sendPresence, 15000);
        }
    };
    if (presence) {
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) sendPresence();
        });
        window.addEventListener('online', sendPresence);
        sendPresence();
    }
})();
