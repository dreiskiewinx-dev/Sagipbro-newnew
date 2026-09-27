<?php
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/connection.php';
$contactError = '';
$contactSent = !empty($_SESSION['contact_sent']);
unset($_SESSION['contact_sent']);
$contactSitios = ['Japan', 'China', 'America', 'Palatong', 'Bliss', 'Korea', 'Russia'];
$contactValues = array_fill_keys(['name', 'email', 'phone', 'sitio', 'message'], '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    foreach ($contactValues as $field => $value) {
        $contactValues[$field] = is_string($_POST[$field] ?? null) ? trim($_POST[$field]) : '';
    }
    $limits = ['name' => 120, 'email' => 160, 'phone' => 30, 'sitio' => 100, 'message' => 1500];
    foreach ($limits as $field => $limit) {
        if (($field !== 'phone' && $contactValues[$field] === '') || mb_strlen($contactValues[$field]) > $limit) {
            $contactError = 'Please complete the required fields within the allowed lengths.';
        }
    }
    if (!filter_var($contactValues['email'], FILTER_VALIDATE_EMAIL) || !in_array($contactValues['sitio'], $contactSitios, true)) {
        $contactError = 'Please enter a valid email address and select a sitio.';
    }
    if ($contactError === '') {
        try {
            $statement = sagipbroDatabase()->prepare("INSERT INTO contact_messages (name, email, phone, sitio, message, subject) VALUES (?, ?, ?, ?, ?, 'General inquiry')");
            $statement->execute(array_values($contactValues));
            $_SESSION['contact_sent'] = true;
            header('Location: contact.php#contactFormNotice');
            exit;
        } catch (Throwable $e) {
            error_log('Contact submission failed (' . get_class($e) . ').');
            $contactError = 'Your message could not be sent. Please try again or use the contact details listed here.';
        }
    }
}
$pageTitle = 'Contact';
$pageDescription = 'Find Barangay Bonuan Binloc contact details and send a message to SAGIPBRO administrators.';
$activePage = 'contact';
$basePath = '';

