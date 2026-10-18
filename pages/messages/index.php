<?php
require_once '../../includes/auth_check.php';
requireRole(['admin', 'official']);
require_once '../../config/database.php';
$conn = sagipbroDatabase();

$pageTitle = 'Messages';
$pageDescription = 'Review messages sent through the public contact form.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'messages';
$messagesLoaded = false;
$messageSummary = ['unread' => 0, 'total' => 0];
try {
    $messageSummary = $conn->query("SELECT COUNT(*) AS total, COALESCE(SUM(status = 'Unread'), 0) AS unread FROM contact_messages")->fetch();
    $messagesLoaded = true;
} catch (Throwable $e) {
    error_log('Messages unavailable (' . get_class($e) . ').');
}
include '../../includes/header.php';
?>
<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>
        <main class="admin-content" id="main-content">
            <header class="page-header"><div><nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../../dashboard/admin.php">Dashboard</a></li><li class="breadcrumb-item active">Messages</li></ol></nav><h1>Messages</h1><p>Messages sent through the public contact form.</p></div></header>
            <section class="filter-toolbar" aria-label="Message table filters">
                <div class="search-field">
                    <label for="messageSearch">Search messages</label>
                    <div class="input-group"><span class="input-group-text bg-white border-end-0"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control border-start-0" id="messageSearch" type="search" placeholder="Search sender, subject, or message" autocomplete="off" data-table-search="#messagesTable"></div>
                </div>
                <div class="filter-field">
                    <label for="messageStatus">Status</label>
                    <select class="form-select" id="messageStatus" data-filter-select="#messagesTable" data-filter-field="status"><option value="">All statuses</option><option>Unread</option><option>Read</option><option>Resolved</option></select>
                </div>
                <span class="filter-results" aria-live="polite" data-filter-results>Loading records…</span>
            </section>
            <section class="data-card" aria-labelledby="messagesHeading" data-messages-inbox data-loaded="<?= $messagesLoaded ? 'true' : 'false' ?>">
                <p class="px-4 pt-3 mb-0 small text-secondary" data-messages-live-status role="status">Messages update automatically.</p>
                <?php $unreadCount = (int) $messageSummary['unread']; ?>
                <div class="data-card-header"><div><h2 id="messagesHeading">Contact inbox</h2><p>Unread messages are highlighted. Use the actions to update each message.</p></div><span class="status-badge <?= $unreadCount ? 'status-warning' : 'status-success' ?>" data-message-count><?= $unreadCount ?> unread · <?= (int) $messageSummary['total'] ?> total</span></div>
                <div class="table-responsive"><table class="table app-table align-middle" id="messagesTable" data-pagination="messages" data-server-pagination="true"><thead><tr><th>Sender</th><th>Sitio</th><th>Contact number</th><th>Subject</th><th>Message</th><th>Date</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
                <tr><td colspan="8" class="text-center text-body-secondary py-4">Loading messages…</td></tr>
                </tbody></table></div>
                <div class="data-card-footer record-summary"><span data-pagination-summary>Loading records…</span></div>
            </section>
        </main>
    </div>
</div>
<script>
window.sagipbroMessagesApi = {
    endpoint: <?= json_encode(appUrl('api/messages.php')) ?>,
    csrfToken: <?= json_encode(csrfToken()) ?>
};
</script>
<script src="../../assets/js/messages-live.js?v=4" defer></script>
<?php include '../../includes/footer.php'; ?>
