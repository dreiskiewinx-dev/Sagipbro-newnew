<?php
$pageTitle = 'About Barangay Binloc';
$pageDescription = 'Get to know Barangay Binloc, Dagupan City, Pangasinan, and how SAGIPBRO supports community preparedness and disaster-relief information.';
$activePage = 'about';
$basePath = '';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content" class="about-page">
    <section class="page-hero about-hero" aria-labelledby="about-page-title">
        <div class="container">
            <nav aria-label="Breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">About</li>
                </ol>
            </nav>
            <span class="hero-chip"><span aria-hidden="true"></span> Our barangay, our community</span>
            <h1 id="about-page-title">Barangay Binloc.<br>Prepared together.</h1>
            <p>Serving Barangay Binloc, Dagupan City, Pangasinan with clear information about relief supplies, evacuation centers, and community advisories.</p>
        </div>
    </section>

    <section class="section-space" id="about-barangay" data-scroll-target aria-labelledby="about-barangay-title">
        <div class="container">
            <div class="row g-3 g-lg-4 align-items-center">
                <div class="col-lg-6">
                    <span class="section-kicker">The community we serve</span>
                    <h2 id="about-barangay-title">About Barangay Binloc</h2>
                    <p class="mt-3">Barangay Binloc is a community in Dagupan City, Pangasinan. It is the community at the heart of SAGIPBRO and the focus of the system’s disaster-relief information.</p>
                    <p>For residents and families, finding clear information matters before, during, and after a disaster. SAGIPBRO brings local relief and preparedness updates into one place that is easy to access.</p>
                    <a class="btn btn-outline-brand mt-2" href="contact.php#send-message"><i class="bi bi-telephone" aria-hidden="true"></i> Contact the barangay</a>
                </div>
                <div class="col-lg-6">
                    <figure class="about-community-photo">
                        <img class="about-community-image" src="assets/images/binloc-barangay-hall-dagupan.png" alt="Bonuan Binloc Barangay Hall exterior with City of Dagupan signage" width="1672" height="941" loading="lazy" decoding="async">
                        <figcaption>Bonuan Binloc Barangay Hall, City of Dagupan, Pangasinan</figcaption>
                    </figure>
                </div>
            </div>
        </div>
    </section>

    <div class="section-space section-soft">
        <div class="container">
            <div class="row g-3">
                <section class="col-lg-5" aria-labelledby="barangay-location-title">
                    <div class="icon-card about-detail-card h-100">
                        <div class="icon-box"><i class="bi bi-geo-alt" aria-hidden="true"></i></div>
                        <h2 id="barangay-location-title">Location</h2>
                        <p>Barangay Binloc, Dagupan City, Pangasinan</p>
                        <dl class="about-location-details">
                            <div><dt>Barangay</dt><dd>Binloc</dd></div>
                            <div><dt>City</dt><dd>Dagupan City</dd></div>
                            <div><dt>Province</dt><dd>Pangasinan</dd></div>
                        </dl>
                        <a href="evacuation-centers.php#center-directory">Find local evacuation centers <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </section>
                <section class="col-lg-7" aria-labelledby="our-community-title">
                    <div class="icon-card about-detail-card h-100">
                        <div class="icon-box"><i class="bi bi-people" aria-hidden="true"></i></div>
                        <h2 id="our-community-title">Our Community</h2>
                        <p>Residents, families, volunteers, and barangay personnel all have a part in community preparedness. Sharing reliable updates and looking out for one another can help people find assistance when it is needed.</p>
                        <p class="mt-3">SAGIPBRO is designed around these everyday needs: knowing what supplies are available, where evacuation centers are located, and which announcements need attention.</p>
                        <div class="about-community-note"><i class="bi bi-house-heart" aria-hidden="true"></i><span>A prepared community starts with informed households and neighbors who support one another.</span></div>
                    </div>
                </section>
            </div>
        </div>
    </div>

    <section class="section-space section-soft" aria-labelledby="why-sagipbro-title">
        <div class="container">
            <div class="section-heading">
                <span class="section-kicker">Built for Barangay Binloc</span>
                <h2 id="why-sagipbro-title">Why SAGIPBRO?</h2>
                <p>SAGIPBRO — Disaster Relief Resource Information System — helps Barangay Binloc organize and share the information residents need for preparedness and relief assistance.</p>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <article class="icon-card about-service-card h-100">
                        <div class="icon-box"><i class="bi bi-box-seam" aria-hidden="true"></i></div>
                        <h3>Relief resources</h3>
                        <p>See recorded supplies, quantities, and stock conditions so it is easier to understand what assistance may be available.</p>
                        <a href="resources.php#resource-directory">View relief resources <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </article>
                </div>
                <div class="col-md-6">
                    <article class="icon-card about-service-card h-100">
                        <div class="icon-box"><i class="bi bi-buildings" aria-hidden="true"></i></div>
                        <h3>Evacuation centers</h3>
                        <p>Find center locations, capacity, current occupants, and available spaces in one place.</p>
                        <a href="evacuation-centers.php#center-directory">View evacuation centers <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </article>
                </div>
                <div class="col-md-6">
                    <article class="icon-card about-service-card h-100">
                        <div class="icon-box"><i class="bi bi-megaphone" aria-hidden="true"></i></div>
                        <h3>Community announcements</h3>
                        <p>Read published advisories and urgent notices to keep up with information that affects the barangay.</p>
                        <a href="announcements.php#announcement-directory">Read announcements <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </article>
                </div>
                <div class="col-md-6">
                    <article class="icon-card about-service-card h-100">
                        <div class="icon-box"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></div>
                        <h3>Disaster and relief information</h3>
                        <p>Review resource and evacuation summaries alongside recent relief distribution information to support local planning.</p>
                        <a href="reports.php#public-reports">View public information <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    </article>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-3 mt-4">
                <a class="btn btn-brand" href="services.php#services-list">Explore all services <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                <a class="btn btn-outline-brand" href="distributions.php#distribution-directory">View distribution schedules</a>
            </div>
        </div>
    </section>
</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
