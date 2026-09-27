<?php
require_once __DIR__ . '/../config/session.php';
requireRole(['volunteer']);
require_once __DIR__ . '/../config/database.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$accountName = trim((string) ($_SESSION['full_name'] ?? ''));
$volunteer = null;
$assignments = [];
$advisory = null;
$assignmentCount = 0;
$activeCenters = 0;
$householdsAssisted = 0;
$itemsDistributed = 0;
$nextAssignment = null;

if ($accountName !== '') {
    $volunteerStatement = $conn->prepare('SELECT * FROM volunteers WHERE full_name = ? ORDER BY updated_at DESC LIMIT 1');
    $volunteerStatement->execute([$accountName]);
    $volunteer = $volunteerStatement->fetch() ?: null;
}

$activeCenters = (int) $conn->query("SELECT COUNT(*) FROM evacuation_centers WHERE status = 'Open'")->fetchColumn();

$assignments = $conn->query(
    "SELECT id, title, location, details, starts_at, ends_at, status
     FROM distribution_events
     WHERE publication_status = 'Published' AND DATE(starts_at) = CURDATE()
     ORDER BY starts_at"
)->fetchAll();
$assignmentCount = count($assignments);
foreach ($assignments as $assignment) {
    if (strtotime((string) $assignment['starts_at']) >= time() && $assignment['status'] !== 'Cancelled') {
        $nextAssignment = $assignment;
        break;
    }
}

if ($userId > 0) {
    $distributionStatement = $conn->prepare(
        'SELECT COUNT(DISTINCT COALESCE(recipient_resident_id, recipient_name)) AS households, COALESCE(SUM(quantity), 0) AS items
         FROM distributions WHERE distributed_by = ? AND DATE(distributed_at) = CURDATE()'
    );
    $distributionStatement->execute([$userId]);
    $distributionStats = $distributionStatement->fetch() ?: [];
    $householdsAssisted = (int) ($distributionStats['households'] ?? 0);
    $itemsDistributed = (int) ($distributionStats['items'] ?? 0);
}

$advisoryResult = $conn->query(
    "SELECT title, body, priority, published_at
     FROM announcements
     WHERE status = 'Published'
       AND published_at IS NOT NULL AND published_at <= NOW()
       AND (expires_at IS NULL OR expires_at >= NOW())
       AND audience IN ('All residents', 'Volunteers')
     ORDER BY (priority = 'Urgent') DESC, published_at DESC
     LIMIT 1"
);
$advisory = $advisoryResult ? ($advisoryResult->fetch() ?: null) : null;

