(() => {
    'use strict';
    const panel = document.querySelector('[data-announcements-panel]');
    if (!panel) return;
    const navbar = document.querySelector('.public-navbar');
    const setOffset = () => panel.style.setProperty('--announcement-nav-height', `${navbar?.getBoundingClientRect().height || 78}px`);
    setOffset();
    if (navbar && window.ResizeObserver) new ResizeObserver(setOffset).observe(navbar);
    else window.addEventListener('resize', setOffset);

    const list = panel.querySelector('[data-announcements-list]');
    const detail = panel.querySelector('[data-announcements-detail]');
    const content = panel.querySelector('[data-announcements-detail-content]');
    const scroller = panel.querySelector('[data-announcements-content]');
    const back = panel.querySelector('[data-announcements-back]');
    if (!list || !detail || !content || !back) return;
    const staticLayout = window.matchMedia('(max-width: 991.98px)');
    let selected = null;
    let scrollPosition = 0;
    let pageScrollPosition = 0;
    let restorePagePosition = false;
    panel.querySelectorAll('[data-announcement-open]').forEach((summary) => {
        summary.setAttribute('aria-expanded', 'false');
        summary.addEventListener('click', (event) => {
            event.preventDefault();
            const source = summary.closest('[data-announcement-id]').querySelector('[data-announcement-full]');
            selected = summary;
            scrollPosition = scroller.scrollTop;
            pageScrollPosition = window.scrollY;
            content.replaceChildren(source.cloneNode(true));
            list.hidden = true;
            detail.hidden = false;
            summary.setAttribute('aria-expanded', 'true');
            scroller.scrollTop = 0;
            content.querySelector('h3')?.focus({ preventScroll: true });
            const navbarBottom = navbar?.getBoundingClientRect().bottom || 0;
            const headingBottom = content.querySelector('h3').getBoundingClientRect().bottom;
            restorePagePosition = staticLayout.matches
                || back.getBoundingClientRect().top < navbarBottom + 8
                || headingBottom > window.innerHeight;
            if (restorePagePosition) panel.scrollIntoView({ block: 'start', behavior: 'auto' });
        });
    });
    back.addEventListener('click', () => {
        detail.hidden = true;
        list.hidden = false;
        content.replaceChildren();
        selected?.setAttribute('aria-expanded', 'false');
        scroller.scrollTop = scrollPosition;
        selected?.focus({ preventScroll: true });
        if (staticLayout.matches || restorePagePosition) window.scrollTo({ top: pageScrollPosition, behavior: 'auto' });
    });
})();
