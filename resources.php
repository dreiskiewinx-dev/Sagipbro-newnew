<?php
$pageTitle = 'Relief Resources';
$pageDescription = 'Browse current relief-supply information for the SAGIPBRO public interface.';
$activePage = 'resources';
$basePath = '';

require_once __DIR__ . '/includes/public_service_helpers.php';
$resources = [];
$resourceIcons = ['Food' => 'bi-basket2', 'Water' => 'bi-droplet', 'Hygiene' => 'bi-handbag', 'Medical' => 'bi-bandaid', 'Shelter' => 'bi-grid'];
try {
    $liveResources = publicResources(sagipbroDatabase())['rows'];
    foreach ($liveResources as $resource) {
        $status = $resource['availability'];
        $resources[] = [
            'name' => $resource['name'], 'category' => $resource['category'], 'quantity' => (int) $resource['stock'],
            'unit' => $resource['unit'], 'status' => $status, 'tone' => $status === 'Available' ? 'success' : ($status === 'Low Stock' ? 'warning' : 'danger'),
            'icon' => $resourceIcons[$resource['category']] ?? 'bi-box-seam'
        ];
    }
} catch (Throwable $e) {
    error_log('Public resources unavailable (' . get_class($e) . ').');
}

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <section class="page-hero photo-hero resources-hero" aria-labelledby="resources-page-title">
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

    <section class="section-space section-soft" id="resource-directory" data-scroll-target aria-labelledby="resource-directory-title">
        <div class="container">
            <div class="section-heading">
                <span class="section-kicker">Live inventory</span>
                <h2 id="resource-directory-title">Relief supply overview</h2>
                <p>Current public resource names, categories, quantities, units, and stock conditions.</p>
            </div>

            <?php if (!$resources): ?><div class="alert alert-info app-alert mb-4" role="status">
                <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                <div>
                    <strong>No resources recorded yet</strong>
                    <span>Authorized administrators can add current inventory from the admin Resources page.</span>
                </div>
                <span class="status-badge status-neutral">Empty</span>
            </div><?php endif; ?>

            <div class="filter-panel" role="search" aria-label="Filter relief resources">
                <div class="filter-search">
                    <label for="resourceSearch">Search supplies</label>
                    <div class="input-icon">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input class="form-control" id="resourceSearch" type="search" placeholder="Search by resource name..." autocomplete="off" aria-controls="resourceGrid" data-resource-search>
                    </div>
                </div>
                <div class="filter-field">
                    <label for="resourceCategory">Category</label>
                    <select class="form-select" id="resourceCategory" aria-controls="resourceGrid" data-resource-filter>
                        <option value="all">All categories</option>
                        <option value="food">Food</option>
                        <option value="water">Water</option>
                        <option value="hygiene">Hygiene</option>
                        <option value="medical">Medical</option>
                        <option value="shelter">Shelter</option>
                    </select>
                </div>
                <p class="mb-2 ms-auto small text-secondary" aria-live="polite"><strong data-resource-count><?= count($resources) ?></strong> resources shown</p>
            </div>

            <div class="public-resource-grid" id="resourceGrid">
                <?php foreach ($resources as $resource): ?>
                    <article
                        class="resource-card"
                        data-resource-card
                        data-resource-name="<?= htmlspecialchars(strtolower($resource['name']), ENT_QUOTES, 'UTF-8') ?>"
                        data-resource-category="<?= htmlspecialchars(strtolower($resource['category']), ENT_QUOTES, 'UTF-8') ?>"
                    >
                        <div class="resource-card-top">
                            <div>
                                <div class="resource-mini-icon"><i class="bi <?= htmlspecialchars($resource['icon'], ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></i></div>
                                <h2><?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                <span class="category"><?= htmlspecialchars($resource['category'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                            <span class="status-badge status-<?= htmlspecialchars($resource['tone'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($resource['status'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                        <div class="resource-stock">
                            <div>
                                <small>Available quantity</small>
                                <strong><?= number_format($resource['quantity']) ?></strong>
                            </div>
                            <small><?= htmlspecialchars($resource['unit'], ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="empty-state surface-card mt-3" data-resource-empty hidden aria-live="polite">
                <i class="bi bi-search" aria-hidden="true"></i>
                <h3>No resources match</h3>
                <p>Try another search term or choose a different category.</p>
            </div>
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
