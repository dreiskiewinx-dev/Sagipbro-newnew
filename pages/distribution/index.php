<?php
require_once '../../includes/auth_check.php';
requireRole(['admin', 'official', 'volunteer']);

$pageTitle = 'Relief Distributions';
$pageDescription = 'Record and review accountable relief distributions for Barangay Binloc residents.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'distributions';

$distributions = [];

$statusClasses = ['Completed' => 'status-success', 'Pending review' => 'status-warning'];

include '../../includes/header.php';
?>

<div class="admin-shell">
    <?php include '../../includes/sidebar.php'; ?>
    <div class="admin-main">
        <?php include '../../includes/navbar.php'; ?>

        <main id="main-content" class="admin-content">
            <header class="page-header">
                <div>
                    <nav aria-label="Breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= $basePath ?>dashboard/admin.php">Dashboard</a></li><li class="breadcrumb-item active" aria-current="page">Distributions</li></ol></nav>
                    <h1>Relief distributions</h1>
                    <p>Maintain a traceable record of every resource released, recipient served, and distribution point.</p>
                </div>
                <div class="page-actions">
                    <a class="btn btn-outline-brand" href="<?= htmlspecialchars(appUrl('api/distribution.php?export=csv'), ENT_QUOTES, 'UTF-8') ?>" download><i class="bi bi-file-earmark-arrow-down" aria-hidden="true"></i> Export register</a>
                    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#addDistributionModal"><i class="bi bi-plus-lg" aria-hidden="true"></i> Record distribution</button>
                </div>
            </header>

            <section class="filter-toolbar" aria-label="Distribution table filters">
                <div class="search-field">
                    <label for="distributionSearch">Search distributions</label>
                    <div class="input-group"><span class="input-group-text bg-white border-end-0"><i class="bi bi-search" aria-hidden="true"></i></span><input class="form-control border-start-0" id="distributionSearch" type="search" placeholder="Search recipient, resource, or reference" autocomplete="off" data-table-search="#distributionsTable"></div>
                </div>
                <div class="filter-field">
                    <label for="distributionCategory">Resource category</label>
                    <select class="form-select" id="distributionCategory" data-filter-select="#distributionsTable" data-filter-field="category"><option value="">All categories</option><option>Food packs</option><option>Water &amp; hydration</option><option>Hygiene</option><option>Shelter</option><option>Medical</option><option>Special needs</option></select>
                </div>
                <div class="filter-field">
                    <label for="distributionStatus">Verification</label>
                    <select class="form-select" id="distributionStatus" data-filter-select="#distributionsTable" data-filter-field="status"><option value="">All records</option><option>Completed</option><option>Pending review</option></select>
                </div>
                <div class="filter-field">
                    <label for="distributionDate">Date range</label>
                    <select class="form-select" id="distributionDate" data-filter-select="#distributionsTable" data-filter-field="period"><option value="">All dates</option><option value="today">Today</option><option value="previous">Previous days</option></select>
                </div>
                <span class="filter-results" aria-live="polite" data-filter-results><?= count($distributions) ?> distribution records</span>
            </section>

            <section class="data-card" aria-labelledby="distributionRegisterHeading">
                <div class="data-card-header">
                    <div><h2 id="distributionRegisterHeading">Distribution register</h2><p>Latest releases appear first. Pending records require an official's review.</p></div>
                    <span class="status-badge status-warning">2 awaiting review</span>
                </div>
                <div class="table-responsive">
                    <table class="table app-table align-middle" id="distributionsTable" data-table>
                        <thead><tr><th scope="col">Reference / resource</th><th scope="col">Recipient</th><th scope="col">Quantity</th><th scope="col">Location</th><th scope="col">Date</th><th scope="col">Distributed by</th><th scope="col">Status</th><th scope="col" class="text-end" data-export-ignore>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($distributions as $distribution): ?>
                                <tr data-row data-category="<?= htmlspecialchars($distribution['category'], ENT_QUOTES, 'UTF-8') ?>" data-status="<?= htmlspecialchars($distribution['status'], ENT_QUOTES, 'UTF-8') ?>" data-period="<?= $distribution['date'] === 'Sep 10, 2026' ? 'today' : 'previous' ?>">
                                    <td><span class="table-primary-text"><?= htmlspecialchars($distribution['resource'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($distribution['id'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="table-primary-text"><?= htmlspecialchars($distribution['recipient'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($distribution['recipient_id'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><strong><?= htmlspecialchars($distribution['quantity'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                                    <td><i class="bi bi-geo-alt text-success me-1" aria-hidden="true"></i><?= htmlspecialchars($distribution['location'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="table-primary-text"><?= htmlspecialchars($distribution['date'], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars($distribution['time'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($distribution['by'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><span class="status-badge <?= $statusClasses[$distribution['status']] ?>"><?= htmlspecialchars($distribution['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td class="text-end"><div class="table-actions" role="group" aria-label="Actions for <?= htmlspecialchars($distribution['id'], ENT_QUOTES, 'UTF-8') ?>">
                                        <button class="btn btn-light btn-icon" type="button" title="View" aria-label="View <?= htmlspecialchars($distribution['id'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#viewDistributionModal" data-record-id="<?= htmlspecialchars($distribution['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($distribution['recipient'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-eye" aria-hidden="true"></i></button>
                                        <button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit <?= htmlspecialchars($distribution['id'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#editDistributionModal" data-record-id="<?= htmlspecialchars($distribution['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($distribution['recipient'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                        <button class="btn btn-light btn-icon text-danger" type="button" title="Reverse" aria-label="Reverse <?= htmlspecialchars($distribution['id'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#deleteDistributionModal" data-record-id="<?= htmlspecialchars($distribution['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($distribution['recipient'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    </div></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="data-card-footer record-summary"><span>Loading distribution records…</span><span>Live database records</span></div>
            </section>

            <div class="modal fade" id="addDistributionModal" tabindex="-1" aria-labelledby="addDistributionTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><form id="addDistributionForm">
                    <div class="modal-header"><h2 class="modal-title" id="addDistributionTitle">Record relief distribution</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p class="modal-intro">Confirm recipient details and available stock before releasing supplies.</p><div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="addDistributionResource">Resource</label><select class="form-select" id="addDistributionResource" name="resource" required><option value="">Loading available resources…</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="addDistributionQuantity">Quantity</label><input class="form-control" id="addDistributionQuantity" name="quantity" type="number" min="1" required></div>
                        <div class="col-md-8"><label class="form-label" for="addDistributionRecipient">Recipient or household</label><input class="form-control" id="addDistributionRecipient" name="recipient" placeholder="Search resident, household, or response unit" required></div>
                        <div class="col-md-4"><label class="form-label" for="addDistributionRecipientId">Resident / household ID</label><input class="form-control" id="addDistributionRecipientId" name="recipient_id" placeholder="Optional reference"></div>
                        <div class="col-md-7"><label class="form-label" for="addDistributionLocation">Distribution location</label><input class="form-control" id="addDistributionLocation" name="location" required></div>
                        <div class="col-md-5"><label class="form-label" for="addDistributionDate">Date and time</label><input class="form-control" id="addDistributionDate" name="distributed_at" type="datetime-local" value="<?= htmlspecialchars(date('Y-m-d\TH:i'), ENT_QUOTES, 'UTF-8') ?>" required></div>
                        <div class="col-md-7"><label class="form-label" for="addDistributedBy">Distributed by</label><input class="form-control" id="addDistributedBy" name="distributed_by" value="<?= htmlspecialchars((string) ($_SESSION['full_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly></div>
                        <div class="col-md-5"><label class="form-label" for="addDistributionStatus">Verification status</label><select class="form-select" id="addDistributionStatus" name="status"><option>Completed</option><option>Pending review</option></select></div>
                        <div class="col-12"><label class="form-label" for="addDistributionNotes">Notes</label><textarea class="form-control" id="addDistributionNotes" name="notes" rows="3" placeholder="Household circumstances, authorization, or release notes"></textarea></div>
                    </div><div class="info-callout mt-3"><i class="bi bi-box-seam"></i><div><strong>Stock control</strong><span>Submitting this record will deduct the issued quantity from available stock in a connected build.</span></div></div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-check2-circle"></i> Record distribution</button></div>
                </form></div></div>
            </div>

            <div class="modal fade" id="viewDistributionModal" tabindex="-1" aria-labelledby="viewDistributionTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
                    <div class="modal-header"><h2 class="modal-title" id="viewDistributionTitle">Distribution details</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><div class="empty-state py-4"><i class="bi bi-box-arrow-right"></i><h3>No distribution selected</h3><p>Distribution details are loaded from the database.</p></div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button><button class="btn btn-outline-brand" type="button" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editDistributionModal"><i class="bi bi-pencil"></i> Edit record</button></div>
                </div></div>
            </div>

            <div class="modal fade" id="editDistributionModal" tabindex="-1" aria-labelledby="editDistributionTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content"><form id="editDistributionForm">
                    <div class="modal-header"><h2 class="modal-title" id="editDistributionTitle">Edit distribution</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p class="modal-intro">Changes to quantity should be reconciled against inventory and the activity log.</p><div class="row g-3">
                        <div class="col-md-8"><label class="form-label" for="editDistributionResource">Resource</label><select class="form-select" id="editDistributionResource" name="resource"><option value="">Loading resources…</option></select></div>
                        <div class="col-md-4"><label class="form-label" for="editDistributionQuantity">Quantity</label><input class="form-control" id="editDistributionQuantity" name="quantity" type="number" min="1" required></div>
                        <div class="col-md-8"><label class="form-label" for="editDistributionRecipient">Recipient</label><input class="form-control" id="editDistributionRecipient" name="recipient" required></div>
                        <div class="col-md-4"><label class="form-label" for="editDistributionRecipientId">Household ID</label><input class="form-control" id="editDistributionRecipientId" name="recipient_id"></div>
                        <div class="col-md-7"><label class="form-label" for="editDistributionLocation">Location</label><input class="form-control" id="editDistributionLocation" name="location" required></div>
                        <div class="col-md-5"><label class="form-label" for="editDistributionDate">Date and time</label><input class="form-control" id="editDistributionDate" name="distributed_at" type="datetime-local" required></div>
                        <div class="col-md-7"><label class="form-label" for="editDistributedBy">Distributed by</label><input class="form-control" id="editDistributedBy" name="distributed_by" readonly></div>
                        <div class="col-md-5"><label class="form-label" for="editDistributionStatus">Verification</label><select class="form-select" id="editDistributionStatus" name="status"><option>Completed</option><option>Pending review</option></select></div>
                        <div class="col-12"><label class="form-label" for="editDistributionNotes">Notes</label><textarea class="form-control" id="editDistributionNotes" name="notes" rows="3">Four-person household; priority lane.</textarea></div>
                    </div></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-check-lg"></i> Save changes</button></div>
                </form></div></div>
            </div>

            <div class="modal fade" id="deleteDistributionModal" tabindex="-1" aria-labelledby="deleteDistributionTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form id="deleteDistributionForm">
                    <div class="modal-header"><h2 class="modal-title" id="deleteDistributionTitle">Reverse distribution?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                    <div class="modal-body"><p>Reverse the selected distribution and return its quantity to available stock?</p><label class="form-label" for="reverseReason">Reason for reversal</label><textarea class="form-control" id="reverseReason" name="reason" rows="3" placeholder="Required for the audit trail" required></textarea></div>
                    <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Keep record</button><button class="btn btn-danger" type="submit" data-confirm-action="Distribution reversed."><i class="bi bi-arrow-counterclockwise"></i> Reverse record</button></div>
                </form></div></div>
            </div>
        </main>
    </div>
</div>

<script>
    window.sagipbroDistributionApi = { endpoint: <?= json_encode(appUrl('api/distribution.php')) ?>, resourcesEndpoint: <?= json_encode(appUrl('api/resources.php')) ?>, csrfToken: <?= json_encode(csrfToken()) ?> };
</script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/distribution-api.js"></script>

<?php include '../../includes/footer.php'; ?>
