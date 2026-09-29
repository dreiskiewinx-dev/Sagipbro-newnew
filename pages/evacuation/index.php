<?php
require_once '../../includes/auth_check.php';
requireRole(['admin', 'official']);

$pageTitle = 'Evacuation Centers';
$pageDescription = 'Manage evacuation facilities, capacity, occupancy, and local contacts in SAGIPBRO.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'evacuation';

$centers = [];

$statusClasses = [
    'Open' => 'status-success',
    'Near capacity' => 'status-warning',
    'Full' => 'status-danger',
    'Closed' => 'status-neutral',
];

include '../../includes/header.php';
?>

<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>

        <main id="main-content" class="admin-content">
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="<?= $basePath ?>dashboard/admin.php">Dashboard</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Evacuation centers</li>
                        </ol>
                    </nav>
                    <h1>Evacuation centers</h1>
                    <p>Keep facility readiness, occupancy, and responsible contact details up to date.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-outline-brand" type="button" data-print="#centersTable"><i class="bi bi-printer" aria-hidden="true"></i> Print occupancy</button>
                    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#addCenterModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add center</button>
                </div>
            </header>

            <section class="filter-toolbar" aria-label="Evacuation center table filters">
                <div class="search-field">
                    <label for="centerSearch">Search centers</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input class="form-control border-start-0" id="centerSearch" type="search" placeholder="Search center, ID, or location" autocomplete="off" data-table-search="#centersTable">
                    </div>
                </div>
                <div class="filter-field">
                    <label for="centerStatus">Facility status</label>
                    <select class="form-select" id="centerStatus" data-filter-select="#centersTable" data-filter-field="status">
                        <option value="">All statuses</option>
                        <option>Open</option>
                        <option>Near capacity</option>
                        <option>Full</option>
                        <option>Closed</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="centerArea">Service area</label>
                    <select class="form-select" id="centerArea" data-filter-select="#centersTable" data-filter-field="area">
                        <option value="">All areas</option>
                        <option>Bonuan Binloc</option>
                    </select>
                </div>
                <span class="filter-results" aria-live="polite" data-filter-results><?= count($centers) ?> registered centers</span>
            </section>

            <section class="data-card" aria-labelledby="centerDirectoryHeading">
                <div class="data-card-header">
                    <div>
                        <h2 id="centerDirectoryHeading">Center directory and live occupancy</h2>
                        <p>Occupancy totals are presentation data for this UI preview.</p>
                    </div>
                    <span class="status-badge status-danger">1 center at full capacity</span>
                </div>
                <div class="table-responsive">
                    <table class="table app-table align-middle" id="centersTable" data-table>
                        <thead>
                            <tr>
                                <th scope="col">Center</th>
                                <th scope="col">Location</th>
                                <th scope="col">Capacity / occupancy</th>
                                <th scope="col">Status</th>
                                <th scope="col">Contact person</th>
                                <th scope="col">Updated</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($centers as $center):
                                $occupancyPercent = min(100, (int) round(($center['occupants'] / max(1, $center['capacity'])) * 100));
                                $progressClass = $occupancyPercent >= 100 ? 'danger' : ($occupancyPercent >= 85 ? 'warning' : '');
                                $area = strpos($center['location'], 'Boquig') !== false ? 'Bonuan Boquig' : (strpos($center['location'], 'Gueset') !== false ? 'Bonuan Gueset' : 'Bonuan Binloc');
                            ?>
                                <tr data-row data-status="<?= htmlspecialchars($center['status'], ENT_QUOTES, 'UTF-8') ?>" data-area="<?= htmlspecialchars($area, ENT_QUOTES, 'UTF-8') ?>">
                                    <td><span class="table-primary-text"><?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($center['id'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><i class="bi bi-geo-alt text-success me-1" aria-hidden="true"></i><?= htmlspecialchars($center['location'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <div class="occupancy-cell">
                                            <div class="d-flex justify-content-between gap-2"><strong><?= number_format($center['occupants']) ?> / <?= number_format($center['capacity']) ?></strong><span class="text-muted"><?= $occupancyPercent ?>%</span></div>
                                            <div class="progress" role="progressbar" aria-label="Occupancy at <?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?>" aria-valuenow="<?= $occupancyPercent ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar <?= $progressClass ?>" style="width: <?= $occupancyPercent ?>%"></div></div>
                                            <span class="table-secondary-text"><?= number_format(max(0, $center['capacity'] - $center['occupants'])) ?> spaces available</span>
                                        </div>
                                    </td>
                                    <td><span class="status-badge <?= $statusClasses[$center['status']] ?>"><?= htmlspecialchars($center['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="table-primary-text"><?= htmlspecialchars($center['contact'], ENT_QUOTES, 'UTF-8') ?></span><a class="table-secondary-text text-decoration-none" href="tel:<?= preg_replace('/\D+/', '', $center['phone']) ?>"><?= htmlspecialchars($center['phone'], ENT_QUOTES, 'UTF-8') ?></a></td>
                                    <td><?= htmlspecialchars($center['updated'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <div class="table-actions" role="group" aria-label="Actions for <?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="btn btn-light btn-icon" type="button" title="View" aria-label="View <?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#viewCenterModal" data-record-id="<?= htmlspecialchars($center['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-eye" aria-hidden="true"></i></button>
                                            <button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit <?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#editCenterModal" data-record-id="<?= htmlspecialchars($center['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                            <button class="btn btn-light btn-icon text-danger" type="button" title="Remove" aria-label="Remove <?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#deleteCenterModal" data-record-id="<?= htmlspecialchars($center['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($center['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="data-card-footer record-summary"><span>Loading evacuation centers…</span><span>Live database records</span></div>
            </section>

            <div class="modal fade" id="addCenterModal" tabindex="-1" aria-labelledby="addCenterTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="addCenterForm">
                    <div class="modal-header"><h2 class="modal-title" id="addCenterTitle">Add evacuation center</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p class="modal-intro">Register a verified facility and its designated point of contact.</p><div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="addCenterName">Center name</label><input class="form-control" id="addCenterName" name="name" required></div>
                        <div class="col-md-4"><label class="form-label" for="addCenterStatus">Status</label><select class="form-select" id="addCenterStatus" name="status"><option>Open</option><option>Closed</option></select></div>
                        <div class="col-12"><label class="form-label" for="addCenterLocation">Complete location</label><input class="form-control" id="addCenterLocation" name="location" placeholder="Street or sitio, barangay, city" required></div>
                        <div class="col-md-8"><label class="form-label" for="addCenterCapacity">Maximum capacity</label><input class="form-control" id="addCenterCapacity" name="capacity" type="number" min="1" required></div>
                        <div class="col-md-4"><label class="form-label" for="addCenterOccupants">Current occupants</label><input class="form-control" id="addCenterOccupants" name="occupants" type="number" min="0" value="0" required></div>
                        <div class="col-md-8"><label class="form-label" for="addCenterContact">Contact person</label><input class="form-control" id="addCenterContact" name="contact_person" required></div>
                        <div class="col-md-4"><label class="form-label" for="addCenterPhone">Contact number</label><input class="form-control" id="addCenterPhone" name="contact_number" type="tel" placeholder="09XX XXX XXXX" required></div>
                        <div class="col-12"><label class="form-label" for="addCenterNotes">Facilities and accessibility notes</label><textarea class="form-control" id="addCenterNotes" name="notes" rows="3" placeholder="Water access, accessible entrance, medical area, generator, or restrictions"></textarea></div>
                    </div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-plus-lg"></i> Add center</button></div>
                </form></div>
            </div>

            <div class="modal fade" id="viewCenterModal" tabindex="-1" aria-labelledby="viewCenterTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header"><h2 class="modal-title" id="viewCenterTitle">Center details</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><div class="empty-state py-4"><i class="bi bi-buildings"></i><h3>No center selected</h3><p>Center details are loaded from the database.</p></div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button><button class="btn btn-outline-brand" type="button" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editCenterModal"><i class="bi bi-pencil"></i> Edit center</button></div>
                </div></div>
            </div>

            <div class="modal fade" id="editCenterModal" tabindex="-1" aria-labelledby="editCenterTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><form class="modal-content" id="editCenterForm">
                    <div class="modal-header"><h2 class="modal-title" id="editCenterTitle">Edit evacuation center</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p class="modal-intro">Update occupancy only after confirming the latest registration count.</p><div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="editCenterName">Center name</label><input class="form-control" id="editCenterName" name="name" required></div>
                        <div class="col-md-4"><label class="form-label" for="editCenterStatus">Status</label><select class="form-select" id="editCenterStatus" name="status"><option>Open</option><option>Closed</option></select></div>
                        <div class="col-12"><label class="form-label" for="editCenterLocation">Complete location</label><input class="form-control" id="editCenterLocation" name="location" required></div>
                        <div class="col-md-8"><label class="form-label" for="editCenterCapacity">Maximum capacity</label><input class="form-control" id="editCenterCapacity" name="capacity" type="number" min="1" required></div>
                        <div class="col-md-4"><label class="form-label" for="editCenterOccupants">Current occupants</label><input class="form-control" id="editCenterOccupants" name="occupants" type="number" min="0" required></div>
                        <div class="col-md-8"><label class="form-label" for="editCenterContact">Contact person</label><input class="form-control" id="editCenterContact" name="contact_person" required></div>
                        <div class="col-md-4"><label class="form-label" for="editCenterPhone">Contact number</label><input class="form-control" id="editCenterPhone" name="contact_number" type="tel" required></div>
                        <div class="col-12"><label class="form-label" for="editCenterNotes">Facilities and accessibility notes</label><textarea class="form-control" id="editCenterNotes" name="notes" rows="3"></textarea></div>
                    </div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-check-lg"></i> Save changes</button></div>
                </form></div>
            </div>

            <div class="modal fade" id="deleteCenterModal" tabindex="-1" aria-labelledby="deleteCenterTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="deleteCenterForm">
                    <div class="modal-header"><h2 class="modal-title" id="deleteCenterTitle">Remove evacuation center?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p class="mb-2">Remove the selected center from the active directory?</p><div class="alert alert-warning small mb-0" role="alert"><i class="bi bi-exclamation-triangle me-1"></i> Centers with current occupants should be closed and cleared before removal.</div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Keep center</button><button class="btn btn-danger" type="submit" data-confirm-action="Evacuation center removed."><i class="bi bi-trash3"></i> Remove center</button></div>
                </form></div></div>
            </div>
        </main>
    </div>
</div>

<script>
    window.sagipbroCenterApi = { endpoint: <?= json_encode(appUrl('api/centers.php')) ?>, csrfToken: <?= json_encode(csrfToken()) ?> };
</script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/centers-api.js"></script>

<?php include '../../includes/footer.php'; ?>
