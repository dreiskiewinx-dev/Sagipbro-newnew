<?php
require_once __DIR__ . '/includes/public_service_helpers.php';
$priorities = ['Urgent', 'High', 'Medium', 'Low', 'Normal'];
$filters = ['q' => publicInput('q'), 'priority' => publicChoice('priority', $priorities)];
$paging = publicPageRequest($_GET);
$viewRequested = array_key_exists('view', $_GET);
$viewId = filter_var($_GET['view'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$queryFilters = $viewRequested ? ['id' => $viewId] : $filters;
$result = publicLoad(static fn(PDO $database): array => publicAnnouncements($database, $queryFilters, $paging));
if ($viewRequested && !$result['error'] && !$result['data']['rows']) http_response_code(404);
$directoryUrl = publicPaginationUrl('announcements.php#announcement-directory', array_merge($filters, ['page' => $paging['page'], 'per_page' => $paging['per_page']]));
$pageTitle = 'Emergency Announcements';
$pageDescription = 'Read current published emergency announcements and urgent community advisories for Barangay Bonuan Binloc.';
$activePage = 'services';
$basePath = '';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <?php publicServiceHero('Emergency announcements', 'Read current barangay advisories and emergency instructions. Urgent notices are highlighted and appear first.', 'announcements'); ?>
    <section class="section-space section-soft" id="announcement-directory" data-scroll-target aria-label="Emergency announcement directory">
        <div class="container">
            <?php if ($result['error']) publicDataNotice($result); ?>
            <?php if (!$result['error']): $directory = $result['data']; ?>
                <?php if ($viewRequested): ?>
                    <a class="btn btn-outline-brand mb-4" href="<?= publicEscape($directoryUrl) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to Announcements</a>
                <?php else: ?>
                <?php publicFilterForm('announcements.php#announcement-directory', $filters, $priorities, null, 'priority'); ?>
                <p class="public-record-meta"><?= number_format($directory['total']) ?> announcement<?= $directory['total'] === 1 ? '' : 's' ?> found</p>
                <?php endif; ?>
                <?php if (!$directory['rows']): publicEmpty($viewRequested ? 'Announcement unavailable' : 'No current announcements found', $viewRequested ? 'This announcement may have expired or is no longer published. Browse current announcements for verified updates.' : 'There are no current published announcements matching these filters. Check again for updates.'); else: ?>
                    <div class="public-directory-grid<?= $viewRequested ? ' d-block' : '' ?>">
                        <?php foreach ($directory['rows'] as $announcement): ?>
                            <article id="announcement-<?= (int) $announcement['id'] ?>" class="public-info-card<?= $announcement['priority'] === 'Urgent' ? ' public-info-card--urgent' : '' ?>">
                                <div class="public-card-header"><h2><?= publicEscape($announcement['title']) ?></h2><?php publicStatus($announcement['priority']); ?></div>
                                <p class="public-record-meta">Published <?= publicEscape(publicDate($announcement['published_at'])) ?></p>
                                <div class="public-record-details"><?= publicEscape($announcement['body']) ?></div>
                                <?php if ($announcement['expires_at']): ?><p class="public-record-meta mt-3 mb-0">Valid until <?= publicEscape(publicDate($announcement['expires_at'])) ?></p><?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!$viewRequested) publicPagination($directory['pagination'], 'announcements.php#announcement-directory', $filters); ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
