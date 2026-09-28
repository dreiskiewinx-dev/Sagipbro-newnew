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
$messages = [];
$messagesLoaded = false;
$messageStatusClasses = ['Unread' => 'status-warning', 'Read' => 'status-info', 'Resolved' => 'status-success'];
try {
    $messages = $conn->query('SELECT id, name, email, phone, sitio, subject, message, status, created_at FROM contact_messages ORDER BY created_at DESC, id DESC')->fetchAll();
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
            <section class="data-card" aria-labelledby="messagesHeading" data-messages-inbox data-loaded="<?= $messagesLoaded ? 'true' : 'false' ?>">
                <p class="px-4 pt-3 mb-0 small text-secondary" data-messages-live-status role="status">Messages update automatically.</p>
                <?php $unreadCount = count(array_filter($messages, static fn(array $message): bool => $message['status'] === 'Unread')); ?>
                <div class="data-card-header"><div><h2 id="messagesHeading">Contact inbox</h2><p>Unread messages are highlighted. Use the actions to update each message.</p></div><span class="status-badge <?= $unreadCount ? 'status-warning' : 'status-success' ?>" data-message-count><?= $unreadCount ?> unread · <?= count($messages) ?> total</span></div>
                <div class="table-responsive"><table class="table app-table align-middle"><thead><tr><th>Sender</th><th>Sitio</th><th>Contact number</th><th>Subject</th><th>Message</th><th>Date</th><th>Status</th><th class="text-end">Actions</th></tr></thead><tbody>
                <?php foreach ($messages as $message): ?><tr class="<?= $message['status'] === 'Unread' ? 'message-row-unread' : '' ?>" data-message-id="<?= (int) $message['id'] ?>"><td><span class="table-primary-text"><?= htmlspecialchars($message['name'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($message['email'], ENT_QUOTES, 'UTF-8') ?></span></td><td><?= htmlspecialchars($message['sitio'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($message['phone'] ?: 'Not provided', ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars($message['subject'], ENT_QUOTES, 'UTF-8') ?></td><td style="max-width:420px;"><?= nl2br(htmlspecialchars($message['message'], ENT_QUOTES, 'UTF-8')) ?></td><td><?= htmlspecialchars($message['created_at'], ENT_QUOTES, 'UTF-8') ?></td><td><span class="status-badge <?= $messageStatusClasses[$message['status']] ?? 'status-neutral' ?>"><?= htmlspecialchars($message['status'], ENT_QUOTES, 'UTF-8') ?></span></td><td class="text-end"><div class="table-actions"><button class="btn btn-light btn-icon" type="button" title="<?= $message['status'] === 'Unread' ? 'Mark as read' : 'Mark as unread' ?>" aria-label="<?= $message['status'] === 'Unread' ? 'Mark message as read' : 'Mark message as unread' ?>" data-message-status="<?= $message['status'] === 'Unread' ? 'Read' : 'Unread' ?>"><i class="bi <?= $message['status'] === 'Unread' ? 'bi-envelope-open' : 'bi-envelope' ?>" aria-hidden="true"></i></button><?php if ($message['status'] !== 'Resolved'): ?><button class="btn btn-light btn-icon text-success" type="button" title="Resolve" aria-label="Mark message as resolved" data-message-status="Resolved"><i class="bi bi-check2-circle" aria-hidden="true"></i></button><?php endif; ?></div></td></tr><?php endforeach; ?>
                <?php if (!$messages): ?><tr><td colspan="8" class="text-center text-body-secondary py-4">No messages received yet.</td></tr><?php endif; ?>
                </tbody></table></div>
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
<script src="../../assets/js/messages-live.js?v=3" defer></script>
<?php include '../../includes/footer.php'; ?>