include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<main id="main-content">
    <section class="page-hero photo-hero contact-hero" aria-labelledby="contact-page-title">
        <div class="container">
            <nav aria-label="Breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Contact</li>
                </ol>
            </nav>
            <span class="hero-chip"><span aria-hidden="true"></span> Contact and assistance</span>
            <h1 id="contact-page-title">Reach the right help, without guesswork.</h1>
            <p>Find the barangay address, hall hotlines, email, and clearly labeled Dagupan City contact channels. For immediate threats to life or safety, call 911.</p>
        </div>
    </section>

    <section class="section-space section-soft" aria-labelledby="contact-details-title">
        <div class="container">
            <div class="contact-layout">
                <aside class="contact-panel" aria-labelledby="contact-details-title">
                    <div class="contact-panel-body">
                        <span class="eyebrow text-white">Contact details</span>
                        <h2 id="contact-details-title">Barangay Bonuan Binloc</h2>
                        <p>Use these details to choose the most appropriate channel for your concern.</p>
                        <ul class="contact-list">
                            <li>
                                <i class="bi bi-geo-alt" aria-hidden="true"></i>
                                <div>
                                    <strong>Barangay address</strong>
                                    <span>Bonuan Binloc, Dagupan City, Pangasinan 2400</span>
                                </div>
                            </li>
                            <li>
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                <div>
                                    <strong>Barangay hall hotlines</strong>
                                    <a href="tel:+639631743346">+63 963 174 3346</a><br>
                                    <a href="tel:+639632173031">+63 963 217 3031</a>
                                </div>
                            </li>
                            <li>
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                <div>
                                    <strong>Barangay email</strong>
                                    <a href="mailto:barangaybonuabbinloc@gmail.com">barangaybonuabbinloc@gmail.com</a>
                                </div>
                            </li>
                            <li>
                                <i class="bi bi-telephone" aria-hidden="true"></i>
                                <div>
                                    <strong>Dagupan City CDRRMO mobile</strong>
                                    <a href="tel:+639684449598">+63 968 444 9598</a>
                                </div>
                            </li>
                            <li>
                                <i class="bi bi-telephone-forward" aria-hidden="true"></i>
                                <div>
                                    <strong>Dagupan City CDRRMO landline</strong>
                                    <a href="tel:+63755400363">+63 75 540 0363</a>
                                </div>
                            </li>
                            <li>
                                <i class="bi bi-envelope" aria-hidden="true"></i>
                                <div>
                                    <strong>Dagupan City general email</strong>
                                    <a href="mailto:dagupanlgu@gmail.com">dagupanlgu@gmail.com</a>
                                </div>
                            </li>
                        </ul>
                    </div>
                    <div class="contact-hours">
                        <strong>Barangay and city contacts</strong>
                        <span>Use the barangay hall hotlines and email for local inquiries. Dagupan City contact channels are listed separately for citywide concerns and disaster coordination.</span>
                        <a class="d-inline-block mt-2 text-white small" href="https://www.dagupan.gov.ph/directories/" target="_blank" rel="noopener noreferrer">View the official city directory <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></a>
                    </div>
                </aside>

                <div class="form-card">
                    <h2>Send a message</h2>
                    <p>Send your inquiry to the barangay administrators through their SAGIPBRO Messages inbox.</p>

                    <div class="alert alert-info app-alert" id="contactFormNotice" role="note">
                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                        <div>
                            <strong><?= $contactSent ? 'Message sent' : ($contactError !== '' ? 'Message not sent' : 'Contact the barangay') ?></strong>
                            <span><?= htmlspecialchars($contactSent ? 'Your message has been saved to the administrators’ Messages inbox.' : ($contactError ?: 'Complete the form below to send your inquiry. For immediate emergencies, call 911.'), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>

                    <form action="contact.php#contactFormNotice" method="post" aria-describedby="contactFormNotice">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label" for="contactName">Full name <span class="required-mark" aria-hidden="true">*</span></label>
                                <input class="form-control" id="contactName" name="name" type="text" autocomplete="name" maxlength="120" required aria-required="true">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="contactEmail">Email address <span class="required-mark" aria-hidden="true">*</span></label>
                                <input class="form-control" id="contactEmail" name="email" type="email" autocomplete="email" maxlength="160" required aria-required="true">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="contactPhone">Contact number</label>
                                <input class="form-control" id="contactPhone" name="phone" type="tel" autocomplete="tel" maxlength="30" inputmode="tel" placeholder="09XX XXX XXXX">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label" for="contactSitio">Sitio <span class="required-mark" aria-hidden="true">*</span></label>
                                <select class="form-select" id="contactSitio" name="sitio" required aria-required="true">
                                    <option value="" <?= $contactValues['sitio'] === '' ? 'selected' : '' ?> disabled>Select a sitio</option>
                                    <?php foreach ($contactSitios as $sitio): ?>
                                    <option value="<?= htmlspecialchars($sitio, ENT_QUOTES, 'UTF-8') ?>" <?= $contactValues['sitio'] === $sitio ? 'selected' : '' ?>><?= htmlspecialchars($sitio, ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label" for="contactMessage">Message <span class="required-mark" aria-hidden="true">*</span></label>
                                <textarea class="form-control" id="contactMessage" name="message" rows="5" maxlength="1500" required aria-required="true" placeholder="Describe your question or concern."></textarea>
                                <div class="form-text">Do not include passwords, financial details, or other sensitive personal information.</div>
                            </div>
                            <div class="col-12 d-flex flex-column flex-sm-row align-items-sm-center justify-content-between gap-3">
                                <small class="text-secondary"><span class="required-mark" aria-hidden="true">*</span> Required fields</small>
                                <button class="btn btn-brand" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Send message</button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </section>

</main>
<?php include __DIR__ . '/includes/footer.php'; ?>
