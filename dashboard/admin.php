<?php
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
requireRole(['admin', 'official']);

$dashboardStats = [
    'resources' => 0,
    'low_stock' => 0,
    'centers' => 0,
    'open_centers' => 0,
    'residents' => 0,
    'volunteers' => 0,
    'distributions' => 0,
    'distributed_quantity' => 0,
];
$recentActivity = [];
$lowStockItems = [];
$recentAnnouncements = [];
$distributionOverview = [];

try {
    $dashboardStats = $conn->query("SELECT
        (SELECT COUNT(*) FROM resources WHERE status <> 'Inactive') AS resources,
        (SELECT COUNT(*) FROM resources WHERE status <> 'Inactive' AND stock <= low_stock_threshold) AS low_stock,
        (SELECT COUNT(*) FROM evacuation_centers) AS centers,
        (SELECT COUNT(*) FROM evacuation_centers WHERE status = 'Open') AS open_centers,
        (SELECT COUNT(*) FROM residents WHERE status = 'Active') AS residents,
        (SELECT COUNT(*) FROM volunteers WHERE status IN ('Active', 'Deployed')) AS volunteers,
        (SELECT COUNT(*) FROM distributions WHERE distributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS distributions,
        (SELECT COALESCE(SUM(quantity), 0) FROM distributions WHERE distributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS distributed_quantity")->fetch();
    $recentActivity = $conn->query("SELECT l.action, l.entity_type AS module, l.details AS description, l.created_at, COALESCE(u.full_name, u.username, 'System') AS actor
        FROM activity_logs l LEFT JOIN users u ON u.id = l.user_id ORDER BY l.created_at DESC LIMIT 5")->fetchAll();
    $lowStockItems = $conn->query("SELECT name AS resource_name, stock AS quantity, low_stock_threshold AS minimum_stock, unit FROM resources
        WHERE status <> 'Inactive' AND stock <= low_stock_threshold ORDER BY stock ASC LIMIT 3")->fetchAll();
    $recentAnnouncements = $conn->query("SELECT title, body AS message, created_at FROM announcements
        WHERE status = 'Published' ORDER BY created_at DESC LIMIT 3")->fetchAll();
    $distributionOverview = $conn->query("SELECT r.name AS resource_name, r.unit, SUM(d.quantity) AS quantity
        FROM distributions d JOIN resources r ON r.id = d.resource_id
        WHERE d.distributed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY r.id, r.name, r.unit ORDER BY quantity DESC LIMIT 4")->fetchAll();
} catch (Throwable $e) {
    error_log('Dashboard data unavailable (' . get_class($e) . ').');
}

$distributionQuantities = array_map(static fn(array $row): int => (int) $row['quantity'], $distributionOverview);
$distributionMax = max(array_merge([1], $distributionQuantities));

$pageTitle = 'Dashboard';
$pageDescription = 'SAGIPBRO administration dashboard for Barangay Binloc disaster relief operations.';
$basePath = '../';
$isAdmin = true;
$activeAdmin = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="admin-shell">
    <?php require __DIR__ . '/../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php require __DIR__ . '/../includes/navbar.php'; ?>
        <main id="main-content" class="admin-content">
            <?php require __DIR__ . '/../includes/alerts.php'; ?>

            <section class="dashboard-hero" aria-labelledby="dashboard-title">
                <div>
                    <h1 id="dashboard-title">Operations overview</h1>
                    <p>Monitor relief readiness, active centers, and the latest community response work across Bonuan Binloc.</p>
                </div>
                <div class="dashboard-date">
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    <span><strong><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></strong><span data-live-time><?= htmlspecialchars(date('g:i A'), ENT_QUOTES, 'UTF-8') ?></span></span>
                </div>
            </section>

            <section class="stat-grid" aria-label="Key operational totals">
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-card-label">Total resources</span><span class="stat-card-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span></div>
                    <strong class="stat-value" data-dashboard-stat="resources"><?= (int) $dashboardStats['resources'] ?></strong>
                    <span class="stat-meta">Live database records</span>
                </article>
                <article class="stat-card warning">
                    <div class="stat-card-top"><span class="stat-card-label">Low-stock items</span><span class="stat-card-icon"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></span></div>
                    <strong class="stat-value" data-dashboard-stat="low_stock"><?= (int) $dashboardStats['low_stock'] ?></strong>
                    <span class="stat-meta">Items at or below threshold</span>
                </article>
                <article class="stat-card info">
                    <div class="stat-card-top"><span class="stat-card-label">Evacuation centers</span><span class="stat-card-icon"><i class="bi bi-buildings" aria-hidden="true"></i></span></div>
                    <strong class="stat-value" data-dashboard-stat="centers"><?= (int) $dashboardStats['centers'] ?></strong>
                    <span class="stat-meta"><span data-dashboard-stat="open_centers"><?= (int) $dashboardStats['open_centers'] ?></span> open</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-card-label">Registered residents</span><span class="stat-card-icon"><i class="bi bi-people" aria-hidden="true"></i></span></div>
                    <strong class="stat-value" data-dashboard-stat="residents"><?= (int) $dashboardStats['residents'] ?></strong>
                    <span class="stat-meta">Active database records</span>
                </article>
                <article class="stat-card info">
                    <div class="stat-card-top"><span class="stat-card-label">Active volunteers</span><span class="stat-card-icon"><i class="bi bi-person-hearts" aria-hidden="true"></i></span></div>
                    <strong class="stat-value" data-dashboard-stat="volunteers"><?= (int) $dashboardStats['volunteers'] ?></strong>
                    <span class="stat-meta">Active volunteer accounts</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-card-label">Recent distributions</span><span class="stat-card-icon"><i class="bi bi-truck" aria-hidden="true"></i></span></div>
                    <strong class="stat-value" data-dashboard-stat="distributions"><?= (int) $dashboardStats['distributions'] ?></strong>
                    <span class="stat-meta"><span data-dashboard-stat="distributed_quantity"><?= (int) $dashboardStats['distributed_quantity'] ?></span> items in the last 30 days</span>
                </article>
            </section>

            <div class="dashboard-grid">
                <section class="data-card" aria-labelledby="activity-title">
                    <div class="data-card-header">
                        <div><h2 id="activity-title">Recent activity</h2><p>Latest updates from the operations team</p></div>
                        <a href="../pages/activity/index.php">View all activity <i class="bi bi-arrow-right"></i></a>
                    </div>
                    <div class="table-responsive">
                        <table class="table app-table">
                            <caption class="visually-hidden">Recent SAGIPBRO activity</caption>
                            <thead><tr><th scope="col">Activity</th><th scope="col">User</th><th scope="col">Module</th><th scope="col">Time</th></tr></thead>
                            <tbody>
                                <?php foreach ($recentActivity as $activity): ?>
                                    <?php $module = ucwords(str_replace(['_', '-'], ' ', (string) $activity['module'])); ?>
                                    <tr>
                                        <td><div class="activity-cell"><span class="activity-icon"><i class="bi bi-activity"></i></span><span><span class="table-primary-text"><?= htmlspecialchars(ucfirst((string) $activity['action']) . ' ' . strtolower($module), ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text">Database activity recorded</span></span></div></td>
                                        <td><?= htmlspecialchars((string) $activity['actor'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="status-badge status-info"><?= htmlspecialchars($module, ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><time datetime="<?= htmlspecialchars((string) $activity['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, g:i A', strtotime((string) $activity['created_at'])), ENT_QUOTES, 'UTF-8') ?></time></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (!$recentActivity): ?><tr><td colspan="4" class="text-center text-body-secondary py-4">No activity records yet.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <div class="dashboard-stack">
                    <section class="data-card warning-card" aria-labelledby="stock-warning-title">
                        <div class="data-card-header"><div><h2 id="stock-warning-title"><i class="bi bi-exclamation-triangle-fill me-1"></i> Low-stock warning</h2><p><?= (int) $dashboardStats['low_stock'] ?> items below their set threshold</p></div><a href="../pages/resources/index.php">Manage</a></div>
                        <div class="data-card-body">
                            <ul class="stock-warning-list">
                                <?php foreach ($lowStockItems as $item): ?>
                                    <li><span class="warning-icon"><i class="bi bi-exclamation-triangle"></i></span><span><strong><?= htmlspecialchars((string) $item['resource_name'], ENT_QUOTES, 'UTF-8') ?></strong><small>Threshold: <?= (int) $item['minimum_stock'] ?> <?= htmlspecialchars((string) $item['unit'], ENT_QUOTES, 'UTF-8') ?></small></span><span class="stock-count"><?= (int) $item['quantity'] ?> left</span></li>
                                <?php endforeach; ?>
                                <?php if (!$lowStockItems): ?><li class="text-body-secondary">No low-stock items.</li><?php endif; ?>
                            </ul>
                        </div>
                    </section>

                    <section class="data-card" aria-labelledby="announcement-title">
                        <div class="data-card-header"><div><h2 id="announcement-title">Recent announcements</h2><p>Public information updates</p></div><a href="../pages/announcements/index.php">View all</a></div>
                        <div class="data-card-body">
                            <ul class="announcement-list">
                                <?php foreach ($recentAnnouncements as $announcement): ?>
                                    <li class="announcement-item"><h3><?= htmlspecialchars((string) $announcement['title'], ENT_QUOTES, 'UTF-8') ?></h3><p><?= htmlspecialchars((string) $announcement['message'], ENT_QUOTES, 'UTF-8') ?></p><time datetime="<?= htmlspecialchars((string) $announcement['created_at'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(date('M j, g:i A', strtotime((string) $announcement['created_at'])), ENT_QUOTES, 'UTF-8') ?></time></li>
                                <?php endforeach; ?>
                                <?php if (!$recentAnnouncements): ?><li class="text-body-secondary">No published announcements yet.</li><?php endif; ?>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>

            <section class="data-card" aria-labelledby="distribution-overview-title">
                <div class="data-card-header"><div><h2 id="distribution-overview-title">Distribution overview</h2><p>Items released during the past 30 days</p></div><a href="../pages/reports/index.php">Open report <i class="bi bi-arrow-right"></i></a></div>
                <div class="data-card-body distribution-bars">
                    <?php foreach ($distributionOverview as $distribution): ?>
                        <div class="distribution-bar-row"><strong><?= htmlspecialchars((string) $distribution['resource_name'], ENT_QUOTES, 'UTF-8') ?></strong><div class="bar-track"><div class="bar-fill" style="width:<?= min(100, max(0, (int) round(((int) $distribution['quantity'] / $distributionMax) * 100))) ?>%"></div></div><span><?= (int) $distribution['quantity'] ?> <?= htmlspecialchars((string) $distribution['unit'], ENT_QUOTES, 'UTF-8') ?></span></div>
                    <?php endforeach; ?>
                    <?php if (!$distributionOverview): ?><p class="text-body-secondary mb-0">No distributions recorded in the last 30 days.</p><?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
