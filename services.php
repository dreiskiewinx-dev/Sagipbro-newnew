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
                    <div class="service-row-icon"><i class="bi bi-box-seam" aria-hidden="true"></i></div>
                    <div>
                        <h2><a class="stretched-link" href="resources.php#resource-directory">Relief Resources</a></h2>
                        <p>Organizes food, water, hygiene, medical, and shelter supplies by available quantity, unit, category, and stock condition.</p>
                        <span class="quick-link">Browse available supplies <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </div>
                    <span class="service-number" aria-hidden="true">01</span>
                </article>
                <article class="service-row" id="evacuation-centers">
                    <div class="service-row-icon"><i class="bi bi-buildings" aria-hidden="true"></i></div>
                    <div>
                        <h2><a class="stretched-link" href="evacuation-centers.php#center-directory">Evacuation Centers</a></h2>
                        <p>Presents center locations, capacity, current occupancy, and availability so options can be reviewed quickly.</p>
                        <span class="quick-link">Check center availability <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </div>
                    <span class="service-number" aria-hidden="true">02</span>
                </article>
                <article class="service-row" id="relief-distribution">
                    <div class="service-row-icon"><i class="bi bi-truck" aria-hidden="true"></i></div>
                    <div>
                        <h2><a class="stretched-link" href="distributions.php#distribution-directory">Relief Distribution</a></h2>
                        <p>Find published schedules, locations, active distributions, planned supplies, and totals already distributed. Recipient details remain private.</p>
                        <span class="quick-link">View distributions <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </div>
                    <span class="service-number" aria-hidden="true">03</span>
                </article>
                <article class="service-row" id="announcements">
                    <div class="service-row-icon"><i class="bi bi-megaphone" aria-hidden="true"></i></div>
                    <div>
                        <h2><a class="stretched-link" href="announcements.php#announcement-directory">Emergency Announcements</a></h2>
                        <p>Gives urgent advisories and community updates a prominent, readable location across the public experience.</p>
                        <span class="quick-link">Read current announcements <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </div>
                    <span class="service-number" aria-hidden="true">04</span>
                </article>
                <article class="service-row">
                    <div class="service-row-icon"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></div>
                    <div>
                        <h2><a class="stretched-link" href="reports.php#public-reports">Reports</a></h2>
                        <p>Review public resource availability, open evacuation capacity, and recent relief distributions from barangay records.</p>
                        <span class="quick-link">Open public reports <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </div>
                    <span class="service-number" aria-hidden="true">05</span>
                </article>
            </div>
        </div>
    </section>

    <section class="section-space" aria-labelledby="service-process-title">
        <div class="container">
            <div class="section-heading text-center">
                <span class="section-kicker">Information flow</span>
                <h2 id="service-process-title">Simple by design</h2>
                <p>The interface keeps the path from information entry to community understanding short and consistent.</p>
            </div>
            <div class="process-grid">
                <article class="process-step">
                    <h3>Record</h3>
                    <p>Authorized users enter or update operational information using clear forms and familiar labels.</p>
                </article>
                <article class="process-step">
                    <h3>Review</h3>
                    <p>Search, filters, stock cues, and summaries make important conditions easier to notice.</p>
                </article>
                <article class="process-step">
                    <h3>Communicate</h3>
                    <p>Approved public information can be presented in an accessible format for community reference.</p>
                </article>
            </div>
        </div>
    </section>

    <section class="section-space section-soft" aria-labelledby="service-principles-title">
        <div class="container">
            <div class="section-heading">
                <span class="section-kicker">Service principles</span>
                <h2 id="service-principles-title">Made to stay useful under pressure</h2>
            </div>
            <div class="icon-card-grid">
                <article class="icon-card">
                    <div class="icon-box"><i class="bi bi-eye" aria-hidden="true"></i></div>
                    <h3>Readable</h3>
                    <p>Plain language, strong contrast, and recognizable status labels reduce guesswork.</p>
                </article>
                <article class="icon-card">
                    <div class="icon-box"><i class="bi bi-phone" aria-hidden="true"></i></div>
                    <h3>Responsive</h3>
                    <p>Information remains usable across desktop, tablet, and mobile screen sizes.</p>
                </article>
                <article class="icon-card">
                    <div class="icon-box"><i class="bi bi-shield-check" aria-hidden="true"></i></div>
                    <h3>Role-aware</h3>
                    <p>Public views focus on community information while staff tools support authorized workflows.</p>
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
