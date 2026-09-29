<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/session.php';

$pageTitle = $pageTitle ?? 'SAGIPBRO';
$pageDescription = $pageDescription ?? 'SAGIPBRO Disaster Relief Resource Information System for Barangay Binloc, Dagupan City.';
$basePath = $basePath ?? '';
$isAdmin = $isAdmin ?? false;
$bodyClass = trim(($bodyClass ?? '') . ($isAdmin ? ' admin-body' : ' public-body'));
$titleSuffix = $pageTitle === 'SAGIPBRO' ? 'Disaster Relief Resource Information System' : 'SAGIPBRO';
?>
<!doctype html>
<html lang="en" class="app-loading<?= $isAdmin ? ' admin-document' : '' ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0b5d3b">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($titleSuffix, ENT_QUOTES, 'UTF-8') ?></title>
    <style>
        html, body { background: <?= $isAdmin ? 'linear-gradient(90deg, #073e2a 0 268px, #f2f6f3 268px)' : '#ffffff' ?>; }
        <?php if ($isAdmin): ?>
        @media (max-width: 991.98px) { html, body { background: #f2f6f3; } }
        <?php endif; ?>
        html.app-loading *, html.app-loading *::before, html.app-loading *::after {
            animation: none !important;
            transition: none !important;
        }
    </style>
    <script>
    (() => {
        const currentUrl = new URL(window.location.href);
        const hasNotificationTarget = currentUrl.searchParams.has('view');
        currentUrl.searchParams.delete('view');
        currentUrl.hash = '';
        const storageKey = `sagipbro:page-scroll:${currentUrl.pathname}${currentUrl.search}`;
        const navigation = performance.getEntriesByType?.('navigation')?.[0];
        const shouldRestore = !hasNotificationTarget && ['reload', 'back_forward'].includes(navigation?.type);
        let savedPosition = null;
        if (shouldRestore) {
            try {
                const value = Number.parseInt(window.sessionStorage.getItem(storageKey) || '', 10);
                if (Number.isFinite(value) && value >= 0) savedPosition = value;
            } catch (_) {}
        }

        if ('scrollRestoration' in window.history) window.history.scrollRestoration = 'manual';

        let userInteracted = false;
        const markInteraction = () => { userInteracted = true; };
        window.addEventListener('wheel', markInteraction, { passive: true, once: true });
        window.addEventListener('touchstart', markInteraction, { passive: true, once: true });
        window.addEventListener('pointerdown', markInteraction, { passive: true, once: true });
        window.addEventListener('keydown', markInteraction, { passive: true, once: true });

        const savePosition = () => {
            try { window.sessionStorage.setItem(storageKey, String(Math.max(0, Math.round(window.scrollY)))); } catch (_) {}
        };
        let saveFrame = null;
        window.addEventListener('scroll', () => {
            if (saveFrame !== null) return;
            saveFrame = window.requestAnimationFrame(() => {
                saveFrame = null;
                savePosition();
            });
        }, { passive: true });
        window.addEventListener('pagehide', savePosition);
        window.addEventListener('beforeunload', savePosition);

        window.sagipbroRestorePagePosition = () => {
            if (savedPosition === null || userInteracted) return;
            const root = document.documentElement;
            const previous = root.style.getPropertyValue('scroll-behavior');
            const priority = root.style.getPropertyPriority('scroll-behavior');
            root.style.setProperty('scroll-behavior', 'auto', 'important');
            window.scrollTo(0, savedPosition);
            if (previous) root.style.setProperty('scroll-behavior', previous, priority);
            else root.style.removeProperty('scroll-behavior');
        };

        // Discard data left by the old refresh-overlay implementation. Native
        // rendering is intentionally used so fresh text is never hidden.
        try {
            for (let index = window.sessionStorage.length - 1; index >= 0; index -= 1) {
                const key = window.sessionStorage.key(index);
                if (key?.startsWith('sagipbro:refresh-snapshot:')) {
                    window.sessionStorage.removeItem(key);
                }
            }
        } catch (_) {}

        // Remove the earlier service-worker refresh experiment immediately so
        // reloads use the server response instead of a stale cached document.
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.getRegistrations().then((registrations) => {
                registrations
                    .filter((registration) => registration.active?.scriptURL.endsWith('/service-worker.js'))
                    .forEach((registration) => registration.unregister());
            }).catch(() => {});
        }
        window.addEventListener('pageshow', (event) => {
            if (!event.persisted) return;
            userInteracted = false;
            window.requestAnimationFrame(() => window.sagipbroRestorePagePosition?.());
        });
    })();
    </script>
    <link rel="icon" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/images/sagipbro-mark.svg" type="image/svg+xml">
    <link rel="preload" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/third-party/bootstrap-icons/fonts/bootstrap-icons.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/third-party/bootstrap/bootstrap.min.css?v=5.3.3">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/third-party/bootstrap-icons/bootstrap-icons.min.css?v=1.11.3">
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/style.css?v=3">
    <?php if ($isAdmin): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/dashboard.css?v=11">
    <?php endif; ?>
    <?php if (!empty($useLoginStyles)): ?>
        <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/login.css">
    <?php endif; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/css/responsive.css?v=2">
    <?php if (!empty($useRecaptcha)): ?><script src="https://www.google.com/recaptcha/api.js" async defer></script><?php endif; ?>
</head>
<body class="<?= htmlspecialchars($bodyClass, ENT_QUOTES, 'UTF-8') ?>">
<a class="skip-link" href="#main-content">Skip to main content</a>
