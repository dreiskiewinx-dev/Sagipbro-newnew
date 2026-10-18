<?php
$pageTitle = 'Services';
$pageDescription = 'Explore the disaster-relief information services presented by SAGIPBRO for Barangay Bonuan Binloc, Dagupan City.';
$activePage = 'services';
$basePath = '';
$bodyClass = 'services-page';

require_once __DIR__ . '/includes/public_service_helpers.php';
require_once __DIR__ . '/includes/public_announcements_panel.php';
$announcementPanel = publicAnnouncementPanelLoad();

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <section class="page-hero services-hero" aria-labelledby="services-page-title">
        <div class="container">
            <nav aria-label="Breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Services</li>
                </ol>
            </nav>
            <span class="hero-chip"><span aria-hidden="true"></span> Barangay Bonuan Binloc</span>
            <h1 id="services-page-title">Disaster Relief Services</h1>
            <p>Access essential relief resources, evacuation centers, distribution updates, and public reports in Barangay Bonuan Binloc.</p>
        </div>
    </section>

    <?php publicServiceNavigation(''); ?>
    <section class="section-space section-soft" id="services-list" data-scroll-target aria-label="Public services">
        <div class="container public-services-layout">
            <div class="public-services-main">
            <div class="service-list">
                <article class="service-row">
                    <a class="service-row-link" href="resources.php#resource-directory">
                        <span class="service-row-top">
                            <span class="service-row-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></span>
                        </span>
                        <div class="service-row-copy">
                            <h3>Relief Resources</h3>
                            <p>Organizes food, water, hygiene, medical, and shelter supplies by available quantity, unit, category, and stock condition.</p>
                        </div>
                        <span class="quick-link">Browse available supplies <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </a>
                </article>
                <article class="service-row" id="evacuation-centers">
                    <a class="service-row-link" href="evacuation-centers.php#center-directory">
                        <span class="service-row-top">
                            <span class="service-row-icon"><i class="bi bi-buildings" aria-hidden="true"></i></span>
                        </span>
                        <div class="service-row-copy">
                            <h3>Evacuation Centers</h3>
                            <p>Presents center locations, capacity, current occupancy, and availability so options can be reviewed quickly.</p>
                        </div>
                        <span class="quick-link">Check center availability <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </a>
                </article>
                <article class="service-row" id="relief-distribution">
                    <a class="service-row-link" href="distributions.php#distribution-directory">
                        <span class="service-row-top">
                            <span class="service-row-icon"><i class="bi bi-truck" aria-hidden="true"></i></span>
                        </span>
                        <div class="service-row-copy">
                            <h3>Relief Distribution</h3>
                            <p>Find published schedules, locations, active distributions, planned supplies, and totals already distributed. Recipient details remain private.</p>
                        </div>
                        <span class="quick-link">View distributions <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </a>
                </article>
                <article class="service-row">
                    <a class="service-row-link" href="reports.php#public-reports">
                        <span class="service-row-top">
                            <span class="service-row-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></span>
                        </span>
                        <div class="service-row-copy">
                            <h3>Reports</h3>
                            <p>Review public resource availability, open evacuation capacity, and recent relief distributions from barangay records.</p>
                        </div>
                        <span class="quick-link">Open public reports <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </a>
                </article>
            </div>
            </div>
            <?php publicAnnouncementsPanel($announcementPanel); ?>
        </div>
    </section>

    <section class="pb-5" aria-labelledby="services-cta-title">
        <div class="container">
            <div class="cta-panel">
                <div>
                    <h2 id="services-cta-title">Check the latest recorded availability</h2>
                    <p>Browse supplies from the barangay database, or contact the barangay hall for assistance and confirmation.</p>
                </div>
                <div class="cta-actions">
                    <a class="btn btn-white" href="resources.php#resource-directory"><i class="bi bi-search" aria-hidden="true"></i> Browse resources</a>
                    <a class="btn btn-ghost-light" href="contact.php#contact-details">Contact information</a>
                </div>
            </div>
        </div>
    </section>
</main>
<script src="assets/js/public-announcements.js?v=3" defer></script>
<?php include __DIR__ . '/includes/footer.php'; ?>
