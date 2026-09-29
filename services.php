<?php
$pageTitle = 'Services';
$pageDescription = 'Explore the disaster-relief information services presented by SAGIPBRO for Barangay Bonuan Binloc, Dagupan City.';
$activePage = 'services';
$basePath = '';

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
            <span class="hero-chip"><span aria-hidden="true"></span> What SAGIPBRO provides</span>
            <h1 id="services-page-title">Essential relief information, organized around real decisions.</h1>
            <p>From supply visibility to reports, SAGIPBRO brings the main information areas of barangay disaster-relief coordination into one consistent experience.</p>
        </div>
    </section>

    <section class="section-space section-soft" id="services-list" data-scroll-target aria-labelledby="services-list-title">
        <div class="container">
            <div class="section-heading">
                <span class="section-kicker">Core services</span>
                <h2 id="services-list-title">A clearer view of every response area</h2>
                <p>Public information stays easy to read, while authorized staff can work with more detailed operational records after signing in.</p>
            </div>

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
                <article class="service-row" id="announcements">
                    <a class="service-row-link" href="announcements.php#announcement-directory">
                        <span class="service-row-top">
                            <span class="service-row-icon"><i class="bi bi-megaphone" aria-hidden="true"></i></span>
                        </span>
                        <div class="service-row-copy">
                            <h3>Emergency Announcements</h3>
                            <p>Gives urgent advisories and community updates a prominent, readable location across the public experience.</p>
                        </div>
                        <span class="quick-link">Read current announcements <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
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
<?php include __DIR__ . '/includes/footer.php'; ?>
