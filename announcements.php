<?php
require_once __DIR__ . '/includes/public_service_helpers.php';
$priorities = ['Normal', 'Urgent'];
$filters = ['q' => publicInput('q'), 'priority' => publicChoice('priority', $priorities)];
$result = publicLoad(static fn(PDO $database): array => publicAnnouncements($database, $filters));
$pageTitle = 'Emergency Announcements';
$pageDescription = 'Read current published emergency announcements and urgent community advisories for Barangay Bonuan Binloc.';
$activePage = 'services';
$basePath = '';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <?php publicServiceHero('Emergency announcements', 'Read current barangay advisories and emergency instructions. Urgent notices are highlighted and appear first.', 'announcements'); ?>
    <section class="section-space section-soft" id="announcement-directory" data-scroll-target aria-labelledby="announcement-directory-title">
        <div class="container">
            <div class="section-heading"><span class="section-kicker">Community advisories</span><h2 id="announcement-directory-title">Current announcements</h2><p>Published announcements remain visible until they expire or are archived. For an immediate threat to life, call 911.</p></div>
            <?php publicDataNotice($result); ?>
            <?php if (!$result['error']): $directory = $result['data']; ?>
                <?php publicFilterForm('announcements.php#announcement-directory', $filters, $priorities, null, 'priority'); ?>
                <p class="public-record-meta"><?= number_format($directory['total']) ?> announcement<?= $directory['total'] === 1 ? '' : 's' ?> found</p>
                <?php if (!$directory['rows']): publicEmpty('No current announcements found', 'There are no current published announcements matching these filters. Check again for updates.'); else: ?>
                    <div class="public-directory-grid">
                        <?php foreach ($directory['rows'] as $announcement): ?>
                            <article class="public-info-card<?= $announcement['priority'] === 'Urgent' ? ' public-info-card--urgent' : '' ?>">
                                <div class="public-card-header"><h2><?= publicEscape($announcement['title']) ?></h2><?php publicStatus($announcement['priority']); ?></div>
                                <p class="public-record-meta">Published <?= publicEscape(publicDate($announcement['published_at'])) ?></p>
                                <div class="public-record-details"><?= publicEscape($announcement['body']) ?></div>
                                <?php if ($announcement['expires_at']): ?><p class="public-record-meta mt-3 mb-0">Valid until <?= publicEscape(publicDate($announcement['expires_at'])) ?></p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
