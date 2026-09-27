<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/public_service_helpers.php';

$statuses = ['Active', 'Upcoming', 'Completed', 'Cancelled'];
$filters = [
    'q' => publicInput('q'),
    'status' => publicChoice('status', $statuses),
];
$result = publicLoad(static fn(PDO $database): array => publicDistributions($database, $filters));

$pageTitle = 'Relief Distribution';
$pageDescription = 'View published relief-distribution schedules, locations, supplies, and public distribution totals for Barangay Bonuan Binloc.';
$activePage = 'services';
$basePath = '';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <?php publicServiceHero(
        'Relief distribution',
        'Check published distribution schedules, locations, instructions, and supply totals from current barangay records.',
        'distributions'
    ); ?>

    <section class="section-space section-soft" aria-labelledby="distribution-directory-title">
        <div class="container">
            <div class="section-heading">
                <span class="section-kicker">Published operations</span>
                <h2 id="distribution-directory-title">Relief distribution schedules and records</h2>
                <p>Active and upcoming schedules appear first. Public totals never include recipient names, contact details, or private release notes.</p>
            </div>

            <?php publicDataNotice($result); ?>
            <?php if (!$result['error']): $directory = $result['data']; ?>
                <?php publicFilterForm('distributions.php', $filters, $statuses); ?>
                <p class="public-record-meta"><?= number_format($directory['total']) ?> distribution<?= $directory['total'] === 1 ? '' : 's' ?> found</p>

                <?php if (!$directory['rows']): ?>
                    <?php publicEmpty('No relief distributions found', 'There are no published distribution schedules or public distribution summaries matching these filters.'); ?>
                <?php else: ?>
                    <div class="public-directory-grid">
                        <?php foreach ($directory['rows'] as $distribution): ?>
                            <article class="public-info-card<?= $distribution['status'] === 'Active' ? ' public-info-card--available' : '' ?>">
                                <div class="public-card-header">
                                    <h2><?= publicEscape($distribution['title']) ?></h2>
                                    <?php publicStatus($distribution['status']); ?>
                                </div>

                                <p><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= publicEscape($distribution['location']) ?></p>
                                <dl class="row small mb-3">
                                    <dt class="col-sm-4">Starts</dt>
                                    <dd class="col-sm-8"><?= publicEscape(publicDate($distribution['starts_at'])) ?></dd>
                                    <?php if (!empty($distribution['ends_at'])): ?>
                                        <dt class="col-sm-4">Ends</dt>
                                        <dd class="col-sm-8"><?= publicEscape(publicDate($distribution['ends_at'])) ?></dd>
                                    <?php endif; ?>
                                </dl>

                                <?php if (!empty($distribution['details'])): ?>
                                    <p class="public-record-details"><?= publicEscape($distribution['details']) ?></p>
                                <?php endif; ?>

                                <h3 class="h6 mt-4">Relief supplies</h3>
                                <?php if (empty($distribution['resources'])): ?>
                                    <p class="public-record-meta">No supply quantities have been published for this distribution.</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table app-table mb-3">
                                            <caption class="visually-hidden">Published supplies for <?= publicEscape($distribution['title']) ?></caption>
                                            <thead><tr><th scope="col">Resource</th><th scope="col">Planned</th><th scope="col">Distributed</th></tr></thead>
                                            <tbody>
                                                <?php foreach ($distribution['resources'] as $resource): ?>
                                                    <tr>
                                                        <td><?= publicEscape($resource['name']) ?></td>
                                                        <td><?= $resource['planned_quantity'] === null ? 'Not specified' : publicEscape(publicQuantity($resource['planned_quantity']) . ' ' . $resource['unit']) ?></td>
                                                        <td><?= publicEscape(publicQuantity($resource['distributed_quantity']) . ' ' . $resource['unit']) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>

                                <p class="public-record-meta mb-0">Last updated <?= publicEscape(publicDate($distribution['updated_at'])) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
