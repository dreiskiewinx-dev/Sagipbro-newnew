<?php
require_once '../../includes/auth_check.php';
requireRole(['admin', 'official']);

$pageTitle = 'Resource Management';
$pageDescription = 'Track available relief supplies, stock levels, and replenishment needs in SAGIPBRO.';
$basePath = '../../';
$isAdmin = true;
$activeAdmin = 'resources';

$resources = [];

$statusClasses = [
    'In stock' => 'status-success',
    'Low stock' => 'status-warning',
    'Out of stock' => 'status-danger',
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
                            <li class="breadcrumb-item active" aria-current="page">Resources</li>
                        </ol>
                    </nav>
                    <h1>Resource management</h1>
                    <p>Monitor relief inventory, storage locations, and items that require immediate replenishment.</p>
                </div>
                <div class="page-actions">
                    <button class="btn btn-outline-brand" type="button" data-confirm-action="Resource inventory prepared for export." aria-label="Export resource inventory">
                        <i class="bi bi-download" aria-hidden="true"></i> Export list
                    </button>
                    <button class="btn btn-brand" type="button" data-bs-toggle="modal" data-bs-target="#addResourceModal">
                        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add resource
                    </button>
                </div>
            </header>

            <section class="filter-toolbar" aria-label="Resource table filters">
                <div class="search-field">
                    <label for="resourceSearch">Search resources</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search" aria-hidden="true"></i></span>
                        <input class="form-control border-start-0" id="resourceSearch" type="search" placeholder="Search name, ID, or location" autocomplete="off" data-table-search="#resourcesTable">
                    </div>
                </div>
                <div class="filter-field">
                    <label for="resourceCategory">Category</label>
                    <select class="form-select" id="resourceCategory" data-filter-select="#resourcesTable" data-filter-field="category">
                        <option value="">All categories</option>
                        <option>Food packs</option>
                        <option>Water &amp; hydration</option>
                        <option>Hygiene</option>
                        <option>Shelter</option>
                        <option>Special needs</option>
                        <option>Medical</option>
                    </select>
                </div>
                <div class="filter-field">
                    <label for="resourceStatus">Stock status</label>
                    <select class="form-select" id="resourceStatus" data-filter-select="#resourcesTable" data-filter-field="status">
                        <option value="">All statuses</option>
                        <option>In stock</option>
                        <option>Low stock</option>
                        <option>Out of stock</option>
                    </select>
                </div>
                <span class="filter-results" aria-live="polite" data-filter-results><?= count($resources) ?> resource records</span>
            </section>

            <section class="data-card" aria-labelledby="resourceInventoryHeading">
                <div class="data-card-header">
                    <div>
                        <h2 id="resourceInventoryHeading">Relief inventory</h2>
                        <p>Quantities reflect the latest recorded physical count.</p>
                    </div>
                    <span class="status-badge status-warning"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> 3 need attention</span>
                </div>
                <div class="table-responsive">
                    <table class="table app-table align-middle" id="resourcesTable" data-table>
                        <thead>
                            <tr>
                                <th scope="col">Resource</th>
                                <th scope="col">Category</th>
                                <th scope="col">Available quantity</th>
                                <th scope="col">Storage location</th>
                                <th scope="col">Status</th>
                                <th scope="col">Last updated</th>
                                <th scope="col" class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($resources as $resource):
                                $stockPercent = min(100, (int) round(($resource['stock'] / max(1, $resource['threshold'] * 2)) * 100));
                                $barClass = $resource['status'] === 'Out of stock' ? 'danger' : ($resource['status'] === 'Low stock' ? 'warning' : '');
                            ?>
                                <tr data-row data-category="<?= htmlspecialchars($resource['category'], ENT_QUOTES, 'UTF-8') ?>" data-status="<?= htmlspecialchars($resource['status'], ENT_QUOTES, 'UTF-8') ?>">
                                    <td>
                                        <span class="table-primary-text"><?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <span class="table-secondary-text"><?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </td>
                                    <td><?= htmlspecialchars($resource['category'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <div class="stock-cell">
                                            <div class="stock-cell-top"><strong><?= number_format($resource['stock']) ?> <?= htmlspecialchars($resource['unit'], ENT_QUOTES, 'UTF-8') ?></strong><span>Min. <?= number_format($resource['threshold']) ?></span></div>
                                            <div class="progress" role="progressbar" aria-label="Stock level for <?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>" aria-valuenow="<?= $stockPercent ?>" aria-valuemin="0" aria-valuemax="100">
                                                <div class="progress-bar <?= $barClass ?>" style="width: <?= $stockPercent ?>%"></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><span class="table-primary-text"><?= htmlspecialchars(explode(' · ', $resource['location'])[0], ENT_QUOTES, 'UTF-8') ?></span><span class="table-secondary-text"><?= htmlspecialchars(explode(' · ', $resource['location'])[1] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><span class="status-badge <?= $statusClasses[$resource['status']] ?>"><?= htmlspecialchars($resource['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                    <td><?= htmlspecialchars($resource['updated'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <div class="table-actions" role="group" aria-label="Actions for <?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button class="btn btn-light btn-icon" type="button" title="View" aria-label="View <?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#viewResourceModal" data-record-id="<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-eye" aria-hidden="true"></i></button>
                                            <button class="btn btn-light btn-icon" type="button" title="Edit" aria-label="Edit <?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#editResourceModal" data-record-id="<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-pencil" aria-hidden="true"></i></button>
                                            <button class="btn btn-light btn-icon text-danger" type="button" title="Archive" aria-label="Archive <?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>" data-bs-toggle="modal" data-bs-target="#deleteResourceModal" data-record-id="<?= htmlspecialchars($resource['id'], ENT_QUOTES, 'UTF-8') ?>" data-record-name="<?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>"><i class="bi bi-archive" aria-hidden="true"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="data-card-footer record-summary"><span>Loading resources…</span><span>Live database records</span></div>
            </section>

            <div class="modal fade" id="addResourceModal" tabindex="-1" aria-labelledby="addResourceTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form id="addResourceForm">
                            <div class="modal-header"><h2 class="modal-title" id="addResourceTitle">Add relief resource</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                            <div class="modal-body">
                                <p class="modal-intro">Create an inventory record and define when the system should flag low stock.</p>
                                <div class="row g-3">
                                    <div class="col-md-7"><label class="form-label" for="addResourceName">Resource name</label><input class="form-control" id="addResourceName" name="name" required></div>
                                    <div class="col-md-5"><label class="form-label" for="addResourceCategory">Category</label><select class="form-select" id="addResourceCategory" name="category" required><option value="" selected disabled>Select category</option><option>Food packs</option><option>Water &amp; hydration</option><option>Hygiene</option><option>Shelter</option><option>Medical</option><option>Special needs</option></select></div>
                                    <div class="col-sm-4"><label class="form-label" for="addResourceQuantity">Opening quantity</label><input class="form-control" id="addResourceQuantity" name="stock" type="number" min="0" value="0" required></div>
                                    <div class="col-sm-4"><label class="form-label" for="addResourceUnit">Unit</label><input class="form-control" id="addResourceUnit" name="unit" placeholder="packs, cases, pieces" required></div>
                                    <div class="col-sm-4"><label class="form-label" for="addResourceThreshold">Low-stock threshold</label><input class="form-control" id="addResourceThreshold" name="low_stock_threshold" type="number" min="0" value="10" required></div>
                                    <div class="col-md-8"><label class="form-label" for="addResourceLocation">Storage location</label><input class="form-control" id="addResourceLocation" name="location" placeholder="Building, room, rack or cabinet" required></div>
                                    <div class="col-md-4"><label class="form-label" for="addResourceStatus">Record status</label><select class="form-select" id="addResourceStatus" name="status"><option>Available</option><option>Inactive</option></select></div>
                                    <div class="col-12"><label class="form-label" for="addResourceNotes">Inventory notes</label><textarea class="form-control" id="addResourceNotes" name="notes" rows="3" placeholder="Source, expiry information, or handling instructions"></textarea></div>
                                </div>
                            </div>
                            <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-plus-lg"></i> Add resource</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="viewResourceModal" tabindex="-1" aria-labelledby="viewResourceTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header"><h2 class="modal-title" id="viewResourceTitle">Resource details</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                        <div class="modal-body"><div class="empty-state py-4"><i class="bi bi-box-seam"></i><h3>No resource selected</h3><p>Resource details are loaded from the database.</p></div></div>
                        <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Close</button><button class="btn btn-outline-brand" type="button" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#editResourceModal"><i class="bi bi-pencil"></i> Edit record</button></div>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="editResourceModal" tabindex="-1" aria-labelledby="editResourceTitle" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered">
                    <div class="modal-content">
                        <form id="editResourceForm">
                            <div class="modal-header"><h2 class="modal-title" id="editResourceTitle">Edit resource</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                            <div class="modal-body">
                                <p class="modal-intro">Update resource details and current physical stock.</p>
                                <div class="row g-3">
                                    <div class="col-md-7"><label class="form-label" for="editResourceName">Resource name</label><input class="form-control" id="editResourceName" name="name" required></div>
                                    <div class="col-md-5"><label class="form-label" for="editResourceCategory">Category</label><select class="form-select" id="editResourceCategory" name="category"><option>Food packs</option><option>Water &amp; hydration</option><option>Hygiene</option><option>Shelter</option><option>Medical</option><option>Special needs</option></select></div>
                                    <div class="col-sm-4"><label class="form-label" for="editResourceQuantity">Available quantity</label><input class="form-control" id="editResourceQuantity" name="stock" type="number" min="0" required></div>
                                    <div class="col-sm-4"><label class="form-label" for="editResourceUnit">Unit</label><input class="form-control" id="editResourceUnit" name="unit" required></div>
                                    <div class="col-sm-4"><label class="form-label" for="editResourceThreshold">Low-stock threshold</label><input class="form-control" id="editResourceThreshold" name="low_stock_threshold" type="number" min="0" required></div>
                                    <div class="col-12"><label class="form-label" for="editResourceLocation">Storage location</label><input class="form-control" id="editResourceLocation" name="location" required></div>
                                    <div class="col-12"><label class="form-label" for="editResourceNotes">Inventory notes</label><textarea class="form-control" id="editResourceNotes" name="notes" rows="3" placeholder="Source, expiry information, or handling instructions"></textarea></div>
                                    <div class="col-12"><label class="form-label" for="editResourceReason">Adjustment note</label><textarea class="form-control" id="editResourceReason" name="reason" rows="3" placeholder="Briefly explain this stock adjustment"></textarea></div>
                                </div>
                            </div>
                            <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-brand" type="submit"><i class="bi bi-check-lg"></i> Save changes</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="modal fade" id="deleteResourceModal" tabindex="-1" aria-labelledby="deleteResourceTitle" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <form id="deleteResourceForm">
                            <div class="modal-header"><h2 class="modal-title" id="deleteResourceTitle">Archive resource?</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>
                            <div class="modal-body"><p class="mb-2">Archive the selected resource from the active inventory?</p><p class="small text-muted mb-0">Historical distribution records will be preserved. You can reactivate this resource later.</p></div>
                            <div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Keep resource</button><button class="btn btn-danger" type="submit" data-confirm-action="Resource archived."><i class="bi bi-archive"></i> Archive resource</button></div>
                        </form>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
    window.sagipbroResourceApi = {
        endpoint: <?= json_encode(appUrl('api/resources.php')) ?>,
        csrfToken: <?= json_encode(csrfToken()) ?>
    };
</script>
<script src="<?= htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8') ?>assets/js/resources-api.js"></script>
<?php include '../../includes/footer.php'; ?>