$pageTitle = 'Volunteer dashboard';
$pageDescription = 'SAGIPBRO volunteer coordination dashboard.';
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
            <section class="dashboard-hero" aria-labelledby="volunteer-title">
                <div><h1 id="volunteer-title">Volunteer operations</h1><p>View today's assignments, record relief work, and stay informed about active barangay response operations.</p></div>
                <div class="dashboard-date"><i class="bi bi-calendar-check"></i><span><strong><?= htmlspecialchars(date('l, F j'), ENT_QUOTES, 'UTF-8') ?></strong><span data-live-time><?= htmlspecialchars(date('g:i A'), ENT_QUOTES, 'UTF-8') ?></span></span></div>
            </section>
            <section class="stat-grid" aria-label="Volunteer overview">
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Today's assignments</span><span class="stat-card-icon"><i class="bi bi-clipboard2-check"></i></span></div><strong class="stat-value"><?= $assignmentCount ?></strong><span class="stat-meta"><span class="trend-up"><?= $nextAssignment ? 'Next at ' . htmlspecialchars(date('g:i A', strtotime((string) $nextAssignment['starts_at'])), ENT_QUOTES, 'UTF-8') : 'No upcoming assignment today' ?></span></span></article>
                <article class="stat-card info"><div class="stat-card-top"><span class="stat-card-label">Open centers</span><span class="stat-card-icon"><i class="bi bi-buildings"></i></span></div><strong class="stat-value"><?= $activeCenters ?></strong><span class="stat-meta">Current evacuation-center records</span></article>
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Households assisted</span><span class="stat-card-icon"><i class="bi bi-house-heart"></i></span></div><strong class="stat-value"><?= $householdsAssisted ?></strong><span class="stat-meta">Recorded by you today</span></article>
                <article class="stat-card warning"><div class="stat-card-top"><span class="stat-card-label">Items distributed</span><span class="stat-card-icon"><i class="bi bi-box-seam"></i></span></div><strong class="stat-value"><?= $itemsDistributed ?></strong><span class="stat-meta">Recorded by you today</span></article>
            </section>
            <div class="dashboard-grid">
                <section class="data-card" aria-labelledby="assignments-title">
                    <div class="data-card-header"><div><h2 id="assignments-title">Today's assignments</h2><p><?= htmlspecialchars(date('l, F j, Y'), ENT_QUOTES, 'UTF-8') ?></p></div><span class="status-badge <?= $volunteer && in_array($volunteer['status'], ['Active', 'Deployed'], true) ? 'status-success' : 'status-neutral' ?>"><?= $volunteer ? htmlspecialchars((string) $volunteer['status'], ENT_QUOTES, 'UTF-8') : 'Profile not linked' ?></span></div>
                    <div class="table-responsive"><table class="table app-table"><caption class="visually-hidden">Volunteer assignments</caption><thead><tr><th>Time</th><th>Assignment</th><th>Location</th><th>Details</th><th>Status</th></tr></thead><tbody>
                        <?php if ($assignments): foreach ($assignments as $assignment):
                            $assignmentStatusClass = $assignment['status'] === 'Completed' ? 'status-success' : ($assignment['status'] === 'Active' ? 'status-warning' : ($assignment['status'] === 'Cancelled' ? 'status-danger' : 'status-info'));
                        ?>
                        <tr><td><?= htmlspecialchars(date('g:i A', strtotime((string) $assignment['starts_at'])), ENT_QUOTES, 'UTF-8') ?></td><td><span class="table-primary-text"><?= htmlspecialchars((string) $assignment['title'], ENT_QUOTES, 'UTF-8') ?></span><?= !empty($assignment['ends_at']) ? '<span class="table-secondary-text">Until ' . htmlspecialchars(date('g:i A', strtotime((string) $assignment['ends_at'])), ENT_QUOTES, 'UTF-8') . '</span>' : '' ?></td><td><?= htmlspecialchars((string) $assignment['location'], ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars((string) ($assignment['details'] ?: 'No additional details'), ENT_QUOTES, 'UTF-8') ?></td><td><span class="status-badge <?= $assignmentStatusClass ?>"><?= htmlspecialchars((string) $assignment['status'], ENT_QUOTES, 'UTF-8') ?></span></td></tr>
                        <?php endforeach; else: ?>
                        <tr><td colspan="5"><div class="empty-state py-4"><i class="bi bi-calendar2-check"></i><h3>No assignments today</h3><p>Published distribution events scheduled for today will appear here.</p></div></td></tr>
                        <?php endif; ?>
                    </tbody></table></div>
                </section>
                <div class="dashboard-stack">
                    <section class="data-card" aria-labelledby="quick-action-title"><div class="data-card-header"><h2 id="quick-action-title">Quick actions</h2></div><div class="data-card-body d-grid gap-2"><a class="btn btn-brand justify-content-start" href="../pages/distribution/index.php"><i class="bi bi-plus-circle"></i> Record distribution</a><a class="btn btn-brand-soft justify-content-start" href="../evacuation-centers.php#center-directory"><i class="bi bi-person-check"></i> View center information</a><a class="btn btn-outline-brand justify-content-start" href="../resources.php#resource-directory"><i class="bi bi-box-seam"></i> Check public resource view</a></div></section>
                    <section class="data-card warning-card" aria-labelledby="volunteer-advisory"><div class="data-card-header"><h2 id="volunteer-advisory"><i class="bi bi-megaphone-fill me-1"></i> Team advisory</h2></div><div class="data-card-body"><?php if ($advisory): ?><h3 class="h6 mb-2"><?= htmlspecialchars((string) $advisory['title'], ENT_QUOTES, 'UTF-8') ?></h3><p class="mb-2" style="font-size:.72rem"><?= nl2br(htmlspecialchars((string) $advisory['body'], ENT_QUOTES, 'UTF-8')) ?></p><span class="status-badge <?= $advisory['priority'] === 'Urgent' ? 'status-danger' : 'status-warning' ?>"><?= htmlspecialchars(date('M j, Y g:i A', strtotime((string) $advisory['published_at'])), ENT_QUOTES, 'UTF-8') ?></span><?php else: ?><div class="empty-state py-3"><i class="bi bi-megaphone"></i><h3>No active advisory</h3><p>Published volunteer announcements will appear here.</p></div><?php endif; ?></div></section>
                </div>
            </div>
        </main>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
