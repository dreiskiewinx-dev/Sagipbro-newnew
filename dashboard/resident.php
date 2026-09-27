<?php
require_once __DIR__ . '/../config/session.php';
requireRole(['resident']);
require_once __DIR__ . '/../config/database.php';

$resident = null;
$householdMembers = 0;
$accountName = trim((string) ($_SESSION['full_name'] ?? ''));

if ($accountName !== '') {
    $residentStatement = $conn->prepare(
        "SELECT r.*, h.household_no, h.address AS household_address, h.barangay
         FROM residents r
         LEFT JOIN households h ON h.id = r.household_id
         WHERE CONCAT_WS(' ', r.first_name, r.last_name) = ?
         ORDER BY (r.status = 'Active') DESC, r.updated_at DESC
         LIMIT 1"
    );
    $residentStatement->execute([$accountName]);
    $resident = $residentStatement->fetch() ?: null;

    if ($resident && !empty($resident['household_id'])) {
        $memberStatement = $conn->prepare('SELECT COUNT(*) AS total FROM residents WHERE household_id = ? AND status = \'Active\'');
        $memberStatement->execute([(int) $resident['household_id']]);
        $householdMembers = (int) $memberStatement->fetchColumn();
    }
}

$pageTitle = 'Resident portal';
$pageDescription = 'SAGIPBRO resident information portal.';
$basePath = '../';
$activePage = '';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>
<main id="main-content">
    <section class="page-hero" aria-labelledby="resident-title">
        <div class="container">
            <span class="hero-chip"><span aria-hidden="true"></span> Resident portal</span>
            <h1 id="resident-title">Welcome, <?= htmlspecialchars($_SESSION['full_name'] ?? 'Resident', ENT_QUOTES, 'UTF-8') ?>.</h1>
            <p>Review trusted community guidance, available resources, and the household information linked to your account.</p>
        </div>
    </section>
    <section class="section-space section-soft">
        <div class="container">
            <?php require __DIR__ . '/../includes/alerts.php'; ?>
            <div class="quick-grid mb-4">
                <a class="quick-card" href="../resources.php#resource-directory"><span class="quick-icon"><i class="bi bi-box-seam"></i></span><h3>Available resources</h3><p>View the latest public relief supply information and stock status.</p><span class="quick-link">Browse resources <i class="bi bi-arrow-right"></i></span></a>
                <a class="quick-card" href="../evacuation-centers.php#center-directory"><span class="quick-icon"><i class="bi bi-buildings"></i></span><h3>Evacuation information</h3><p>Review center capacity, availability, and preparedness information.</p><span class="quick-link">View centers <i class="bi bi-arrow-right"></i></span></a>
                <a class="quick-card" href="../contact.php#contact-details"><span class="quick-icon"><i class="bi bi-telephone"></i></span><h3>Emergency contacts</h3><p>Save the verified Dagupan City response numbers before you need them.</p><span class="quick-link">Contact directory <i class="bi bi-arrow-right"></i></span></a>
            </div>
            <div class="contact-layout">
                <section class="surface-card" aria-labelledby="household-title">
                    <div class="surface-card-header"><div><h2 id="household-title">Household profile</h2><p>Information associated with your resident account</p></div><span class="status-badge <?= $resident ? 'status-success' : 'status-neutral' ?>"><?= $resident ? 'Linked' : 'Not linked' ?></span></div>
                    <div class="surface-card-body">
                        <?php if ($resident): ?>
                        <dl class="row mb-0 small">
                            <dt class="col-sm-4 text-secondary mb-2">Household number</dt><dd class="col-sm-8 mb-2"><?= htmlspecialchars((string) ($resident['household_no'] ?: 'Not assigned'), ENT_QUOTES, 'UTF-8') ?></dd>
                            <dt class="col-sm-4 text-secondary mb-2">Area</dt><dd class="col-sm-8 mb-2"><?= htmlspecialchars((string) ($resident['household_address'] ?: $resident['address'] ?: 'Not recorded'), ENT_QUOTES, 'UTF-8') ?><?= !empty($resident['barangay']) ? ', ' . htmlspecialchars((string) $resident['barangay'], ENT_QUOTES, 'UTF-8') : '' ?></dd>
                            <dt class="col-sm-4 text-secondary mb-2">Household members</dt><dd class="col-sm-8 mb-2"><?= $householdMembers ?> registered member<?= $householdMembers === 1 ? '' : 's' ?></dd>
                            <dt class="col-sm-4 text-secondary">Last reviewed</dt><dd class="col-sm-8"><?= htmlspecialchars(date('F j, Y', strtotime((string) $resident['updated_at'])), ENT_QUOTES, 'UTF-8') ?></dd>
                        </dl>
                        <?php else: ?>
                        <div class="empty-state py-4"><i class="bi bi-person-exclamation"></i><h3>No resident record linked</h3><p>Your account name does not currently match a resident record. Contact the barangay office to link or update it.</p></div>
                        <?php endif; ?>
                    </div>
                    <div class="surface-card-footer"><a class="btn btn-sm btn-outline-brand" href="../contact.php#send-message">Request an information update</a></div>
                </section>
                <aside class="center-feature"><span class="feature-icon"><i class="bi bi-backpack"></i></span><h2>Is your family go-bag ready?</h2><p>Include drinking water, food, medicines, a flashlight, radio, clothing, hygiene supplies, and copies of important documents.</p><a class="btn btn-ghost-light w-100 mt-3" href="../services.php#services-list">Review preparedness services</a></aside>
            </div>
            <form class="mt-4 text-end" action="../actions/auth/logout.php" method="post" data-confirm-logout><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-outline-brand" type="submit"><i class="bi bi-box-arrow-left"></i> Sign out</button></form>
        </div>
    </section>
</main>
<?php require __DIR__ . '/../includes/footer.php'; ?>
