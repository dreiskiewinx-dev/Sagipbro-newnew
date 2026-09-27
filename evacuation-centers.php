<?php
require_once __DIR__ . '/includes/public_service_helpers.php';
$statuses = ['Available', 'Full', 'Closed'];
$filters = ['q' => publicInput('q'), 'status' => publicChoice('status', $statuses)];
$result = publicLoad(static fn(PDO $database): array => publicCenters($database, $filters));
$pageTitle = 'Evacuation Centers';
$pageDescription = 'Find recorded evacuation centers, locations, capacities, current occupants and available spaces in Barangay Bonuan Binloc.';
$activePage = 'services';
$basePath = '';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <?php publicServiceHero('Evacuation centers', 'Find a center, check available spaces, and review its current status. Follow barangay instructions before travelling to an evacuation center.', 'centers'); ?>
    <section class="section-space section-soft" id="center-directory" data-scroll-target aria-labelledby="center-directory-title">
        <div class="container">
            <div class="section-heading"><span class="section-kicker">Evacuation directory</span><h2 id="center-directory-title">Find a center with space</h2><p>Available centers appear first and are highlighted in green. Full and closed centers cannot accept additional occupants.</p></div>
            <?php publicDataNotice($result); ?>
            <?php if (!$result['error']): $directory = $result['data']; ?>
                <?php publicFilterForm('evacuation-centers.php#center-directory', $filters, $statuses); ?>
                <p class="public-record-meta"><?= number_format($directory['total']) ?> center<?= $directory['total'] === 1 ? '' : 's' ?> found</p>
                <?php if (!$directory['rows']): publicEmpty('No evacuation centers found', 'No centers match these filters, or none have been recorded yet. Contact the barangay hall for current evacuation guidance.'); else: ?>
                    <div class="public-directory-grid">
                        <?php foreach ($directory['rows'] as $center): ?>
                            <article class="public-info-card<?= $center['availability'] === 'Available' ? ' public-info-card--available' : '' ?>">
                                <div class="public-card-header"><h2><?= publicEscape($center['name']) ?></h2><?php publicStatus($center['availability']); ?></div>
                                <p><i class="bi bi-geo-alt" aria-hidden="true"></i> <?= publicEscape($center['address']) ?></p>
                                <dl class="public-metrics"><div><dt>Capacity</dt><dd><?= number_format($center['capacity']) ?></dd></div><div><dt>Current occupants</dt><dd><?= number_format($center['occupants']) ?></dd></div><div><dt>Available spaces</dt><dd><?= number_format($center['available_spaces']) ?></dd></div></dl>
                                <?php if ($center['availability'] === 'Available'): ?><p class="text-success"><i class="bi bi-check-circle" aria-hidden="true"></i> Spaces currently available</p><?php endif; ?>
                                <p class="public-record-meta mb-0">Last updated <?= publicEscape(publicDate($center['updated_at'])) ?></p>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
