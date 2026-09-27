<?php
$basePath = $basePath ?? '';
$activePage = $activePage ?? '';
$isAdmin = $isAdmin ?? false;

if ($isAdmin):
    $adminName = $_SESSION['username'] ?? $_SESSION['full_name'] ?? 'Admin';
    $adminRole = ucfirst($_SESSION['role'] ?? 'Administrator');
    $notificationArchive = ($_SESSION['role'] ?? '') === 'volunteer'
        ? $basePath . 'pages/distribution/index.php'
        : $basePath . 'pages/activity/index.php';
?>
<header class="admin-topbar">
    <div class="d-flex align-items-center gap-2 gap-lg-3">
        <button class="icon-button sidebar-toggle d-lg-none" type="button" aria-label="Open navigation" aria-controls="adminSidebar" aria-expanded="false">
            <i class="bi bi-list" aria-hidden="true"></i>
        </button>
        <div class="admin-greeting d-none d-md-block">
            <span class="eyebrow">Barangay Binloc operations</span>
            <strong>Good day, <?= htmlspecialchars(explode(' ', trim($adminName))[0] ?: 'Admin', ENT_QUOTES, 'UTF-8') ?></strong>
        </div>
    </div>
    <div class="admin-topbar-actions">
        <div class="dropdown notification-menu">
            <button class="icon-button position-relative" id="notificationToggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-label="Notifications" data-notification-toggle>
                <i class="bi bi-bell" aria-hidden="true"></i>
                <span class="notification-count" data-notification-count hidden>0</span>
            </button>
            <div class="dropdown-menu dropdown-menu-end notification-dropdown shadow border-0" aria-labelledby="notificationToggle">
                <div class="notification-header">
                    <div><strong>Notifications</strong><span data-notification-summary>Checking for updates…</span></div>
                    <button class="btn btn-sm btn-link" type="button" data-notification-read-all hidden>Mark all read</button>
                </div>
                <div class="notification-list" data-notification-list aria-live="polite" aria-busy="true">
                    <div class="notification-empty"><i class="bi bi-arrow-repeat"></i><span>Loading notifications…</span></div>
                </div>
                <a class="notification-footer" href="<?= htmlspecialchars($notificationArchive, ENT_QUOTES, 'UTF-8') ?>">View activity history <i class="bi bi-arrow-right"></i></a>
            </div>
        </div>
        <div class="dropdown">
            <button class="profile-menu" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <span class="avatar" aria-hidden="true"><?= htmlspecialchars(strtoupper(substr($adminName, 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
                <span class="profile-copy d-none d-sm-flex">
                    <strong><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></strong>
                    <small><?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?></small>
                </span>
                <i class="bi bi-chevron-down d-none d-sm-block" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0">
                <li><a class="dropdown-item" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>pages/profile/index.php"><i class="bi bi-person me-2"></i>My profile</a></li>
                <li><a class="dropdown-item" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>index.php"><i class="bi bi-house-door me-2"></i>View public site</a></li>
                <li><hr class="dropdown-divider"></li>
                <li>
                    <form action="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>actions/auth/logout.php" method="post" data-confirm-logout>
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <button class="dropdown-item text-danger" type="submit"><i class="bi bi-box-arrow-left me-2" aria-hidden="true"></i>Logout</button>
                    </form>
                </li>
            </ul>
        </div>
    </div>
</header>
<script>
window.sagipbroNotifications = {
    endpoint: <?= json_encode(appUrl('api/notifications.php')) ?>,
    csrfToken: <?= json_encode(csrfToken()) ?>
};
</script>
<?php else: ?>
<div class="utility-bar">
    <div class="container d-flex justify-content-between align-items-center gap-3">
        <p class="mb-0"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> Barangay Binloc, Dagupan City</p>
        <div class="utility-links">
            <span><i class="bi bi-telephone-fill" aria-hidden="true"></i> CDRRMO: <a href="tel:+639684449598">0968 444 9598</a></span>
            <a class="d-none d-sm-inline" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>contact.php">Report a concern</a>
        </div>
    </div>
</div>
<nav class="navbar navbar-expand-lg public-navbar sticky-top" aria-label="Main navigation">
    <div class="container">
        <a class="navbar-brand brand-lockup" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>index.php" aria-label="SAGIPBRO home">
            <img src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/images/sagipbro-mark.svg" alt="" width="42" height="48">
            <span><strong>SAGIPBRO</strong><small>DISASTER RELIEF INFORMATION SYSTEM</small></span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#publicNavigation" aria-controls="publicNavigation" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="publicNavigation">
            <ul class="navbar-nav ms-auto align-items-lg-center">
                <?php
                $publicLinks = [
                    'home' => ['Home', 'index.php'],
                    'about' => ['About', 'about.php'],
                    'services' => ['Services', 'services.php'],
                    'resources' => ['Resources', 'resources.php'],
                    'contact' => ['Contact', 'contact.php'],
                ];
                foreach ($publicLinks as $key => [$label, $href]):
                ?>
                    <li class="nav-item"><a class="nav-link<?= $activePage === $key ? ' active' : '' ?>" <?= $activePage === $key ? 'aria-current="page"' : '' ?> href="<?= htmlspecialchars($basePath . $href, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?php if (function_exists('isLoggedIn') && isLoggedIn()): ?>
                <?php $portalFile = ($_SESSION['role'] ?? '') === 'resident' ? 'resident.php' : (($_SESSION['role'] ?? '') === 'volunteer' ? 'volunteer.php' : 'admin.php'); ?>
                <a class="btn btn-brand ms-lg-3 mt-3 mt-lg-0" href="<?= htmlspecialchars($basePath . 'dashboard/' . $portalFile, ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-grid" aria-hidden="true"></i> My dashboard</a>
            <?php else: ?>
                <a class="btn btn-brand ms-lg-3 mt-3 mt-lg-0" href="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>login.php"><i class="bi bi-person-lock" aria-hidden="true"></i> Staff login</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<?php endif; ?>
