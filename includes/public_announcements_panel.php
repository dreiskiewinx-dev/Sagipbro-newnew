<?php
declare(strict_types=1);

/** Keep the service catalogue available even when announcement retrieval fails. */
function publicAnnouncementPanelLoad(): array
{
    header('Cache-Control: no-store, max-age=0');
    $db = null;
    try {
        $db = sagipbroDatabase();
        $db->beginTransaction();
        $data = publicAnnouncementPanel($db);
        $db->commit();
        return ['data' => $data, 'error' => false];
    } catch (Throwable $error) {
        if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
        error_log('Public announcements panel unavailable (' . get_class($error) . ').');
        return ['data' => null, 'error' => true];
    }
}

function publicAnnouncementsPanel(array $result): void
{
    $rows = $result['data']['rows'] ?? [];
    $urgent = (int) ($result['data']['urgent_count'] ?? 0);
    $shownUrgent = count(array_filter($rows, static fn ($row) => $row['priority'] === 'Urgent'));
    ?>
    <aside class="public-announcements-aside" aria-labelledby="services-announcements-title" data-announcements-panel>
        <div class="public-announcements-panel">
            <header class="announcements-panel-header">
                <div><span class="section-kicker">Community updates</span><h2 id="services-announcements-title"><i class="bi bi-megaphone" aria-hidden="true"></i> Announcements</h2></div>
                <?php if (!$result['error']): ?><span class="announcements-urgent-count" aria-label="<?= $urgent ?> active urgent announcements"><i class="bi bi-bell-fill" aria-hidden="true"></i> <?= number_format($urgent) ?> urgent</span><?php endif; ?>
            </header>
            <div class="announcements-panel-content" data-announcements-content>
                <?php if ($result['error']): ?>
                    <div class="announcements-panel-empty" role="status"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><h3>Updates temporarily unavailable</h3><p>Refresh this page to check current announcements. For immediate assistance, call <a href="tel:911">911</a>.</p></div>
                <?php elseif (!$rows): ?>
                    <div class="announcements-panel-empty"><i class="bi bi-megaphone" aria-hidden="true"></i><h3>No current announcements</h3><p>Published community updates will appear here. Check again for the latest notices.</p></div>
                <?php else: ?>
                    <div class="announcements-panel-list" data-announcements-list>
                        <?php if ($urgent > $shownUrgent): ?><p class="announcements-urgent-overflow">Showing <?= $shownUrgent ?> of <?= number_format($urgent) ?> urgent notices. <a href="announcements.php?priority=Urgent#announcement-directory">Read all urgent announcements</a>.</p><?php endif; ?>
                        <?php foreach ($rows as $announcement): ?>
                            <details class="announcement-panel-entry<?= $announcement['priority'] === 'Urgent' ? ' announcement-panel-entry--urgent' : '' ?>" data-announcement-id="<?= (int) $announcement['id'] ?>">
                                <summary data-announcement-open>
                                    <span class="announcement-panel-meta"><?php publicStatus($announcement['priority']); ?><time datetime="<?= publicEscape(str_replace(' ', 'T', $announcement['published_at']) . '+08:00') ?>"><?= publicEscape(publicDate($announcement['published_at'])) ?></time></span>
                                    <span class="announcement-panel-title"><?= publicEscape($announcement['title']) ?></span>
                                    <span class="announcement-panel-preview"><?= publicEscape($announcement['body']) ?></span>
                                    <span class="announcement-panel-read">Read announcement <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                                </summary>
                                <div class="announcement-panel-full" data-announcement-full>
                                    <h3 tabindex="-1"><?= publicEscape($announcement['title']) ?></h3>
                                    <div class="announcement-panel-detail-meta"><?php publicStatus($announcement['priority']); ?><span class="status-badge status-success">Published</span></div>
                                    <p class="public-record-meta">Published <?= publicEscape(publicDate($announcement['published_at'])) ?></p>
                                    <div class="public-record-details"><?= publicEscape($announcement['body']) ?></div>
                                    <?php if ($announcement['expires_at']): ?><p class="public-record-meta mt-3 mb-0">Valid until <?= publicEscape(publicDate($announcement['expires_at'])) ?></p><?php endif; ?>
                                </div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                    <section class="announcements-panel-detail" aria-label="Announcement details" data-announcements-detail hidden>
                        <button class="announcements-panel-back" type="button" data-announcements-back><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Announcements</button>
                        <div data-announcements-detail-content></div>
                    </section>
                <?php endif; ?>
            </div>
            <footer class="announcements-panel-footer"><a href="announcements.php#announcement-directory">View All Announcements <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></footer>
        </div>
    </aside>
    <?php
}
