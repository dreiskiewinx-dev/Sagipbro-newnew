(() => {
    'use strict';

    const card = document.querySelector('[data-floating-announcement]');
    const minimize = document.querySelector('[data-floating-announcement-minimize]');
    const restore = document.querySelector('[data-floating-announcement-restore]');
    if (!card || !minimize || !restore) return;

    const storageKey = 'sagipbro:announcements:minimized';
    let minimized = false;
    const readPreference = () => {
        try { return window.localStorage.getItem(storageKey) === '1'; }
        catch (_) { return minimized; }
    };
    const render = (value, moveFocus = false) => {
        minimized = value;
        document.documentElement.classList.toggle('announcements-minimized', minimized);
        card.hidden = minimized;
        restore.hidden = !minimized;
        restore.setAttribute('aria-expanded', String(!minimized));
        if (moveFocus) (minimized ? restore : minimize).focus({ preventScroll: true });
    };
    const savePreference = (value) => {
        render(value, true);
        try { window.localStorage.setItem(storageKey, value ? '1' : '0'); } catch (_) {}
    };

    minimize.addEventListener('click', () => savePreference(true));
    restore.addEventListener('click', () => savePreference(false));
    render(readPreference());
    // Keep Back/Forward navigation and other open pages in the same state.
    window.addEventListener('pageshow', () => render(readPreference()));
    window.addEventListener('storage', (event) => {
        if (event.key === storageKey || event.key === null) render(readPreference());
    });
})();
