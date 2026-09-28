<?php
$basePath = $basePath ?? '../../';
$activeAdmin = $activeAdmin ?? 'dashboard';
$sidebarRole = $_SESSION['role'] ?? 'admin';
$dashboardFile = $sidebarRole === 'volunteer'
    ? 'dashboard/volunteer.php'
    : ($sidebarRole === 'resident' ? 'dashboard/resident.php' : 'dashboard/admin.php');
$consoleLabel = $sidebarRole === 'volunteer'
    ? 'Volunteer console'
    : ($sidebarRole === 'resident' ? 'Resident portal' : 'Admin console');
$announcementCount = 0;
if (in_array($sidebarRole, ['admin', 'official'], true)) {
    try {
        require_once __DIR__ . '/../config/connection.php';
        $announcementCount = (int) sagipbroDatabase()->query("SELECT COUNT(*) FROM announcements WHERE status = 'Published'")->fetchColumn();
    } catch (Throwable $e) {
        error_log('Sidebar announcement count unavailable (' . get_class($e) . ').');
    }
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
if ($sidebarRole === 'official') {
    $sidebarGroups['Administration'] = [
        ['activity', 'Activity logs', 'bi-clock-history', 'pages/activity/index.php'],
    ];
} elseif ($sidebarRole === 'volunteer') {
    $sidebarGroups = [
        'Overview' => $sidebarGroups['Overview'],
        'Operations' => [
            ['distributions', 'Distributions', 'bi-truck', 'pages/distribution/index.php'],
        ],
    ];
} elseif ($sidebarRole === 'resident') {
    $sidebarGroups = [
        'Overview' => $sidebarGroups['Overview'],
    ];
}
?>
<aside class="admin-sidebar" id="adminSidebar" data-sidebar-role="<?= htmlspecialchars($sidebarRole, ENT_QUOTES, 'UTF-8') ?>" aria-label="Application navigation">
    <div class="sidebar-brand">
        <a class="brand-lockup" href="<?= htmlspecialchars($basePath . $dashboardFile, ENT_QUOTES, 'UTF-8') ?>">
            <img src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/images/sagipbro-mark.svg" alt="" width="40" height="45">
            <span><strong>SAGIPBRO</strong><small><?= htmlspecialchars($consoleLabel, ENT_QUOTES, 'UTF-8') ?></small></span>
        </a>
        <button class="icon-button sidebar-close d-lg-none" type="button" aria-label="Close navigation"><i class="bi bi-x-lg"></i></button>
    </div>
    <nav class="sidebar-nav">
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
        const savedValue = window.sessionStorage.getItem(storageKey);
        if (savedValue !== null) {
            const position = Number.parseInt(savedValue, 10);
            if (Number.isFinite(position) && position >= 0) {
                navigation.scrollTop = position;
            }
        }
    } catch (error) {
        // Leave the sidebar at its natural position when storage is unavailable.
    }
    const activeLink = navigation.querySelector('.sidebar-link.active');
    if (activeLink) {
        const navBounds = navigation.getBoundingClientRect();
        const linkBounds = activeLink.getBoundingClientRect();
        if (linkBounds.top < navBounds.top) navigation.scrollTop -= navBounds.top - linkBounds.top;
        if (linkBounds.bottom > navBounds.bottom) navigation.scrollTop += linkBounds.bottom - navBounds.bottom;
    }
    navigation.dataset.scrollRestored = 'true';

    let saveFrame = null;
    const saveSidebarPosition = () => {
        try {
            window.sessionStorage.setItem(storageKey, String(Math.round(navigation.scrollTop)));
        } catch (error) {
            // Sidebar navigation still works when storage is unavailable.
        }
    };
    navigation.addEventListener('scroll', () => {
        if (saveFrame !== null) return;
        saveFrame = window.requestAnimationFrame(() => {
            saveFrame = null;
            saveSidebarPosition();
        });
    }, { passive: true });
    window.addEventListener('pagehide', saveSidebarPosition);
    let navigationPending = false;
    let unlockTimer = null;
    const unlockNavigation = () => {
        navigationPending = false;
        sidebar.classList.remove('sidebar-navigation-pending');
        sidebar.querySelectorAll('a[href]').forEach((link) => {
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

        if (link.closest('.sidebar-brand')) {
            // Match the incoming Dashboard sidebar before navigation begins.
            navigation.scrollTop = 0;
        }
        saveSidebarPosition();

        navigationPending = true;
        sidebar.classList.add('sidebar-navigation-pending');
        sidebar.querySelectorAll('a[href]').forEach((item) => item.setAttribute('aria-disabled', 'true'));
        unlockTimer = window.setTimeout(unlockNavigation, 8000);
    });

    window.addEventListener('pageshow', unlockNavigation);
})();
</script>
<div class="sidebar-backdrop" aria-hidden="true"></div>
