<?php
require_once '../../includes/auth_check.php';
requireRole(['admin', 'official']);

$pageTitle = 'Residents';
$pageDescription = 'Manage resident and household records for Barangay Binloc.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'residents';
$residents = [];

include '../../includes/header.php';
?>
<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>
        <main class="admin-content" id="main-content">
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="../../dashboard/admin.php">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Residents</li>
                        </ol>
                    </nav>
                    <h1>Resident records</h1>
                    <p>Maintain reliable household information for relief assessment and response planning.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-outline-brand" type="button" data-export="#residentsTable" data-export-name="resident-directory">
                        <i class="bi bi-download" aria-hidden="true"></i> Export list
                    </button>
                    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#addResidentModal">
                        <i class="bi bi-person-plus-fill" aria-hidden="true"></i> Add resident
                    </button>
                </div>
            </header>

            <section class="stat-grid" aria-label="Resident overview">
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-card-label">Registered residents</span><span class="stat-card-icon"><i class="bi bi-people-fill" aria-hidden="true"></i></span></div>
                    <strong class="stat-value">0</strong>
                    <span class="stat-meta">No resident records yet</span>
                </article>
                <article class="stat-card info">
                    <div class="stat-card-top"><span class="stat-card-label">Households</span><span class="stat-card-icon"><i class="bi bi-house-door-fill" aria-hidden="true"></i></span></div>
                    <strong class="stat-value">0</strong>
                    <span class="stat-meta">No household records yet</span>
                </article>
                <article class="stat-card warning">
                    <div class="stat-card-top"><span class="stat-card-label">Priority residents</span><span class="stat-card-icon"><i class="bi bi-heart-pulse-fill" aria-hidden="true"></i></span></div>
                    <strong class="stat-value">0</strong>
                    <span class="stat-meta">No priority records yet</span>
                </article>
                <article class="stat-card">
                    <div class="stat-card-top"><span class="stat-card-label">Records verified</span><span class="stat-card-icon"><i class="bi bi-patch-check-fill" aria-hidden="true"></i></span></div>
                    <strong class="stat-value">0%</strong>
                    <span class="stat-meta">No verified records yet</span>
                </article>
            </section>

            <section aria-labelledby="residentDirectoryHeading">
                <div class="filter-toolbar">
                    <div class="search-field">
                        <label for="residentSearch">Search residents</label>
                        <div class="input-icon">
                            <i class="bi bi-search" aria-hidden="true"></i>
                            <input class="form-control" id="residentSearch" type="search" placeholder="Name, household ID or address" autocomplete="off" data-table-search="#residentsTable">
                        </div>
                    </div>
                    <div class="filter-field">
                        <label for="residentStatusFilter">Record status</label>
                        <select class="form-select" id="residentStatusFilter" data-filter-select="#residentsTable" data-filter-field="status">
                            <option value="">All statuses</option>
                            <option value="Active">Active</option>
                            <option value="Inactive">Inactive</option>
                        </select>
                    </div>
                    <span class="filter-results" aria-live="polite">Showing 0 records</span>
                </div>

                <div class="data-card">
                    <div class="data-card-header">
                        <div>
                            <h2 id="residentDirectoryHeading">Resident directory</h2>
                            <p>Household and vulnerability information used during emergency operations.</p>
                        </div>
                        <span class="status-badge status-success">Data current</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table app-table align-middle" id="residentsTable">
                            <caption class="visually-hidden">Barangay Binloc resident directory</caption>
                            <thead>
                                <tr>
                                    <th scope="col">Resident</th>
                                    <th scope="col">Household</th>
                                    <th scope="col">Address</th>
                                    <th scope="col">Age</th>
                                    <th scope="col">Contact</th>
                                    <th scope="col">Priority group</th>
                                    <th scope="col">Status</th>
                                    <th scope="col" class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($residents as $resident): ?>
                                    <tr data-row data-status="<?= htmlspecialchars($resident[8], ENT_QUOTES, 'UTF-8') ?>">
                                        <td>
                                            <span class="table-avatar" aria-hidden="true"><?= htmlspecialchars($resident[2], ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="d-inline-block align-middle">
                                                <span class="table-primary-text"><?= htmlspecialchars($resident[1], ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="table-secondary-text"><?= htmlspecialchars($resident[0], ENT_QUOTES, 'UTF-8') ?></span>
                                            </span>
                                        </td>
                                        <td><span class="table-primary-text"><?= htmlspecialchars($resident[3], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><?= htmlspecialchars($resident[4], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($resident[5], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= htmlspecialchars($resident[6], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="status-badge <?= $resident[7] === 'None' ? 'status-neutral' : 'status-warning' ?>"><?= htmlspecialchars($resident[7], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td><span class="status-badge <?= $resident[8] === 'Active' ? 'status-success' : 'status-neutral' ?>"><?= htmlspecialchars($resident[8], ENT_QUOTES, 'UTF-8') ?></span></td>
                                        <td class="text-end">
                                            <div class="table-actions" role="group" aria-label="Actions for <?= htmlspecialchars($resident[1], ENT_QUOTES, 'UTF-8') ?>">
                                                <button class="btn btn-light btn-icon" type="button" title="View resident" aria-label="View <?= htmlspecialchars($resident[1], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#viewResidentModal"><i class="bi bi-eye" aria-hidden="true"></i></button>
                                                <button class="btn btn-light btn-icon" type="button" title="Edit resident" aria-label="Edit <?= htmlspecialchars($resident[1], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#editResidentModal"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>
                    </div>
                    <div class="data-card-footer record-summary">
                        <span>Showing 0 of 0 residents</span>
                        <nav aria-label="Resident table pages">
                            <ul class="pagination">
                                <li class="page-item disabled"><button class="page-link" type="button" disabled aria-label="Previous page"><i class="bi bi-chevron-left" aria-hidden="true"></i></button></li>
                                <li class="page-item active"><button class="page-link" type="button" aria-current="page">1</button></li>
                                <li class="page-item"><button class="page-link" type="button" aria-label="Page 2">2</button></li>
                                <li class="page-item"><button class="page-link" type="button" aria-label="Next page"><i class="bi bi-chevron-right" aria-hidden="true"></i></button></li>
                            </ul>
                        </nav>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal fade" id="addResidentModal" tabindex="-1" aria-labelledby="addResidentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="addResidentForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-header">
                    <div><h2 class="modal-title" id="addResidentModalLabel">Add resident</h2><p class="mb-0 mt-1 small text-body-secondary">Create a resident and household profile for relief planning.</p></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="residentFirstName">First name <span class="required-mark">*</span></label><input class="form-control" id="residentFirstName" name="first_name" required autocomplete="given-name"></div>
                        <div class="col-md-6"><label class="form-label" for="residentLastName">Last name <span class="required-mark">*</span></label><input class="form-control" id="residentLastName" name="last_name" required autocomplete="family-name"></div>
                        <div class="col-md-4"><label class="form-label" for="residentBirthDate">Birth date</label><input class="form-control" id="residentBirthDate" name="birth_date" type="date"></div>
                        <div class="col-md-4"><label class="form-label" for="residentSex">Sex</label><select class="form-select" id="residentSex" name="sex"><option>Female</option><option>Male</option><option>Other</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="residentContact">Contact number</label><input class="form-control" id="residentContact" name="contact" type="tel" autocomplete="tel" placeholder="09XX XXX XXXX"></div>
                        <div class="col-md-6"><label class="form-label" for="residentHousehold">Household ID</label><input class="form-control" id="residentHousehold" name="household_id" placeholder="Enter an existing household number"></div>
                        <div class="col-md-6"><label class="form-label" for="residentPriority">Priority group</label><select class="form-select" id="residentPriority" name="priority_group"><option>None</option><option>Senior citizen</option><option>PWD</option><option>Pregnant</option><option>Solo parent</option><option>Child under five</option></select></div>
                        <div class="col-12"><label class="form-label" for="residentAddress">Complete address <span class="required-mark">*</span></label><textarea class="form-control" id="residentAddress" name="address" rows="3" required autocomplete="street-address"></textarea></div>
                    </div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-brand"><i class="bi bi-check-lg" aria-hidden="true"></i> Save resident</button></div>
            </form>
        </div>
    </div>
</div>

<script>
    window.sagipbroResidentApi = {
        endpoint: <?= json_encode(appUrl('api/residents.php')) ?>,
        csrfToken: <?= json_encode(csrfToken()) ?>
    };
</script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/residents-api.js"></script>

<div class="modal fade" id="viewResidentModal" tabindex="-1" aria-labelledby="viewResidentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h2 class="modal-title" id="viewResidentModalLabel">Resident profile</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><div class="empty-state py-4"><i class="bi bi-person-vcard"></i><h3>No resident selected</h3><p>Resident details are loaded from the database.</p></div></div>
            <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button><button class="btn btn-brand" type="button" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editResidentModal"><i class="bi bi-pencil" aria-hidden="true"></i> Edit record</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="editResidentModal" tabindex="-1" aria-labelledby="editResidentModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="editResidentForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <div class="modal-header"><h2 class="modal-title" id="editResidentModalLabel">Edit resident record</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
                <div class="modal-body">
                    <p class="modal-intro">Update the selected resident's basic contact and classification information.</p>
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label" for="editResidentFirstName">First name</label><input class="form-control" id="editResidentFirstName" name="first_name" required></div>
                        <div class="col-md-6"><label class="form-label" for="editResidentLastName">Last name</label><input class="form-control" id="editResidentLastName" name="last_name" required></div>
                        <div class="col-md-4"><label class="form-label" for="editResidentBirthDate">Birth date</label><input class="form-control" id="editResidentBirthDate" name="birth_date" type="date"></div>
                        <div class="col-md-4"><label class="form-label" for="editResidentSex">Sex</label><select class="form-select" id="editResidentSex" name="sex"><option>Female</option><option>Male</option><option>Other</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="editResidentContact">Contact number</label><input class="form-control" id="editResidentContact" name="contact" type="tel"></div>
                        <div class="col-md-6"><label class="form-label" for="editResidentHousehold">Household ID</label><input class="form-control" id="editResidentHousehold" name="household_id"></div>
                        <div class="col-md-6"><label class="form-label" for="editResidentPriority">Priority group</label><select class="form-select" id="editResidentPriority" name="priority_group"><option>None</option><option>Senior citizen</option><option>PWD</option><option>Pregnant</option><option>Solo parent</option><option>Child under five</option></select></div>
                        <div class="col-12"><label class="form-label" for="editResidentAddress">Complete address</label><textarea class="form-control" id="editResidentAddress" name="address" rows="2" required></textarea></div>
                        <div class="col-12"><label class="form-label" for="editResidentStatus">Record status</label><select class="form-select" id="editResidentStatus" name="status"><option>Active</option><option>Inactive</option></select></div>
                    </div>
                </div>
                <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit">Save changes</button></div>
            </form>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
