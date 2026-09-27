<?php
$pageTitle = 'SAGIPBRO';
$pageDescription = 'Official Disaster Relief Resource Information for Bonuan Binloc, Dagupan City—relief supplies, evacuation centers, distributions, and emergency updates.';
$activePage = 'home';
$basePath = '';
require_once __DIR__ . '/includes/public_service_helpers.php';
$snapshot = publicLoad(static fn(PDO $db): array => [
    'resources' => publicResources($db)['rows'],
    'centers' => publicCenters($db)['rows'],
]);
require __DIR__ . '/includes/header.php';
require __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <section class="home-hero" aria-labelledby="hero-title">
        <div class="container">
            <div class="hero-content">
                <div class="hero-chip"><span aria-hidden="true"></span> Serving Bonuan Binloc, Dagupan City</div>
                <h1 id="hero-title">SAGIPBRO</h1>
                <p class="hero-subtitle">Disaster Relief Resource Information System</p>
                <p class="hero-copy">One trusted place for residents and barangay responders to find relief supply availability, evacuation center information, and verified emergency updates when every minute matters.</p>
                <div class="hero-actions">
                    <a class="btn btn-white" href="resources.php#resource-directory"><i class="bi bi-box-seam" aria-hidden="true"></i> View resources</a>
                    <a class="btn btn-ghost-light" href="evacuation-centers.php#center-directory"><i class="bi bi-buildings" aria-hidden="true"></i> View evacuation centers</a>
                </div>
            </div>
        </div>
        <div class="hero-trust" aria-label="System qualities">
            <div class="container hero-trust-row">
                <span><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Barangay-verified information</span>
                <span><i class="bi bi-phone" aria-hidden="true"></i> Mobile-ready access</span>
                <span><i class="bi bi-clock-history" aria-hidden="true"></i> Timely operational updates</span>
            </div>
        </div>
    </section>

    <section class="emergency-band" aria-label="Emergency announcement">
        <div class="container">
            <div class="emergency-band-inner">
                <span class="emergency-label"><i class="bi bi-megaphone-fill" aria-hidden="true"></i> Advisory</span>
                <div class="emergency-message">
                    <strong>Community preparedness reminder</strong>
                    <span>Keep your family go-bag ready, monitor official weather bulletins, and know your nearest evacuation route.</span>
                </div>
                <a class="emergency-link" href="announcements.php#announcement-directory">Read guidance <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
        </div>
    </section>

    <section class="section-space" aria-labelledby="quick-info-title">
        <div class="container">
            <div class="section-heading">
                <span class="section-kicker">Quick information</span>
                <h2 id="quick-info-title">What do you need today?</h2>
                <p>Find clear, current information before, during, and after an emergency.</p>
            </div>
            <div class="quick-grid">
                <a class="quick-card" href="resources.php#resource-directory">
                    <span class="quick-icon"><i class="bi bi-box2-heart" aria-hidden="true"></i></span>
                    <h3>Relief resources</h3>
                    <p>Check the current availability of food, water, hygiene supplies, medicine, and other essential goods.</p>
                    <span class="quick-link">Browse supplies <i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
                </a>
                <a class="quick-card" href="evacuation-centers.php#center-directory">
                    <span class="quick-icon"><i class="bi bi-houses" aria-hidden="true"></i></span>
                    <h3>Evacuation centers</h3>
                    <p>Review center locations, operating status, capacity, and available accommodation before traveling.</p>
                    <span class="quick-link">Find a safe center <i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
                </a>
                <a class="quick-card" href="distributions.php#distribution-directory">
                    <span class="quick-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
                    <h3>Relief distribution</h3>
                    <p>Understand how organized relief is scheduled, recorded, and delivered fairly to affected households.</p>
                    <span class="quick-link">How distribution works <i class="bi bi-arrow-up-right" aria-hidden="true"></i></span>
                </a>
            </div>
        </div>
    </section>

    <section class="section-space section-soft" aria-labelledby="snapshot-title">
        <div class="container">
            <div class="section-heading">
                <span class="section-kicker">Situation snapshot</span>
                <h2 id="snapshot-title">Relief readiness at a glance</h2>
                <p>A quick view of recorded supplies and evacuation capacity.</p>
            </div>
            <?php publicDataNotice($snapshot); ?>
            <?php if (!$snapshot['error']): ?>
                <div class="snapshot-wrap">
                    <article class="surface-card">
                        <div class="surface-card-header"><div><h3>Relief inventory</h3><p>Latest quantities recorded in the barangay database</p></div><a class="btn btn-sm btn-brand-soft" href="resources.php#resource-directory">View all</a></div>
                        <div class="surface-card-body pt-0">
                            <?php if (!$snapshot['data']['resources']): ?>
                                <p>No resources have been recorded yet. Contact the barangay for availability.</p>
                            <?php else: ?>
                                <ul class="resource-list">
                                    <?php foreach (array_slice($snapshot['data']['resources'], 0, 4) as $resource): ?>
                                        <li><span class="resource-mini-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span><span><strong><?= publicEscape($resource['name']) ?></strong><small><?= publicEscape($resource['category']) ?> · <?= publicEscape($resource['unit']) ?></small></span><span class="resource-quantity"><strong><?= publicQuantity($resource['stock']) ?></strong><?php publicStatus($resource['availability']); ?></span></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </article>
                    <article class="center-feature">
                        <span class="feature-icon"><i class="bi bi-building-check" aria-hidden="true"></i></span>
                        <h2>Evacuation center availability</h2>
                        <?php $availableCenters = array_filter($snapshot['data']['centers'], static fn(array $center): bool => $center['availability'] === 'Available'); ?>
                        <p><?= count($availableCenters) ?> available of <?= count($snapshot['data']['centers']) ?> recorded centers. Full and closed centers are excluded from available spaces.</p>
                        <div class="occupancy-line"><span>Available spaces</span><strong><?= number_format(array_sum(array_column($availableCenters, 'available_spaces'))) ?></strong></div>
                        <a class="btn btn-ghost-light w-100 mt-4" href="evacuation-centers.php#center-directory"><i class="bi bi-geo-alt" aria-hidden="true"></i> View evacuation centers</a>
                    </article>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="pb-5" aria-labelledby="prepared-title">
        <div class="container">
            <div class="cta-panel">
                <div>
                    <h2 id="prepared-title">Prepared communities respond better.</h2>
                    <p>Review available resources, save official emergency contacts, and talk with your household about where to go before an emergency begins.</p>
                </div>
                <div class="cta-actions">
                    <a class="btn btn-white" href="resources.php#resource-directory">Check resources</a>
                    <a class="btn btn-ghost-light" href="contact.php#send-message">Contact us</a>
                </div>
            </div>
        </div>
    </section>
</main>
<?php require __DIR__ . '/includes/footer.php'; ?>
