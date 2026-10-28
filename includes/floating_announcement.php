<?php
require_once __DIR__ . '/public_data.php';

// An optional shortcut must never interrupt a working public page during an outage.
$floatingAnnouncement = null;
try {
    $floatingAnnouncement = publicFloatingAnnouncement(sagipbroDatabase());
} catch (Throwable $error) {
    error_log('Floating announcement unavailable (' . get_class($error) . ').');
}
?>
<?php if ($floatingAnnouncement !== null): ?>
<aside class="floating-announcement" id="floatingAnnouncementCard" data-floating-announcement="<?= (int) $floatingAnnouncement['id'] ?>" aria-label="Latest announcement">
    <a class="floating-announcement-link" href="<?= htmlspecialchars(appUrl('announcements.php#announcement-directory'), ENT_QUOTES, 'UTF-8') ?>" aria-label="View all announcements">
        <span class="floating-announcement-icon" aria-hidden="true">📢</span>
        <span class="floating-announcement-copy">
            <span class="floating-announcement-label">Latest Announcement</span>
            <span class="floating-announcement-title"><?= htmlspecialchars($floatingAnnouncement['title'], ENT_QUOTES, 'UTF-8') ?></span>
        </span>
    </a>
    <button class="floating-announcement-minimize" type="button" aria-label="Minimize announcement" title="Minimize announcement" data-floating-announcement-minimize><span aria-hidden="true">&minus;</span></button>
</aside>
<button class="floating-announcement-restore" type="button" aria-label="Show announcements" title="Show announcements" aria-controls="floatingAnnouncementCard" aria-expanded="false" data-floating-announcement-restore hidden><span aria-hidden="true">&#128226;</span></button>
<script src="<?= htmlspecialchars(appUrl('assets/js/floating-announcement.js?v=3'), ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
