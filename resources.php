<?php
$pageTitle = 'Relief Resources';
$pageDescription = 'Browse current relief-supply information for the SAGIPBRO public interface.';
$activePage = 'resources';
$basePath = '';

require_once __DIR__ . '/includes/public_service_helpers.php';
$statuses = ['Available', 'Low Stock', 'Out of Stock'];
$filters = ['q' => publicInput('q'), 'category' => publicInput('category'), 'status' => publicChoice('status', $statuses)];
$paging = publicPageRequest($_GET);
$result = publicLoad(static fn(PDO $database): array => publicResources($database, $filters, $paging));
$resourceIcons = ['Food' => 'bi-basket2', 'Water' => 'bi-droplet', 'Hygiene' => 'bi-handbag', 'Medical' => 'bi-bandaid', 'Shelter' => 'bi-grid'];

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <section class="page-hero public-service-hero" aria-labelledby="resources-page-title">
        <div class="container">
            <nav aria-label="Breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Resources</li>
                </ol>
            </nav>
            <span class="hero-chip"><span aria-hidden="true"></span> Relief supply directory</span>
            <h1 id="resources-page-title">Find resource information quickly.</h1>
            <p>Search and filter current relief resources recorded by authorized barangay staff.</p>
        </div>
    </section>

    <?php publicServiceNavigation('resources'); ?>

    <section class="section-space section-soft" id="resource-directory" data-scroll-target aria-label="Relief resource directory">
        <div class="container">
            <?php if ($result['error']) publicDataNotice($result); ?>
            <?php if (!$result['error']): $directory = $result['data']; ?>
            <?php publicFilterForm('resources.php#resource-directory', $filters, $statuses, $directory['categories']); ?>
            <p class="public-record-meta"><?= number_format($directory['total']) ?> resource<?= $directory['total'] === 1 ? '' : 's' ?> found</p>
            <?php if (!$directory['rows']): publicEmpty('No resources found', 'No supplies match these filters, or inventory has not been recorded yet. Try another search term or category.'); else: ?>
            <div class="public-resource-grid" id="resourceGrid">
                <?php foreach ($directory['rows'] as $resource): ?>
                    <article class="resource-card">
                        <div class="resource-card-top">
                            <div>
                                <div class="resource-mini-icon"><i class="bi <?= publicEscape($resourceIcons[$resource['category']] ?? 'bi-box-seam') ?>" aria-hidden="true"></i></div>
                                <h2><?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <span class="category"><?= htmlspecialchars($resource['category'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <?php publicStatus($resource['availability']); ?>
                        </div>
                        <div class="resource-stock">
                            <div>
                                <small>Available quantity</small>
                                <strong><?= publicQuantity($resource['stock']) ?></strong>
                            </div>
                            <small><?= htmlspecialchars($resource['unit'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php publicPagination($directory['pagination'], 'resources.php#resource-directory', $filters); ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="section-space" aria-labelledby="resource-guidance-title">
        <div class="container">
            <div class="icon-card-grid">
                <article class="icon-card">
                    <div class="icon-box"><i class="bi bi-check-circle" aria-hidden="true"></i></div>
                    <h2 id="resource-guidance-title">Confirm availability</h2>
                    <p>Relief availability can change. Contact the appropriate response channel before relying on a displayed quantity.</p>
                </article>
                <article class="icon-card">
                    <div class="icon-box"><i class="bi bi-person-check" aria-hidden="true"></i></div>
                    <h2>Follow distribution guidance</h2>
                    <p>Distribution schedules and eligibility instructions should come from an authorized announcement or official.</p>
                </article>
                <article class="icon-card">
                    <div class="icon-box"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i></div>
                    <h2>Use 911 for emergencies</h2>
                    <p>For an immediate threat to life or safety, call the national emergency hotline instead of using a website form.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="pb-5" aria-labelledby="resources-cta-title">
        <div class="container">
            <div class="cta-panel">
                <div>
                    <h2 id="resources-cta-title">Need confirmed assistance information?</h2>
                    <p>Use the verified Dagupan City disaster-response contact details listed on the contact page.</p>
                </div>
                <div class="cta-actions">
                    <a class="btn btn-white" href="contact.php#contact-details"><i class="bi bi-telephone" aria-hidden="true"></i> View contact details</a>
                    <a class="btn btn-ghost-light" href="tel:911">Call 911</a>
                </div>
            </div>
        </div>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
