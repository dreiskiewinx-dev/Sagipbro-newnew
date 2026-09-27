<?php
$basePath = $basePath ?? '../../';
$activeAdmin = $activeAdmin ?? 'dashboard';
$sidebarRole = $_SESSION['role'] ?? 'admin';
$dashboardFile = $sidebarRole === 'volunteer' ? 'dashboard/volunteer.php' : 'dashboard/admin.php';
$announcementCount = 0;
try {
    require_once __DIR__ . '/../config/connection.php';
    $announcementCount = (int) sagipbroDatabase()->query("SELECT COUNT(*) FROM announcements WHERE status = 'Published'")->fetchColumn();
} catch (Throwable $e) {
    error_log('Sidebar announcement count unavailable (' . get_class($e) . ').');
}
$sidebarGroups = [
    'Overview' => [
        ['dashboard', 'Dashboard', 'bi-grid-1x2-fill', $dashboardFile],
    ],
    'Operations' => [
        ['resources', 'Resources', 'bi-box-seam-fill', 'pages/resources/index.php'],
        ['evacuation', 'Evacuation centers', 'bi-buildings-fill', 'pages/evacuation/index.php'],
        ['distributions', 'Distributions', 'bi-truck', 'pages/distribution/index.php'],
    ],
    'People' => [
        ['residents', 'Residents', 'bi-people-fill', 'pages/residents/index.php'],
        ['volunteers', 'Volunteers', 'bi-person-hearts', 'pages/volunteers/index.php'],
    ],
    'Communication' => [
        ['announcements', 'Announcements', 'bi-megaphone-fill', 'pages/announcements/index.php'],
        ['messages', 'Messages', 'bi-envelope-fill', 'pages/messages/index.php'],
        ['reports', 'Reports', 'bi-bar-chart-fill', 'pages/reports/index.php'],
    ],
    'Administration' => [
        ['users', 'Users', 'bi-person-gear', 'pages/users/index.php'],
        ['activity', 'Activity logs', 'bi-clock-history', 'pages/activity/index.php'],
    ],
];
if ($sidebarRole === 'volunteer') {
    $sidebarGroups = [
        'Overview' => $sidebarGroups['Overview'],
        'Operations' => [
            ['distributions', 'Distributions', 'bi-truck', 'pages/distribution/index.php'],
        ],
    ];
}
?>
<aside class="admin-sidebar" id="adminSidebar" data-sidebar-role="<?= htmlspecialchars($sidebarRole, ENT_QUOTES, 'UTF-8') ?>" aria-label="Administration navigation">
    <div class="sidebar-brand">
        <a class="brand-lockup" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>dashboard/admin.php">
            <img src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/images/sagipbro-mark.svg" alt="" width="40" height="45">
            <span><strong>SAGIPBRO</strong><small>Admin console</small></span>
        </a>
        <button class="icon-button sidebar-close d-lg-none" type="button" aria-label="Close navigation"><i class="bi bi-x-lg"></i></button>
    </div>
    <nav class="sidebar-nav" style="visibility: hidden">
        <?php foreach ($sidebarGroups as $group => $items): ?>
            <p class="sidebar-label"><?= htmlspecialchars($group, ENT_QUOTES, 'UTF-8') ?></p>
            <ul>
                <?php foreach ($items as [$key, $label, $icon, $href]): ?>
                    <li><a class="sidebar-link<?= $activeAdmin === $key ? ' active' : '' ?>" <?= $activeAdmin === $key ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars($basePath . $href, ENT_QUOTES, 'UTF-8') ?>"><i class="bi <?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i><span><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span><?php if ($key === 'announcements' && $announcementCount > 0): ?><span class="sidebar-count"><?= $announcementCount ?></span><?php endif; ?></a></li>
                <?php endforeach; ?>
            </ul>
        <?php endforeach; ?>
    </nav>
    <div class="sidebar-footer">
        <a class="sidebar-link<?= $activeAdmin === 'profile' ? ' active' : '' ?>" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>pages/profile/index.php"><i class="bi bi-person-circle"></i><span>Profile</span></a>
        <div class="system-status"><span class="status-pulse" aria-hidden="true"></span><span><strong>System operational</strong><small>Last sync: just now</small></span></div>
    </div>
</aside>
<script>
(() => {
    const sidebar = document.getElementById('adminSidebar');
    const navigation = sidebar?.querySelector('.sidebar-nav');
    if (!sidebar || !navigation) return;

    const storageKey = `sagipbro:sidebar-scroll:${sidebar.dataset.sidebarRole || 'default'}`;
    try {
        const storedPosition = window.sessionStorage.getItem(storageKey);
        if (storedPosition !== null) {
            const position = Number.parseInt(storedPosition, 10);
            if (Number.isFinite(position) && position >= 0) {
                navigation.scrollTop = position;
                navigation.dataset.scrollRestored = 'true';
            }
        }
    } catch (error) {
        // Leave the sidebar at its natural position when storage is unavailable.
    }
    navigation.style.removeProperty('visibility');

    let navigationPending = false;
    let unlockTimer = null;
    const unlockNavigation = () => {
        navigationPending = false;
        sidebar.classList.remove('sidebar-navigation-pending');
        sidebar.querySelectorAll('a[href]').forEach((link) => {
            link.classList.remove('sidebar-link-pending');
            link.removeAttribute('aria-disabled');
        });
        if (unlockTimer !== null) {
            window.clearTimeout(unlockTimer);
            unlockTimer = null;
        }
    };

    sidebar.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || !sidebar.contains(link) || event.defaultPrevented) return;
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        if (link.target && link.target !== '_self') return;

        try {
            window.sessionStorage.setItem(storageKey, String(Math.round(navigation.scrollTop)));
        } catch (error) {
            // Continue navigation even when browser storage is unavailable.
        }

        const destination = new URL(link.href, window.location.href);
        const current = new URL(window.location.href);
        const isCurrentPage = destination.origin === current.origin
            && destination.pathname === current.pathname
            && destination.search === current.search
            && destination.hash === current.hash;

        if (isCurrentPage || navigationPending) {
            event.preventDefault();
            return;
        }

        navigationPending = true;
        sidebar.classList.add('sidebar-navigation-pending');
        link.classList.add('sidebar-link-pending');
        sidebar.querySelectorAll('a[href]').forEach((item) => item.setAttribute('aria-disabled', 'true'));
        unlockTimer = window.setTimeout(unlockNavigation, 8000);
    });

    window.addEventListener('pageshow', unlockNavigation);
})();
</script>
<noscript><style>#adminSidebar .sidebar-nav { visibility: visible !important; }</style></noscript>
<div class="sidebar-backdrop" aria-hidden="true"></div>
