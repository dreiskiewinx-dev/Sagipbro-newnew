<?php
require_once '../../includes/auth_check.php';
requireRole(['admin', 'official']);

$pageTitle = 'Volunteers';
$pageDescription = 'Coordinate trained disaster response volunteers in Barangay Binloc.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'volunteers';

$volunteers = [];

include '../../includes/header.php';
?>
<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>
        <main class="admin-content" id="main-content">
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="../../dashboard/admin.php">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Volunteers</li></ol></nav>
                    <h1>Volunteer coordination</h1>
                    <p>Track response skills, assignments, availability, and current deployment status.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-outline-brand" type="button" data-export="#volunteersTable" data-export-name="volunteer-roster"><i class="bi bi-download" aria-hidden="true"></i> Export roster</button>
                    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#addVolunteerModal"><i class="bi bi-person-plus-fill" aria-hidden="true"></i> Add volunteer</button>
                </div>
            </header>

            <section class="stat-grid" aria-label="Volunteer overview">
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Registered volunteers</span><span class="stat-card-icon"><i class="bi bi-person-hearts" aria-hidden="true"></i></span></div><strong class="stat-value">0</strong><span class="stat-meta">No volunteer records yet</span></article>
                <article class="stat-card info"><div class="stat-card-top"><span class="stat-card-label">Ready for deployment</span><span class="stat-card-icon"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span></div><strong class="stat-value">0</strong><span class="stat-meta">No available volunteers yet</span></article>
                <article class="stat-card warning"><div class="stat-card-top"><span class="stat-card-label">Currently deployed</span><span class="stat-card-icon"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i></span></div><strong class="stat-value">0</strong><span class="stat-meta">No active assignments yet</span></article>
                <article class="stat-card"><div class="stat-card-top"><span class="stat-card-label">Operationally available</span><span class="stat-card-icon"><i class="bi bi-award-fill" aria-hidden="true"></i></span></div><strong class="stat-value">0%</strong><span class="stat-meta">Active or deployed roster share</span></article>
            </section>

            <div class="info-callout mb-3" role="note">
                <i class="bi bi-shield-check" aria-hidden="true"></i>
                <div><strong>Response readiness check</strong><span>Keep each volunteer's availability, assignment, skills, and deployment status current.</span></div>
            </div>

            <section aria-labelledby="volunteerRosterHeading">
                <div class="filter-toolbar">
                    <div class="search-field">
                        <label for="volunteerSearch">Search volunteers</label>
                        <div class="input-icon"><i class="bi bi-search" aria-hidden="true"></i><input class="form-control" id="volunteerSearch" type="search" placeholder="Name, skill, assignment or ID" autocomplete="off" data-table-search="#volunteersTable"></div>
                    </div>
                    <div class="filter-field">
                        <label for="volunteerStatusFilter">Deployment status</label>
                        <select class="form-select" id="volunteerStatusFilter" data-filter-select="#volunteersTable" data-filter-field="status">
                            <option value="">All statuses</option><option value="Active">Active</option><option value="Deployed">Deployed</option><option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <span class="filter-results" aria-live="polite">Showing 0 volunteer records</span>
                </div>

                <div class="data-card">
                    <div class="data-card-header"><div><h2 id="volunteerRosterHeading">Volunteer roster</h2><p>Verified personnel available for preparedness and disaster response assignments.</p></div><span class="status-badge status-success">Roster verified</span></div>
                    <div class="table-responsive">
                        <table class="table app-table" id="volunteersTable">
                            <caption class="visually-hidden">Barangay Binloc disaster response volunteer roster</caption>
                            <thead><tr><th scope="col">Volunteer</th><th scope="col">Skills</th><th scope="col">Current assignment</th><th scope="col">Contact</th><th scope="col">Availability</th><th scope="col">Status</th><th scope="col" class="text-end">Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($volunteers as $volunteer): ?>
                                    <?php $statusClass = $volunteer[7] === 'Active' ? 'status-success' : ($volunteer[7] === 'Deployed' ? 'status-info' : 'status-neutral'); ?>
                                    <tr data-row data-status="<?= htmlspecialchars($volunteer[7], ENT_QUOTES, 'UTF-8') ?>">
                                        <td><span class="table-avatar" aria-hidden="true"><?= htmlspecialchars($volunteer[2], ENT_QUOTES, 'UTF-8') ?></span><span class="d-inline-block align-middle"><span class="table-primary-text"><?= htmlspecialchars($volunteer[1], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($volunteer[0], ENT_QUOTES, 'UTF-8') ?></span></span></td>
                                        <td><?= htmlspecialchars($volunteer[3], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="table-primary-text"><?= htmlspecialchars($volunteer[4], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><?= htmlspecialchars($volunteer[5], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($volunteer[6], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="status-badge <?= $statusClass ?>"><?= htmlspecialchars($volunteer[7], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="text-end"><div class="table-actions" role="group" aria-label="Actions for <?= htmlspecialchars($volunteer[1], ENT_QUOTES, 'UTF-8') ?>"><button class="btn btn-light btn-icon" type="button" title="View volunteer" aria-label="View <?= htmlspecialchars($volunteer[1], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#viewVolunteerModal"><i class="bi bi-eye" aria-hidden="true"></i></button><button class="btn btn-light btn-icon" type="button" title="Edit volunteer" aria-label="Edit <?= htmlspecialchars($volunteer[1], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#editVolunteerModal"><i class="bi bi-pencil" aria-hidden="true"></i></button></div></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="data-card-footer record-summary"><span>Showing 0 of 0 volunteers</span><span>No volunteer records yet</span></div>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal fade" id="addVolunteerModal" tabindex="-1" aria-labelledby="addVolunteerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
                        <form id="addVolunteerForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-header"><div><h2 class="modal-title" id="addVolunteerModalLabel">Add volunteer</h2><p class="mb-0 mt-1 small text-body-secondary">Record contact details, response skills, and availability.</p></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><div class="row g-3">
                <div class="col-md-6"><label class="form-label" for="volunteerName">Full name <span class="required-mark">*</span></label><input class="form-control" id="volunteerName" name="full_name" required autocomplete="name"></div>
                <div class="col-md-6"><label class="form-label" for="volunteerContact">Contact number <span class="required-mark">*</span></label><input class="form-control" id="volunteerContact" name="contact" type="tel" required autocomplete="tel" placeholder="09XX XXX XXXX"></div>
                <div class="col-md-6"><label class="form-label" for="volunteerEmail">Email address</label><input class="form-control" id="volunteerEmail" name="email" type="email" autocomplete="email"></div>
                <div class="col-md-6"><label class="form-label" for="volunteerAvailability">Availability <span class="required-mark">*</span></label><select class="form-select" id="volunteerAvailability" name="availability" required><option value="">Select availability</option><option>24/7 response</option><option>Weekdays</option><option>Weekends</option><option>Evenings</option><option>On call</option></select></div>
                <div class="col-md-6"><label class="form-label" for="volunteerSkills">Primary skills <span class="required-mark">*</span></label><input class="form-control" id="volunteerSkills" name="skills" required placeholder="e.g., First aid, radio communications"></div>
                <div class="col-md-6"><label class="form-label" for="volunteerAssignment">Team assignment</label><select class="form-select" id="volunteerAssignment" name="assignment"><option>Unassigned reserve</option><option>Medical team</option><option>Search and rescue</option><option>Relief warehouse</option><option>Transport unit</option><option>Family support desk</option><option>Community kitchen</option></select></div>
                <div class="col-12"><label class="form-label" for="volunteerNotes">Certifications and notes</label><textarea class="form-control" id="volunteerNotes" name="notes" rows="3" placeholder="Training completed, certificate dates, or operational restrictions"></textarea></div>
            </div></div>
            <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Save volunteer</button></div>
        </form>
    </div></div>
</div>

<div class="modal fade" id="viewVolunteerModal" tabindex="-1" aria-labelledby="viewVolunteerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title" id="viewVolunteerModalLabel">Volunteer profile</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div class="d-flex align-items-center gap-3 mb-4"><span class="profile-avatar-large m-0" aria-hidden="true">--</span><div><h3 class="h5 mb-1">No volunteer selected</h3><span class="status-badge status-neutral">No records</span></div></div>
            <dl class="row mb-0 small"><dt class="col-5 text-body-secondary">Volunteer ID</dt><dd class="col-7">Not available</dd><dt class="col-5 text-body-secondary">Skills</dt><dd class="col-7">Not available</dd><dt class="col-5 text-body-secondary">Assignment</dt><dd class="col-7">Not available</dd><dt class="col-5 text-body-secondary">Availability</dt><dd class="col-7">Not available</dd><dt class="col-5 text-body-secondary">Contact</dt><dd class="col-7">Not available</dd><dt class="col-5 text-body-secondary">Last briefing</dt><dd class="col-7 mb-0">Not available</dd></dl>
        </div>
        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button><button class="btn btn-brand" type="button" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editVolunteerModal"><i class="bi bi-pencil" aria-hidden="true"></i> Edit profile</button></div>
    </div></div>
</div>

<div class="modal fade" id="editVolunteerModal" tabindex="-1" aria-labelledby="editVolunteerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                        <form id="editVolunteerForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div class="modal-header"><h2 class="modal-title" id="editVolunteerModalLabel">Edit volunteer</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><p class="modal-intro">Update the selected volunteer's readiness details.</p><div class="mb-3"><label class="form-label" for="editVolunteerAssignment">Team assignment</label><select class="form-select" id="editVolunteerAssignment" name="assignment"><option>Unassigned reserve</option><option>Medical team</option><option>Search and rescue</option><option>Relief warehouse</option><option>Transport unit</option><option>Family support desk</option><option>Community kitchen</option></select></div><div class="mb-3"><label class="form-label" for="editVolunteerAvailability">Availability</label><select class="form-select" id="editVolunteerAvailability" name="availability"><option>24/7 response</option><option>Weekdays</option><option>Weekends</option><option>Evenings</option><option>On call</option></select></div><div><label class="form-label" for="editVolunteerStatus">Status</label><select class="form-select" id="editVolunteerStatus" name="status"><option>Active</option><option>Deployed</option><option>Inactive</option></select></div></div>
            <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit">Save changes</button></div>
        </form>
    </div></div>
</div>
<script>
    window.sagipbroVolunteerApi = { endpoint: <?= json_encode(appUrl('api/volunteers.php')) ?>, csrfToken: <?= json_encode(csrfToken()) ?> };
</script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/volunteers-api.js"></script>
<?php include '../../includes/footer.php'; ?>
