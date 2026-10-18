<?php
require_once '../../includes/auth_check.php';
require_once '../../config/database.php';
requireRole(['admin', 'official']);

$pageTitle = 'Activity Logs';
$pageDescription = 'Review auditable activity across the SAGIPBRO operations console.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'activity';

$activityStats = ['today' => 0, 'users' => 0, 'warnings' => 0, 'blocked' => 0];
try {
    $activityStats = $conn->query("SELECT
        SUM(DATE(created_at) = CURDATE()) AS today,
        COUNT(DISTINCT CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN user_id END) AS users,
        SUM(LOWER(action) LIKE '%fail%' OR LOWER(action) LIKE '%warning%') AS warnings,
        SUM(LOWER(action) LIKE '%block%') AS blocked
        FROM activity_logs")->fetch() ?: $activityStats;
} catch (Throwable $e) {
    error_log('Activity page data unavailable (' . get_class($e) . ').');
}

include '../../includes/header.php';
?>
<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>
        <main class="admin-content" id="main-content">
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../../dashboard/admin.php">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Activity logs</li></ol></nav>
                    <h1>Activity logs</h1>
                    <p>Review a traceable history of important access and operational events.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-outline-brand" type="button" data-print="#activityLogsTable"><i class="bi bi-printer" aria-hidden="true"></i> Print</button>
                    <button class="btn btn-brand" type="button" data-export="#activityLogsTable" data-export-name="sagipbro-activity-log"><i class="bi bi-download" aria-hidden="true"></i> Export log</button>
                </div>
            </header>

            <section class="stat-grid" aria-label="Activity log overview">
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Events today</span><span class="stat-card-icon"><i class="bi bi-activity" aria-hidden="true"></i></span></div><strong class="stat-value"><?= (int) $activityStats['today'] ?></strong><span class="stat-meta">Recorded database activity</span></article>
                <article class="stat-card info"><div class="stat-card-top"><span class="stat-card-label">Active users</span><span class="stat-card-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></span></div><strong class="stat-value"><?= (int) $activityStats['users'] ?></strong><span class="stat-meta">Users active in the last 24 hours</span></article>
                <article class="stat-card warning"><div class="stat-card-top"><span class="stat-card-label">Warnings</span><span class="stat-card-icon"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i></span></div><strong class="stat-value"><?= (int) $activityStats['warnings'] ?></strong><span class="stat-meta">Events requiring review</span></article>
                <article class="stat-card danger"><div class="stat-card-top"><span class="stat-card-label">Blocked attempts</span><span class="stat-card-icon"><i class="bi bi-shield-x" aria-hidden="true"></i></span></div><strong class="stat-value"><?= (int) $activityStats['blocked'] ?></strong><span class="stat-meta">Rejected security events</span></article>
            </section>

            <div class="info-callout mb-3" role="note"><i class="bi bi-shield-check" aria-hidden="true"></i><div><strong>Audit records are read-only</strong><span>Logs help Barangay Binloc account for system changes. Times are shown in Philippine Standard Time (UTC+8).</span></div></div>

            <section aria-labelledby="activityHistoryHeading">
                <div class="filter-toolbar">
                    <div class="search-field"><label for="activitySearch">Search activity</label><div class="input-icon"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="activitySearch" type="search" placeholder="User, event, module, record or IP" autocomplete="off" data-table-search="#activityLogsTable"></div></div>
                    <div class="filter-field"><label for="activityTypeFilter">Event type</label><select class="form-select" id="activityTypeFilter" data-filter-select="#activityLogsTable" data-filter-field="action"><option value="">All events</option><option value="Created">Created</option><option value="Updated">Updated</option><option value="Published">Published</option><option value="Signed in">Signed in</option><option value="Exported">Exported</option><option value="Warning">Warning</option><option value="Blocked">Blocked</option></select></div>
                    <span class="filter-results" aria-live="polite" data-filter-results>Loading activity…</span>
                </div>

                <div class="data-card">
                    <div class="data-card-header"><div><h2 id="activityHistoryHeading">System activity history</h2><p>Security, data changes, announcements, distributions, and report events.</p></div><span class="status-badge status-success">Logging active</span></div>
                    <div class="table-responsive activity-table-responsive">
                        <table class="table app-table" id="activityLogsTable" data-pagination="activity events" data-server-pagination="true" data-endpoint="<?= htmlspecialchars(appUrl('api/activity.php'), ENT_QUOTES, 'UTF-8') ?>">
                            <caption class="visually-hidden">Recent SAGIPBRO system activity</caption>
                            <thead><tr><th scope="col">Event</th><th scope="col">User</th><th scope="col">Module</th><th scope="col">Details</th><th scope="col">Source</th><th scope="col">Date &amp; time</th><th scope="col" class="text-end">Review</th></tr></thead>
                            <tbody>
                                <tr><td colspan="7" class="text-center text-body-secondary py-4">Loading activity…</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="data-card-footer record-summary"><span>Loading activity…</span></div>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal" id="activityDetailModal" tabindex="-1" aria-labelledby="activityDetailModalLabel" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><div><h2 class="modal-title" id="activityDetailModalLabel">Activity event details</h2><p class="mb-0 mt-1 small text-body-secondary">Select an activity record to review it.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body"><div class="empty-state py-4"><i class="bi bi-activity"></i><h3>No event selected</h3><p>Event details are loaded from the activity log.</p></div></div>
    <div class="modal-footer"><button class="btn btn-brand" type="button" data-bs-dismiss="modal">Done</button></div>
</div></div></div>
<script src="../../assets/js/activity-api.js?v=1"></script>
<?php include '../../includes/footer.php'; ?>
